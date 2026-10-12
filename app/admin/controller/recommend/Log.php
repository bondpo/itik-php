<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器（推荐日志）
// +----------------------------------------------------------------------

namespace app\admin\controller\recommend;

use app\common\controller\Backend;

class Log extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\Recommendation();
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
                ->with(['receiver'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        $this->view->assign('channelList', [
            'owner_match'   => '项目方适配人脉',
            'leader_member' => '社区长每日会员',
        ]);
        $this->view->assign('feedbackList', [
            'relevant'   => '相关',
            'irrelevant' => '不相关',
        ]);
        return $this->view->fetch();
    }
}
