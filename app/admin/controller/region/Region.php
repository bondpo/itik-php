<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台地区管理控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\region;

use app\common\controller\Backend;

class Region extends Backend
{
    protected $model = null;
    protected $modelValidate = true;
    protected $noNeedRight = ['selectpage'];
    protected $searchFields = 'id,code,name_zh,name_en';

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\Region();
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            // 如果发送的来源是 Selectpage，则转发到 Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            [$where, $sort, $order, $offset, $limit] = $this->buildparams();
            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }

        return $this->view->fetch();
    }

    /**
     * 父级地区远程搜索（用于表单 remoteSelect）
     */
    public function selectpage()
    {
        return parent::selectpage();
    }

    /**
     * 删除前拦截：存在子地区时禁止删除，避免产生孤儿节点
     */
    public function del()
    {
        $ids = $this->request->param('ids');
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids && $this->model->whereIn('parent_id', $ids)->count() > 0) {
            $this->error('该地区下存在子地区，请先删除子地区');
        }

        return parent::del();
    }
}
