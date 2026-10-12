<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\promotion;

use app\common\controller\Backend;
use think\facade\Db;

class Package extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\PromotionPackage();
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

        return $this->view->fetch();
    }
}
