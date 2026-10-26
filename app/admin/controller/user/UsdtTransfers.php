<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;

/**
 * USDT转账记录
 */
class UsdtTransfers extends Backend
{
    /**
     * UsdtTransfers模型对象
     * @var \app\admin\model\UsdtTransfers
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\UsdtTransfers();
        $this->view->assign('TaskIdList', $this->model->getTaskIdList());
        $this->view->assign('TokenSymbolList', $this->model->getTokenSymbolList());
        $this->view->assign('StatusList', $this->model->getStatusList());
    }



    public function selectpage()
    {
        return parent::selectpage();
    }
}
