<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\project;

use app\common\controller\Backend;
use think\facade\Db;

class Material extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\ProjectMaterial();
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
                ->with(['project'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        $this->view->assign('reviewList', [
            'pending'  => '待审核',
            'normal'   => '已通过',
            'rejected' => '已驳回',
        ]);

        return $this->view->fetch();
    }
}
