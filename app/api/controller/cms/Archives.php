<?php

// +----------------------------------------------------------------------
// | CMS API —— 内容详情
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 内容接口
 *
 * 路由：
 *   GET /api/cms/archives/detail     内容详情（含副表自定义字段、标签、上下篇、相关推荐）
 *   GET /api/cms/archives/relative   相关内容推荐
 *   POST /api/cms/archives/like      点赞
 *   POST /api/cms/archives/view      浏览量上报
 */
class Archives extends Base
{
    protected $noNeedLogin = ['detail', 'relative', 'view'];
    protected $noNeedRight = '*';

    protected const LIST_FIELDS = ['id','channel_id','model_id','title','image','images','description','tags','keywords','views','likes','dislikes','comments','price','weigh','publishtime'];

    /**
     * 内容详情
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        if ($id <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }

        $row = Db::name('cms_archives')->where('id', $id)->where('status', 'normal')->whereNull('deletetime')->find();
        if (!$row) {
            $this->fail('内容不存在或未发布', self::CODE_NOT_FOUND);
        }

        $channel = Db::name('cms_channel')->where('id', (int)$row['channel_id'])
            ->field('id,name,diyname,type,parent_id,model_id')->find();

        // 副表自定义字段值
        $model = Db::name('cms_model')->where('id', (int)$row['model_id'])->find();
        $custom = [];
        if ($model && !empty($model['table'])) {
            $addon = Db::name($model['table'])->where('id', $id)->find();
            if ($addon) {
                $fields = Db::name('cms_fields')
                    ->where('source', 'model')->where('source_id', (int)$row['model_id'])
                    ->where('status', 'normal')
                    ->field('name,title,type,content')->order('weigh', 'desc')->select()->toArray();
                foreach ($fields as $f) {
                    if (array_key_exists($f['name'], $addon)) {
                        $custom[] = [
                            'name'  => $f['name'],
                            'title' => $f['title'],
                            'type'  => $f['type'],
                            'value' => $addon[$f['name']],
                        ];
                    }
                }
            }
        }

        // 标签
        $tagIds = Db::name('cms_taggable')->where('archives_id', $id)->column('tag_id');
        $tags = $tagIds
            ? Db::name('cms_tag')->whereIn('id', $tagIds)->field($this->fieldString('cms_tag', ['id','name','nums','views','seotitle','keywords','description']))->select()->toArray()
            : [];

        // 上一篇 / 下一篇
        $prev = Db::name('cms_archives')->where('channel_id', (int)$row['channel_id'])
            ->where('status', 'normal')->whereNull('deletetime')->where('id', '<', $id)
            ->order('id', 'desc')->field('id,title')->find();
        $next = Db::name('cms_archives')->where('channel_id', (int)$row['channel_id'])
            ->where('status', 'normal')->whereNull('deletetime')->where('id', '>', $id)
            ->order('id', 'asc')->field('id,title')->find();

        // 内容字段白名单输出（剔除模板/内部字段）
        $content = $this->pick($row, $this->onlyExisting('cms_archives', [
            'id','channel_id','model_id','title','flag','image','images','seotitle','keywords',
            'description','content','tags','price','outlink','weigh','views','likes','dislikes','comments','diyname',
            'publishtime','createtime','updatetime','memo',
        ]));

        $this->success('', [
            'archives' => $content,
            'channel'  => $channel,
            'custom'   => $custom,
            'tags'     => $tags,
            'prev'     => $prev,
            'next'     => $next,
            'url'      => '/api/cms/archives/detail?id=' . $id,
        ]);
    }

    /**
     * 相关内容推荐（同栏目，按浏览量）
     */
    public function relative()
    {
        $id = (int)$this->request->param('id', 0);
        $limit = min(20, max(1, (int)$this->request->param('limit', 6)));
        if ($id <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }
        $row = Db::name('cms_archives')->where('id', $id)->field('id,channel_id')->find();
        if (!$row) {
            $this->fail('内容不存在', self::CODE_NOT_FOUND);
        }

        $list = Db::name('cms_archives')
            ->where('channel_id', (int)$row['channel_id'])
            ->where('status', 'normal')->whereNull('deletetime')
            ->where('id', '<>', $id)
            ->field($this->fieldString('cms_archives', self::LIST_FIELDS))
            ->order('views', 'desc')->limit($limit)->select()->toArray();

        $this->success('', ['list' => $list]);
    }

    /**
     * 点赞
     */
    public function like()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }
        $exists = Db::name('cms_archives')->where('id', $id)->where('status', 'normal')->find();
        if (!$exists) {
            $this->fail('内容不存在', self::CODE_NOT_FOUND);
        }
        Db::name('cms_archives')->where('id', $id)->inc('likes')->update();
        $likes = (int)Db::name('cms_archives')->where('id', $id)->value('likes');
        $this->success('点赞成功', ['likes' => $likes]);
    }

    /**
     * 浏览量上报（前台埋点调用，无需登录）
     */
    public function view()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }
        Db::name('cms_archives')->where('id', $id)->where('status', 'normal')->inc('views')->update();
        $views = (int)Db::name('cms_archives')->where('id', $id)->value('views');
        $this->success('', ['views' => $views]);
    }
}
