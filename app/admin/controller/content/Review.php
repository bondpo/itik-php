<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器（内容审核）
// +----------------------------------------------------------------------

namespace app\admin\controller\content;

use app\common\controller\Backend;
use think\facade\Db;

class Review extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\Content();
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();
            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        $this->view->assign('statusList', [
            'draft'    => '草稿',
            'pending'  => '待审核',
            'normal'   => '已通过',
            'rejected' => '已驳回',
            'hidden'   => '已下架',
        ]);
        return $this->view->fetch();
    }

    /**
     * 内容审核：通过 / 驳回（驳回须填原因）
     * 审核通过时同步发布状态与发布时间。
     */
    public function review()
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式不正确');
        }
        $ids    = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids'))));
        $action = (string)$this->request->post('action');
        $reason = (string)$this->request->post('reason', '');

        if (!in_array($action, ['pass', 'reject'], true)) {
            $this->error('审核动作不合法');
        }
        if (!$ids) {
            $this->error('请选择要审核的内容');
        }
        if ($action === 'reject' && $reason === '') {
            $this->error('驳回时必须填写原因');
        }

        Db::startTrans();
        try {
            foreach ($ids as $id) {
                $content = Db::name('app_content')->where('id', $id)->whereNull('deletetime')->find();
                if (!$content) {
                    continue;
                }
                $before = ['status' => $content['status'], 'version' => (int)$content['version']];

                $data = ['update_time' => time()];
                if ($action === 'pass') {
                    $data['status'] = 'normal';
                    $data['published_at'] = (int)$content['published_at'] > 0 ? $content['published_at'] : time();
                } else {
                    $data['status'] = 'rejected';
                }
                Db::name('app_content')->where('id', $id)->update($data);

                $version = (int)Db::name('app_content_review')
                    ->where('content_type', 'content')->where('content_id', $id)->count() + 1;
                Db::name('app_content_review')->insert([
                    'content_type' => 'content',
                    'content_id'   => $id,
                    'version'      => $version,
                    'submitter_id' => (int)$content['author_id'],
                    'reviewer_id'  => (int)$this->auth->id,
                    'result'       => $action === 'pass' ? 'normal' : 'rejected',
                    'reason'       => $reason,
                    'submitted_at' => time(),
                    'reviewed_at'  => time(),
                    'create_time'  => time(),
                ]);

                Db::name('app_audit_log')->insert([
                    'actor_type'  => 'admin',
                    'actor_id'    => (int)$this->auth->id,
                    'action'      => 'content.' . $action,
                    'target_type' => 'content',
                    'target_id'   => $id,
                    'before'      => json_encode($before, JSON_UNESCAPED_UNICODE),
                    'after'       => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'ip'          => $this->request->ip(),
                    'create_time' => time(),
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        $this->success('审核完成');
    }

    /**
     * 下架（保留历史，供风控与投诉追溯）
     */
    public function offline()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids'))));
        if (!$ids) {
            $this->error('请选择要下架的内容');
        }

        Db::name('app_content')->whereIn('id', $ids)->update(['status' => 'hidden', 'update_time' => time()]);
        foreach ($ids as $id) {
            Db::name('app_audit_log')->insert([
                'actor_type' => 'admin', 'actor_id' => (int)$this->auth->id,
                'action' => 'content.offline', 'target_type' => 'content', 'target_id' => $id,
                'ip' => $this->request->ip(), 'create_time' => time(),
            ]);
        }
        $this->success('已下架 ' . count($ids) . ' 条内容');
    }

    /**
     * 查看内容详情与审核历史
     */
    public function detail($ids = null)
    {
        $id = $ids ? (int)$ids : (int)$this->request->param('ids');
        $row = $this->model->where('id', $id)->whereNull('deletetime')->find();
        if (!$row) {
            $this->error('内容不存在或已被删除');
        }

        // 作者信息
        $author = Db::name('user')->where('id', (int)$row['author_id'])
            ->field('id,username,nickname,avatar,email,wallet_address')->find();
        $authorName = 'ID: ' . $row['author_id'];
        if ($author) {
            $authorName = !empty($author['nickname']) ? $author['nickname'] : (!empty($author['username']) ? $author['username'] : ('ID: ' . $row['author_id']));
        }

        // 关联项目
        $project = null;
        if (!empty($row['project_id'])) {
            $project = Db::name('app_project')->where('id', (int)$row['project_id'])
                ->field('id,name,subtitle,cover,summary')->find();
        }

        // 审核历史记录
        $reviews = Db::name('app_content_review')
            ->alias('r')
            ->leftJoin('admin a', 'a.id = r.reviewer_id')
            ->where('r.content_type', 'content')
            ->where('r.content_id', $id)
            ->order('r.id', 'desc')
            ->field('r.*, a.username as reviewer_name, a.nickname as reviewer_nickname')
            ->select()->toArray();

        foreach ($reviews as &$rv) {
            $rv['display_reviewer'] = !empty($rv['reviewer_nickname'])
                ? $rv['reviewer_nickname']
                : (!empty($rv['reviewer_name']) ? $rv['reviewer_name'] : ('管理员(ID:' . $rv['reviewer_id'] . ')'));
            $rvTime = $rv['create_time'] ?? null;
            $rv['create_time_text'] = !empty($rvTime)
                ? (is_numeric($rvTime) ? date('Y-m-d H:i:s', (int)$rvTime) : (string)$rvTime)
                : '-';
        }
        unset($rv);

        $tagList = [];
        if (!empty($row['tags'])) {
            $tagList = array_values(array_filter(array_map('trim', explode(',', (string)$row['tags']))));
        }

        // 安全格式化时间显示
        $createTimeVal = $row['create_time'] ?? null;
        $createTimeText = !empty($createTimeVal)
            ? (is_numeric($createTimeVal) ? date('Y-m-d H:i:s', (int)$createTimeVal) : (string)$createTimeVal)
            : '-';

        $publishedAtVal = $row['published_at'] ?? null;
        $publishedAtText = '';
        if (!empty($publishedAtVal) && is_numeric($publishedAtVal) && (int)$publishedAtVal > 0) {
            $publishedAtText = date('Y-m-d H:i:s', (int)$publishedAtVal);
        } elseif (!empty($publishedAtVal) && !is_numeric($publishedAtVal)) {
            $publishedAtText = (string)$publishedAtVal;
        }

        $this->view->assign('row', $row);
        $this->view->assign('author', $author);
        $this->view->assign('authorName', $authorName);
        $this->view->assign('project', $project);
        $this->view->assign('reviews', $reviews);
        $this->view->assign('tagList', $tagList);
        $this->view->assign('createTimeText', $createTimeText);
        $this->view->assign('publishedAtText', $publishedAtText);
        return $this->view->fetch();
    }
}
