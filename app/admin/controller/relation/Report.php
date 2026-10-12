<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器（举报处理）
// +----------------------------------------------------------------------

namespace app\admin\controller\relation;

use app\common\controller\Backend;
use think\facade\Db;

class Report extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\Report();
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
            'pending'    => '待处理',
            'processing' => '处理中',
            'resolved'   => '已处理',
            'rejected'   => '已驳回',
        ]);
        $this->view->assign('categoryList', [
            'fraud'         => '欺诈',
            'false_content' => '虚假内容',
            'harass'        => '骚扰',
            'illegal'       => '违法违规',
            'other'         => '其他',
        ]);
        return $this->view->fetch();
    }

    /**
     * 处理举报
     * action: resolve 判定成立 / reject 判定不成立 / process 标记处理中
     * ban=1 且 target_type=user 时联动隐藏该用户（封禁）
     */
    public function handle()
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式不正确');
        }
        $ids    = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids'))));
        $action = (string)$this->request->post('action');
        $note   = (string)$this->request->post('note', '');
        $ban    = (int)$this->request->post('ban', 0);

        if (!in_array($action, ['resolve', 'reject', 'process'], true)) {
            $this->error('处理动作不合法');
        }
        if (!$ids) {
            $this->error('请选择要处理的举报');
        }

        $statusMap = ['resolve' => 'resolved', 'reject' => 'rejected', 'process' => 'processing'];
        $status = $statusMap[$action];

        Db::startTrans();
        try {
            foreach ($ids as $id) {
                $report = Db::name('app_report')->where('id', $id)->find();
                if (!$report) {
                    continue;
                }
                Db::name('app_report')->where('id', $id)->update([
                    'status'      => $status,
                    'handler_id'  => (int)$this->auth->id,
                    'handle_note' => $note,
                    'update_time' => time(),
                ]);

                if ($action === 'resolve' && $ban === 1 && $report['target_type'] === 'user') {
                    Db::name('user')->where('id', (int)$report['target_id'])->update([
                        'status' => 'hidden', 'update_time' => time(),
                    ]);
                }

                Db::name('app_audit_log')->insert([
                    'actor_type'  => 'admin',
                    'actor_id'    => (int)$this->auth->id,
                    'action'      => 'report.' . $action,
                    'target_type' => 'report',
                    'target_id'   => $id,
                    'before'      => json_encode(['status' => $report['status']], JSON_UNESCAPED_UNICODE),
                    'after'       => json_encode(['status' => $status, 'note' => $note, 'ban' => $ban], JSON_UNESCAPED_UNICODE),
                    'ip'          => $this->request->ip(),
                    'create_time' => time(),
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        $this->success('处理完成');
    }
}
