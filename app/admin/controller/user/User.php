<?php

// +----------------------------------------------------------------------
// | BADOUCMS [ 八斗网站系统 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2024-2030 http://doc.ldcode.com.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: lande <939134342@qq.com>
// +----------------------------------------------------------------------

namespace app\admin\controller\user;

use app\common\controller\Backend;
use think\facade\Db;

class User extends Backend
{
    protected $model = null;
    protected $modelValidate = true;
    protected $groupList = [];

    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\User();

        // 必须将结果集转换为数组
        $groupList = Db::name("user_group")
            ->order('id ASC')
            ->column('name', 'id');
        $lvTeamList = Db::name("user_lv_team")
            ->order('id ASC')
            ->column('name', 'id');

        $this->view->assign('groupdata', $groupList);
        $this->view->assign('lvTeamData', $lvTeamList);
    }

    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->with(['user_group', 'user_lv_team', 'inviter'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        return $this->view->fetch();
    }

    public function selectpage()
    {
        return parent::selectpage();
    }

    public function recharge()
    {
        $id = $this->request->param('ids');
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('No results were found'));
        }
        if ($this->request->isPost()) {
            $moneyChange = $this->request->post('amount/f');
            $memo = $this->request->post('memo');
            if ($moneyChange == 0) {
                $this->error(__('Please enter a valid amount'));
            }
            $before = $row->money;
            $after = function_exists('bcadd') ? bcadd($row->money, $moneyChange, 2) : ($row->money + $moneyChange);

            // 验证余额不能为负数
            if ($after < 0) {
                $this->error(__('Insufficient balance'));
            }
            $this->model->where('id', $id)->setInc('money', $moneyChange);
            \app\common\model\MoneyLog::create([
                'user_id' => $id,
                'money' => $moneyChange,
                'before' => $before,
                'after' => $after,
                'memo' => $memo ?: ($moneyChange > 0 ? '管理员充值' : '管理员扣减')
            ]);
            $this->success('充值成功');
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }
}
