<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\project;

use app\common\controller\Backend;
use think\facade\Db;

class Project extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\Project();
        $categoryTree = \app\admin\model\app\ProjectCategory::getTree(false);
        $this->view->assign('categoryTree', $categoryTree);
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
                ->with(['owner', 'category'])
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
            'offline'  => '已下线',
        ]);
        $this->view->assign('verifyList', [
            'none'     => '未认证',
            'pending'  => '认证中',
            'normal'   => '已认证',
            'rejected' => '认证驳回',
        ]);

        return $this->view->fetch();
    }

    /**
     * 项目详情查看 (弹窗子页面)
     */
    public function detail()
    {
        $id = (int)($this->request->param('ids') ?: $this->request->param('id'));
        $row = $this->model
            ->with(['owner', 'category', 'timelines'])
            ->where('id', $id)
            ->find();
        if (!$row) {
            $this->error('项目不存在');
        }

        $materials = Db::name('app_project_material')
            ->where('project_id', $id)
            ->order('create_time', 'desc')
            ->select();

        $this->view->assign('row', $row);
        $this->view->assign('materials', $materials);
        return $this->view->fetch();
    }

    /**
     * 项目审核：通过 / 驳回
     * 说明：「已认证」只能由审核通过触发，不允许手工置位。
     */
    public function review()
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式不正确');
        }
        $ids    = (string)$this->request->post('ids');
        $action = (string)$this->request->post('action');
        $reason = (string)$this->request->post('reason', '');

        if (!in_array($action, ['pass', 'reject'], true)) {
            $this->error('审核动作不合法');
        }
        $ids = array_filter(array_map('intval', explode(',', $ids)));
        if (!$ids) {
            $this->error('请选择要审核的项目');
        }
        if ($action === 'reject' && $reason === '') {
            $this->error('驳回时必须填写原因');
        }

        Db::startTrans();
        try {
            foreach ($ids as $id) {
                $project = $this->model->where('id', $id)->find();
                if (!$project) {
                    continue;
                }
                $before = [
                    'status'        => $project['status'],
                    'verify_status' => $project['verify_status'],
                    'certified'     => (int)$project['certified'],
                ];
                $data = $action === 'pass'
                    ? ['status' => 'normal', 'verify_status' => 'normal', 'certified' => 1]
                    : ['status' => 'rejected', 'verify_status' => 'rejected', 'certified' => 0];
                // update_time 由模型 autoWriteTimestamp 自动维护
                $project->save($data);

                $version = (int)Db::name('app_content_review')
                    ->where('content_type', 'project')
                    ->where('content_id', $id)
                    ->count() + 1;

                Db::name('app_content_review')->insert([
                    'content_type' => 'project',
                    'content_id'   => $id,
                    'version'      => $version,
                    'submitter_id' => (int)$project['owner_user_id'],
                    'reviewer_id'  => $this->auth->id,
                    'result'       => $action === 'pass' ? 'normal' : 'rejected',
                    'reason'       => $reason,
                    'submitted_at' => time(),
                    'reviewed_at'  => time(),
                    'create_time'  => time(),
                ]);

                Db::name('app_audit_log')->insert([
                    'actor_type'  => 'admin',
                    'actor_id'    => $this->auth->id,
                    'action'      => 'project.' . $action,
                    'target_type' => 'project',
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
}
