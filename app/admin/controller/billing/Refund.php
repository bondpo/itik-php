<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\billing;

use app\common\controller\Backend;
use think\facade\Db;

class Refund extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\Refund();
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
                ->with(['order'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        $this->view->assign('statusList', [
            'pending'  => '待审批',
            'approved' => '已通过',
            'rejected' => '已驳回',
            'refunded' => '已退款',
        ]);

        return $this->view->fetch();
    }

    /**
     * 审批退款（通过 / 驳回）
     */
    public function audit()
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式不正确');
        }
        $ids    = (string)$this->request->post('ids');
        $action = (string)$this->request->post('action');
        $note   = (string)$this->request->post('note', '');

        if (!in_array($action, ['approve', 'reject'], true)) {
            $this->error('审批动作不合法');
        }
        $ids = array_filter(array_map('intval', explode(',', $ids)));
        if (!$ids) {
            $this->error('请选择要审批的退款申请');
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';
        Db::startTrans();
        try {
            foreach ($ids as $id) {
                $refund = $this->model->where('id', $id)->find();
                if (!$refund) {
                    continue;
                }
                if ($refund['status'] !== 'pending') {
                    throw new \Exception("退款单 #{$id} 当前状态不是待审批");
                }
                // update_time 由模型 autoWriteTimestamp 自动维护，勿显式赋值
                $refund->save([
                    'status'      => $status,
                    'operator_id' => $this->auth->id,
                    'audit_note'  => $note,
                ]);
                Db::name('app_audit_log')->insert([
                    'actor_type'  => 'admin',
                    'actor_id'    => $this->auth->id,
                    'action'      => 'refund.' . $action,
                    'target_type' => 'refund',
                    'target_id'   => $id,
                    'before'      => json_encode(['status' => 'pending'], JSON_UNESCAPED_UNICODE),
                    'after'       => json_encode(['status' => $status, 'note' => $note], JSON_UNESCAPED_UNICODE),
                    'ip'          => $this->request->ip(),
                    'create_time' => time(),
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        $this->success('审批完成');
    }
}
