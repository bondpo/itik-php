<?php

// +----------------------------------------------------------------------
// | CMS API —— 栏目
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 栏目接口
 *
 * 路由：
 *   GET /api/cms/channels            栏目树（可用 ?type=list 过滤）
 *   GET /api/cms/channels/detail     栏目详情（含子栏目与所属模型字段）
 *   GET /api/cms/channels/archives   栏目内容列表（分页 + 筛选 + 排序）
 */
class Channel extends Base
{
    protected $noNeedLogin = ['index', 'detail', 'archives'];
    protected $noNeedRight = '*';

    /** 前台可见字段（不含模板字段；不存在的字段会被自动剔除） */
    protected const SHOW_FIELDS = [
        'id','type','parent_id','model_id','name','image','flag','seotitle','keywords','description',
        'diyname','outlink','linktype','linkid','items','weigh','pagesize','vip','listtype','iscontribute','isnav','status',
    ];

    /**
     * 栏目树
     */
    public function index()
    {
        $type = (string)$this->request->param('type', '');
        $parentId = (int)$this->request->param('parent_id', 0);
        $this->success('', ['list' => $this->channelTree($parentId, $type)]);
    }

    /**
     * 栏目详情
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $diyname = (string)$this->request->param('diyname', '');
        if ($id <= 0 && $diyname === '') {
            $this->fail('缺少栏目标识（id 或 diyname）', self::CODE_PARAM_MISSING);
        }

        $query = Db::name('cms_channel')->where('status', 'normal')->field($this->fieldString('cms_channel', self::SHOW_FIELDS));
        $channel = $id > 0
            ? $query->where('id', $id)->find()
            : $query->where('diyname', $diyname)->find();

        if (!$channel) {
            $this->fail('栏目不存在', self::CODE_NOT_FOUND);
        }

        // 面包屑
        $breadcrumb = $this->breadcrumb((int)$channel['id']);
        // 子栏目
        $children = $this->channelTree((int)$channel['id']);
        // 模型自定义字段（供前端渲染筛选/表单）
        $fields = Db::name('cms_fields')
            ->where('source', 'model')->where('source_id', (int)$channel['model_id'])
            ->where('status', 'normal')
            ->field($this->fieldString('cms_fields', ['id','name','title','type','content_list','defaultvalue','isrequired','isfilter','filter_list','filterlist','content','weigh']))
            ->order('weigh', 'desc')->select()->toArray();

        $this->success('', [
            'channel'    => $channel,
            'breadcrumb' => $breadcrumb,
            'children'   => $children,
            'fields'     => $fields,
        ]);
    }

    /**
     * 栏目内容列表（分页 + 排序 + 筛选）
     */
    public function archives()
    {
        $channelId = (int)$this->request->param('channel_id', 0);
        $diyname   = (string)$this->request->param('diyname', '');
        if ($channelId <= 0 && $diyname === '') {
            $this->fail('缺少栏目标识（channel_id 或 diyname）', self::CODE_PARAM_MISSING);
        }

        $channelQuery = Db::name('cms_channel')->where('status', 'normal')->field($this->fieldString('cms_channel', self::SHOW_FIELDS));
        $channel = $channelId > 0
            ? $channelQuery->where('id', $channelId)->find()
            : $channelQuery->where('diyname', $diyname)->find();
        if (!$channel) {
            $this->fail('栏目不存在', self::CODE_NOT_FOUND);
        }

        [$page, $limit] = $this->cmsPageParams();
        [$orderby, $orderway] = $this->cmsOrderParams();

        // 含子栏目（可通过 include_children=0 关闭）
        $includeChildren = (int)$this->request->param('include_children', 1) === 1;
        $channelIds = [$channel['id']];
        if ($includeChildren) {
            $channelIds = array_merge($channelIds, $this->descendantIds((int)$channel['id']));
        }

        $query = Db::name('cms_archives')
            ->whereIn('channel_id', $channelIds)
            ->where('status', 'normal')->whereNull('deletetime');

        // 关键词
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', "%{$keyword}%")->whereOr('description', 'like', "%{$keyword}%");
            });
        }
        // 标签
        $tag = trim((string)$this->request->param('tag', ''));
        if ($tag !== '') {
            $ids = Db::name('cms_tag')->whereLike('name', "%{$tag}%")->column('id');
            if ($ids) {
                $aids = Db::name('cms_taggable')->whereIn('tag_id', $ids)->column('archives_id');
                $aids ? $query->whereIn('id', array_unique($aids)) : $query->where('id', -1);
            } else {
                $query->where('id', -1);
            }
        }

        $total = (clone $query)->count();
        $list = $query->order($orderby, $orderway)->order('id', 'desc')
            ->page($page, $limit)
            ->field('id,channel_id,model_id,title,image,images,description,tags,views,likes,comments,price,weigh,publishtime')
            ->select()->toArray();

        $this->success('', [
            'channel' => $channel,
            'list'    => $this->translations()->rows('cms_archive', $this->decorateArchives($list)),
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
        ]);
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 面包屑（从当前栏目向上回溯）
     */
    protected function breadcrumb(int $channelId): array
    {
        $chain = [];
        $guard = 0;
        $current = $channelId;
        while ($current > 0 && $guard < 20) {
            $row = Db::name('cms_channel')->where('id', $current)
                ->field('id,parent_id,name,diyname')->find();
            if (!$row) {
                break;
            }
            array_unshift($chain, $row);
            $current = (int)$row['parent_id'];
            $guard++;
        }
        return $chain;
    }

    /**
     * 后代栏目 ID
     */
    protected function descendantIds(int $channelId): array
    {
        $ids = [];
        $stack = [$channelId];
        $guard = 0;
        while ($stack && $guard < 500) {
            $pid = array_pop($stack);
            $children = Db::name('cms_channel')->where('parent_id', $pid)->column('id');
            foreach ($children as $cid) {
                $ids[] = (int)$cid;
                $stack[] = (int)$cid;
            }
            $guard++;
        }
        return $ids;
    }

    /**
     * 内容列表补充栏目名与链接（前端可直接消费）
     */
    protected function decorateArchives(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $channelIds = array_unique(array_column($rows, 'channel_id'));
        $channels = Db::name('cms_channel')->whereIn('id', $channelIds)
            ->field('id,name,diyname')->select()->toArray();
        $channels = array_column($channels, null, 'id');

        foreach ($rows as $k => $r) {
            $cid = (int)$r['channel_id'];
            $rows[$k]['channel_name'] = $channels[$cid]['name'] ?? '';
            $rows[$k]['channel_diyname'] = $channels[$cid]['diyname'] ?? '';
            $rows[$k]['url'] = '/api/cms/archives/detail?id=' . (int)$r['id'];
            $rows[$k]['views'] = (int)$r['views'];
            $rows[$k]['comments'] = (int)$r['comments'];
        }
        return $rows;
    }
}
