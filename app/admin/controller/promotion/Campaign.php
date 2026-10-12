<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\promotion;

use app\common\controller\Backend;
use think\facade\Db;

class Campaign extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\PromotionCampaign();
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
            'pending'  => '待审核',
            'normal'   => '投放中',
            'rejected' => '已驳回',
            'finished' => '已结束',
            'canceled' => '已取消',
        ]);

        return $this->view->fetch();
    }

    /**
     * 投放审核：通过 / 驳回
     * 通过时会二次校验素材审核状态，避免「先通过后素材下架」的时序漏洞。
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
            $this->error('请选择要审核的投放计划');
        }
        if ($action === 'reject' && $reason === '') {
            $this->error('驳回时必须填写原因');
        }

        $service = new \app\common\service\app\PromotionService();
        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $service->reviewCampaign($id, $action, (int)$this->auth->id, $reason);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = "投放 #{$id}：" . $e->getMessage();
            }
        }

        if ($ok === 0) {
            $this->error(implode('；', $errors) ?: '审核失败');
        }
        $msg = '审核完成（成功 ' . $ok . ' 条）';
        if ($errors) {
            $msg .= '，失败：' . implode('；', $errors);
        }
        $this->success($msg);
    }
}
