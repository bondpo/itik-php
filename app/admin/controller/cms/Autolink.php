<?php

namespace app\admin\controller\cms;

use app\common\controller\Backend;

/**
 * 自动链接管理
 *
 * @icon fa fa-circle-o
 */
class Autolink extends Backend
{
    use \app\common\library\cms\traits\RecycleBin;


    /**
     * Autolink模型对象
     * @var \app\admin\model\cms\Autolink
     */
    protected $model = null;
    protected $searchFields = 'id,title,url';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\cms\Autolink;
        $this->view->assign("targetList", $this->model->getTargetList());
        $this->view->assign("statusList", $this->model->getStatusList());
    }

}
