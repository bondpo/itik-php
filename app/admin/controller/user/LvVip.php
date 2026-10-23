<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;

/**
 * VIP等级
 */
class LvVip extends Backend
{
    /**
     * UserLvVip模型对象
     * @var \app\admin\model\UserLvVip
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\UserLvVip();
        $this->view->assign('StatusList', $this->model->getStatusList());
    }



    public function selectpage()
    {
        return parent::selectpage();
    }
}
