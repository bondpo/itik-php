<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;

/**
 * 团队等级
 */
class LvTeam extends Backend
{
    /**
     * UserLvTeam模型对象
     * @var \app\admin\model\UserLvTeam
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\UserLvTeam();
        $this->view->assign('StatusList', $this->model->getStatusList());
    }



    public function selectpage()
    {
        return parent::selectpage();
    }
}
