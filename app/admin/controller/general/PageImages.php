<?php

namespace app\admin\controller\general;

use app\common\controller\Backend;

/**
 * bd_page_images
 */
class PageImages extends Backend
{
    /**
     * PageImages模型对象
     * @var \app\admin\model\PageImages
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\PageImages();
        $this->view->assign('StatusList', $this->model->getStatusList());
    }



    public function selectpage()
    {
        return parent::selectpage();
    }
}
