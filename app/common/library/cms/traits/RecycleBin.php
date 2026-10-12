<?php

// +----------------------------------------------------------------------
// | CMS 模块回收站公共方法
// +----------------------------------------------------------------------
// | 原 FastAdmin 的 Backend 基类自带 recyclebin 方法，这里以 trait 形式补齐
// +----------------------------------------------------------------------

namespace app\common\library\cms\traits;

trait RecycleBin
{
    /**
     * 回收站列表（仅展示已软删除的数据）
     * @return mixed
     */
    public function recyclebin()
    {
        if (!$this->isAjax()) {
            $controller = convert_path_to_snake_case($this->request->controller());
            $this->view->assign('recyclebinUrl', (string)url($controller . '/recyclebin'));
            $this->view->assign('restoreUrl', (string)url($controller . '/restore'));
            $this->view->assign('destroyUrl', (string)url($controller . '/destroy'));
            return $this->view->fetch('cms/common/recyclebin');
        }

        [$where, $sort, $order, $offset, $limit] = $this->buildparams();

        $list = $this->model
            ->onlyTrashed()
            ->where($where)
            ->order($sort, $order)
            ->paginate($limit);

        $this->result('', $list->items(), $list->total());
    }

    /**
     * 真实删除回收站数据
     * @param mixed $ids
     * @return void
     */
    public function destroy($ids = '')
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->param('ids');
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', ['ids']));
        }

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($this->model->onlyTrashed()->where($this->pk, 'in', $ids)->select() as $item) {
                $count += $item->force()->delete();
            }
            $this->model->commit();
        } catch (\Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Delete successful'));
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 还原回收站数据
     * @param mixed $ids
     * @return void
     */
    public function restore($ids = '')
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->param('ids');
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', ['ids']));
        }

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($this->model->onlyTrashed()->where($this->pk, 'in', $ids)->select() as $item) {
                $count += $item->restore();
            }
            $this->model->commit();
        } catch (\Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Restore successful'));
        }
        $this->error(__('No rows were restored'));
    }
}
