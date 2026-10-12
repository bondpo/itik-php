<?php

namespace app\admin\controller\cms;

use app\common\controller\Backend;
use app\common\model\User;
use think\facade\Db;
use think\exception\PDOException;

/**
 * 评论管理
 *
 * @icon fa fa-comment
 */
class Comment extends Backend
{
    /**
     * Comment模型对象
     */
    protected $model = null;
    protected $searchFields = 'id,aid,content';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\cms\Comment;
        $this->view->assign("statusList", $this->model->getStatusList());
    }

    /**
     * 查看
     */
    public function index()
    {
        $this->relationSearch = true;
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            [$where, $sort, $order, $offset, $limit] = $this->buildparams(null, false);
            $total = $this->model
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $item) {
                // 兼容匿名评论（$item->user 可能为空）
                $item->user && $item->user->visible(['id', 'username', 'nickname', 'avatar']);
                $item->source = $item->source;
            }
            $list = collection($list)->toArray();
            $this->result('', $list, $total);
        }
        return $this->view->fetch();
    }

    public function recyclebin()
    {
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            $this->relationSearch = true;
            [$where, $sort, $order, $offset, $limit] = $this->buildparams(null, false);
            $total = $this->model
                ->onlyTrashed()
                ->with(['archives', 'user'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->onlyTrashed()
                ->with(['archives', 'user'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $item) {
                $item->user && $item->user->visible(['id', 'username', 'nickname', 'avatar']);
                $item->url = $item->archives ? $item->archives->url : 'javascript:';
            }
            $list = collection($list)->toArray();
            $this->result('', $list, $total);
        }
        return $this->view->fetch();
    }

    public function restore($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $pk = $this->model->getPk();
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds)) {
            $this->model->where($this->dataLimitField, 'in', $adminIds);
        }
        if ($ids) {
            $this->model->where($pk, 'in', $ids);
        }
        $config = get_cms_config();
        $list = $this->model->onlyTrashed()->select();
        if ($list) {
            $ids = [];
            foreach ($list as $item) {
                if ($item['status'] == 'normal' && !empty($config['score']['postcomment'])) {
                    User::score($config['score']['postcomment'], $item['user_id'], '发表评论');
                }
                $ids[] = $item['id'];
            }
            $this->model->where('id', 'in', $ids);
            $this->model->restore('1=1');
            foreach ($list as $item) {
                \app\admin\model\cms\Comment::refreshSourceComments($item['id']);
            }
            $this->success();
        }
        $this->error(__('No rows were updated'));
    }
}
