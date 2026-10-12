<?php

// +----------------------------------------------------------------------
// | CMS API —— 会员中心（我的内容/评论/收藏/订单）
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 会员中心接口
 *
 * 路由：
 *   GET  /api/cms/user/overview      我的概览（内容/评论/收藏/订单统计）
 *   GET  /api/cms/user/archives      我发布的内容
 *   GET  /api/cms/user/comments      我的评论
 *   GET  /api/cms/user/favorites     我的收藏
 *   POST /api/cms/user/favorite      收藏/取消收藏内容
 */
class User extends Base
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /**
     * 我的概览
     */
    public function overview()
    {
        $userId = $this->requireLogin();

        $this->success('', [
            'archives'  => (int)Db::name('cms_archives')->where('user_id', $userId)->whereNull('deletetime')->count(),
            'comments'  => (int)Db::name('cms_comment')->where('user_id', $userId)->whereNull('deletetime')->count(),
            'favorites' => (int)Db::name('cms_collection')->where('user_id', $userId)->count(),
            'orders'    => (int)Db::name('cms_order')->where('user_id', $userId)->count(),
            'paid'      => (int)Db::name('cms_order')->where('user_id', $userId)->where('status', 'paid')->count(),
            'diyform'   => (int)Db::name('cms_message')->where('user_id', $userId)->count(),
        ]);
    }

    /**
     * 我发布的内容
     */
    public function archives()
    {
        $userId = $this->requireLogin();
        [$page, $limit] = $this->cmsPageParams();

        $query = Db::name('cms_archives')->where('user_id', $userId)->whereNull('deletetime');
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)
            ->field($this->fieldString('cms_archives', ['id','channel_id','title','image','description','views','comments','publishtime','createtime','status']))
            ->select()->toArray();

        $this->success('', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * 我的评论
     */
    public function comments()
    {
        $userId = $this->requireLogin();
        [$page, $limit] = $this->cmsPageParams();

        $query = Db::name('cms_comment')->where('user_id', $userId)->whereNull('deletetime');
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)
            ->field($this->fieldString('cms_comment', ['id','type','aid','pid','content','comments','createtime','status']))
            ->select()->toArray();

        // 补全评论对象标题
        foreach ($list as $k => $r) {
            $map = ['archives' => 'cms_archives', 'page' => 'cms_page', 'special' => 'cms_special'];
            $table = $map[$r['type']] ?? null;
            $list[$k]['target_title'] = $table
                ? (string)Db::name($table)->where('id', (int)$r['aid'])->value('title')
                : '';
        }

        $this->success('', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * 我的收藏
     */
    public function favorites()
    {
        $userId = $this->requireLogin();
        [$page, $limit] = $this->cmsPageParams();

        $query = Db::name('cms_collection')->where('user_id', $userId);
        $type = (string)$this->request->param('type', '');
        if ($type !== '') {
            $query->where('type', $type);
        }
        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)
            ->field($this->fieldString('cms_collection', ['id','type','aid','title','image','url','createtime']))
            ->select()->toArray();

        // 补全内容最新信息
        foreach ($rows as $k => $r) {
            $aid = (int)$r['aid'];
            $map = ['archives' => 'cms_archives', 'page' => 'cms_page', 'special' => 'cms_special'];
            $table = $map[$r['type']] ?? 'cms_archives';
            $row = Db::name($table)->where('id', $aid)->field($this->fieldString($table, ['id','title','image','description']))->find();
            if ($row) {
                $rows[$k]['title'] = $row['title'] ?? $rows[$k]['title'];
                $rows[$k]['image'] = $row['image'] ?? ($rows[$k]['image'] ?? '');
                $rows[$k]['description'] = $row['description'] ?? '';
            }
        }

        $this->success('', ['list' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * 收藏 / 取消收藏
     */
    public function favorite()
    {
        $userId = $this->requireLogin();
        $type = (string)$this->request->post('type', 'archives');
        $aid  = (int)$this->request->post('aid', 0);
        $action = (string)$this->request->post('action', 'add');

        if ($aid <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }
        if (!in_array($type, ['archives', 'page', 'special'], true)) {
            $this->fail('收藏类型不合法', self::CODE_PARAM_INVALID);
        }

        $exists = Db::name('cms_collection')->where('user_id', $userId)->where('type', $type)->where('aid', $aid)->find();

        if ($action === 'remove') {
            if ($exists) {
                Db::name('cms_collection')->where('id', $exists['id'])->delete();
            }
            $this->success('已取消收藏', ['favorited' => false]);
        }

        if ($exists) {
            $this->success('已收藏', ['favorited' => true, 'repeated' => true]);
        }

        $map = ['archives' => 'cms_archives', 'page' => 'cms_page', 'special' => 'cms_special'];
        $table = $map[$type];
        $row = Db::name($table)->where('id', $aid)->field($this->fieldString($table, ['id','title','image']))->find();
        if (!$row) {
            $this->fail('收藏对象不存在', self::CODE_NOT_FOUND);
        }

        $id = (int)Db::name('cms_collection')->insertGetId([
            'type'       => $type,
            'aid'        => $aid,
            'user_id'    => $userId,
            'title'      => (string)($row['title'] ?? ''),
            'image'      => (string)($row['image'] ?? ''),
            'url'        => '/api/cms/archives/detail?id=' . $aid,
            'createtime' => time(),
            'updatetime' => time(),
        ]);

        $this->success('收藏成功', ['id' => $id, 'favorited' => true]);
    }
}
