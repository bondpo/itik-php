<?php

// +----------------------------------------------------------------------
// | CMS API —— 专题
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 专题接口
 *
 * 路由：
 *   GET /api/cms/specials            专题列表
 *   GET /api/cms/specials/detail     专题详情（含关联内容）
 */
class Special extends Base
{
    protected $noNeedLogin = ['index', 'detail'];
    protected $noNeedRight = '*';

    /**
     * 专题列表
     */
    public function index()
    {
        [$page, $limit] = $this->cmsPageParams();
        $query = Db::name('cms_special')->where('status', 'normal');
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)
            ->field($this->fieldString('cms_special', ['id','title','image','banner','diyname','seotitle','keywords','description','intro','views','comments','iscomment','label','weigh','createtime']))
            ->select()->toArray();

        $this->success('', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * 专题详情
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $diyname = trim((string)$this->request->param('diyname', ''));
        if ($id <= 0 && $diyname === '') {
            $this->fail('缺少专题标识（id 或 diyname）', self::CODE_PARAM_MISSING);
        }

        $query = Db::name('cms_special')->where('status', 'normal');
        $special = $id > 0 ? $query->where('id', $id)->find() : $query->where('diyname', $diyname)->find();
        if (!$special) {
            $this->fail('专题不存在', self::CODE_NOT_FOUND);
        }

        // 专题与内容的关联通过 tag_ids（标签）实现，无独立关联表
        $tagIds = array_filter(array_map('intval', explode(',', (string)($special['tag_ids'] ?? ''))));
        $archives = [];
        if ($tagIds) {
            $aids = Db::name('cms_taggable')->whereIn('tag_id', $tagIds)->column('archives_id');
            if ($aids) {
                $archives = Db::name('cms_archives')
                    ->whereIn('id', array_unique(array_map('intval', $aids)))
                    ->where('status', 'normal')->whereNull('deletetime')
                    ->field($this->fieldString('cms_archives', ['id','channel_id','title','image','description','views','comments','publishtime']))
                    ->order('id', 'desc')
                    ->limit(100)->select()->toArray();
            }
        }
        // 专题关联的标签（供前端展示）
        $tags = $tagIds
            ? Db::name('cms_tag')->whereIn('id', $tagIds)->field($this->fieldString('cms_tag', ['id','name','nums','views']))->select()->toArray()
            : [];

        $this->success('', [
            'special'  => $this->pick($special, $this->onlyExisting('cms_special', [
                'id','title','image','banner','diyname','seotitle','keywords','description','intro','views','comments','label','weigh','createtime','updatetime',
            ])),
            'archives' => $archives,
            'tags'     => $tags,
        ]);
    }
}
