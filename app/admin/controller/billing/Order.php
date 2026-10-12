<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\billing;

use app\common\controller\Backend;
use think\facade\Db;

class Order extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\Order();
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
                ->with(['user'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        $this->view->assign('statusList', [
            'pending'          => '待支付',
            'paid'             => '已支付',
            'closed'           => '已关闭',
            'refunded'         => '已退款',
            'partial_refunded' => '部分退款',
        ]);

        return $this->view->fetch();
    }
}
