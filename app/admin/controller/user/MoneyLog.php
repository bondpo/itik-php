<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;

/**
 * 会员余额变动表
 */
class MoneyLog extends Backend
{
    /**
     * MoneyLog模型对象
     * @var \app\admin\model\MoneyLog
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\MoneyLog();

    }



    public function selectpage()
    {
        return parent::selectpage();
    }
}
