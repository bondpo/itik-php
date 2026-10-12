<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 项目赛道分类管理控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\project;

use app\admin\model\app\ProjectCategory as CategoryModel;
use app\common\controller\Backend;
use think\facade\Db;

class Category extends Backend
{
    /**
     * @var CategoryModel
     */
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CategoryModel();

        // 仅一级分类可作为父级
        $parentList = CategoryModel::where('pid', 0)->order('weigh', 'desc')->order('id', 'asc')->select();
        $this->view->assign('parentList', $parentList);
    }

    /**
     * 分类列表
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            [$where, $sort, $order, $offset, $limit] = $this->buildparams();

            // 查询所有数据，并在前端或数据层维护层级展示
            $list = $this->model
                ->with(['parent'])
                ->where($where)
                ->order('pid', 'asc')
                ->order('weigh', 'desc')
                ->order('id', 'asc')
                ->select();

            // 统计每个分类下的公开及所有项目数
            $projectCounts = Db::name('app_project')
                ->group('category_id')
                ->column('count(*)', 'category_id');

            $items = [];
            foreach ($list as $item) {
                $row = $item->toArray();
                $row['parent_name'] = $item->parent ? $item->parent->name : '一级分类 (顶级)';
                $row['project_count'] = (int)($projectCounts[$item->id] ?? 0);
                $items[] = $row;
            }

            $this->result('ok', $items, count($items));
        }

        return $this->view->fetch();
    }

    /**
     * 添加分类
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (empty($params['name'])) {
                $this->error('请填写分类名称');
            }

            $pid = (int)($params['pid'] ?? 0);
            if ($pid > 0) {
                $parentCat = $this->model->where('id', $pid)->find();
                if (!$parentCat) {
                    $this->error('所选上级分类不存在');
                }
                if ((int)$parentCat['pid'] > 0) {
                    $this->error('系统仅支持两级赛道体系（一级分类与二级细分赛道），不允许创建三级分类');
                }
            }

            $params['pid'] = $pid;
            $params['weigh'] = (int)($params['weigh'] ?? 50);
            $params['status'] = $params['status'] ?? 'normal';

            Db::startTrans();
            try {
                $result = $this->model->save($params);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                $this->success('添加分类成功');
            } else {
                $this->error($this->model->getError());
            }
        }

        return $this->view->fetch();
    }

    /**
     * 编辑分类
     */
    public function edit()
    {
        $id = $this->request->param('ids');
        $row = $this->model->where('id', $id)->find();
        if (!$row) {
            $this->error('分类不存在');
        }

        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (empty($params['name'])) {
                $this->error('请填写分类名称');
            }

            $pid = (int)($params['pid'] ?? 0);
            if ($pid === (int)$row['id']) {
                $this->error('上级分类不能选择分类自身');
            }
            if ($pid > 0) {
                $parentCat = $this->model->where('id', $pid)->find();
                if (!$parentCat) {
                    $this->error('所选上级分类不存在');
                }
                if ((int)$parentCat['pid'] > 0) {
                    $this->error('系统仅支持两级赛道体系，不允许创建三级分类');
                }
                // 检查当前分类是否本身含有子分类，若有子分类，不能挂载到其他父分类下
                $hasChildren = $this->model->where('pid', $row['id'])->count();
                if ($hasChildren > 0) {
                    $this->error('当前分类已包含二级子分类，不能作为子分类挂载');
                }
            }

            $params['pid'] = $pid;
            $params['weigh'] = (int)($params['weigh'] ?? 50);

            Db::startTrans();
            try {
                $row->save($params);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }

            $this->success('保存修改成功');
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 删除分类
     */
    public function del()
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式不正确');
        }

        $ids = (string)$this->request->post('ids');
        $idList = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idList)) {
            $this->error('请选择要删除的分类');
        }

        // 检查是否有子分类
        $hasChildren = CategoryModel::whereIn('pid', $idList)->count();
        if ($hasChildren > 0) {
            $this->error('所选分类下包含子分类，请先删除或转移子分类');
        }

        // 检查是否有关联项目
        $hasProjects = Db::name('app_project')->whereIn('category_id', $idList)->count();
        if ($hasProjects > 0) {
            $this->error(sprintf('所选分类下尚有 %d 个关联项目，请先修改项目所属分类', $hasProjects));
        }

        Db::startTrans();
        try {
            $count = $this->model->whereIn('id', $idList)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        $this->success(sprintf('成功删除 %d 个分类', $count));
    }
}
