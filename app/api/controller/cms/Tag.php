<?php

// +----------------------------------------------------------------------
// | CMS API —— 标签
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 标签接口
 *
 * 路由：
 *   GET /api/cms/tags             标签列表（?hot=1 热门，?keyword= 搜索）
 *   GET /api/cms/tags/archives    按标签取内容列表
 */
class Tag extends Base
{
    protected $noNeedLogin = ['index', 'archives'];
    protected $noNeedRight = '*';

    /**
     * 标签列表
     */
    public function index()
    {
        $limit = min(100, max(1, (int)$this->request->param('limit', 50)));
        $query = Db::name('cms_tag');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('name', "%{$keyword}%");
        }
        $list = $query->field($this->fieldString('cms_tag', ['id','name','nums','seotitle','keywords','description','views','autolink','createtime']))
            ->order('nums', 'desc')->order('id', 'desc')
            ->limit($limit)->select()->toArray();

        $this->success('', ['list' => $list]);
    }

    /**
     * 按标签取内容
     */
    public function archives()
    {
        $name = trim((string)$this->request->param('name', ''));
        $id   = (int)$this->request->param('id', 0);
        if ($name === '' && $id <= 0) {
            $this->fail('缺少标签标识（name 或 id）', self::CODE_PARAM_MISSING);
        }

        $tagQuery = Db::name('cms_tag');
        $tag = $id > 0 ? $tagQuery->where('id', $id)->find() : $tagQuery->where('name', $name)->find();
        if (!$tag) {
            $this->fail('标签不存在', self::CODE_NOT_FOUND);
        }

        [$page, $limit] = $this->cmsPageParams();
        $aids = Db::name('cms_taggable')->where('tag_id', (int)$tag['id'])->column('archives_id');
        if (!$aids) {
            $this->success('', ['tag' => $tag, 'list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $query = Db::name('cms_archives')
            ->whereIn('id', array_unique(array_map('intval', $aids)))
            ->where('status', 'normal')->whereNull('deletetime');
        $total = (clone $query)->count();
        $list = $query->order('publishtime', 'desc')->page($page, $limit)
            ->field($this->fieldString('cms_archives', ['id','channel_id','title','image','description','tags','views','comments','likes','publishtime']))
            ->select()->toArray();

        $this->success('', ['tag' => $tag, 'list' => $this->translations()->rows('cms_archive', $list), 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }
}
