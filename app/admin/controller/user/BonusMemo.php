<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;

/**
 * 奖金名称
 */
class BonusMemo extends Backend
{
    /**
     * BonusMemo模型对象
     * @var \app\admin\model\BonusMemo
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\BonusMemo();

    }



    public function selectpage()
    {
        return parent::selectpage();
    }
}
