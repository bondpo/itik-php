<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 项目时间轴/里程碑管理控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\project;

use app\admin\model\app\ProjectTimeline as TimelineModel;
use app\admin\model\app\Project as ProjectModel;
use app\common\controller\Backend;
use think\facade\Db;

class Timeline extends Backend
{
    /**
     * @var TimelineModel
     */
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new TimelineModel();

        // 注入所有项目列表供下拉筛选
        $projectList = ProjectModel::field('id,name')->order('id', 'desc')->select();
        $this->view->assign('projectList', $projectList);
    }

    /**
     * 时间轴列表
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        $projectId = (int)$this->request->param('project_id', 0);

        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            [$where, $sort, $order, $offset, $limit] = $this->buildparams();

            $query = $this->model->with(['project', 'user'])->where($where);
            if ($projectId > 0) {
                $query->where('project_id', $projectId);
            }

            $list = $query
                ->order('event_time', 'desc')
                ->order('id', 'desc')
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }

        $this->view->assign('projectId', $projectId);
        return $this->view->fetch();
    }

    /**
     * 添加时间轴里程碑
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (empty($params['title'])) {
                $this->error('请填写里程碑事件标题');
            }
            if (empty($params['project_id'])) {
                $this->error('请选择所属项目');
            }

            // 处理日期格式转换为时间戳
            if (!empty($params['event_time']) && !is_numeric($params['event_time'])) {
                $params['event_time'] = strtotime($params['event_time']);
            } elseif (empty($params['event_time'])) {
                $params['event_time'] = time();
            }

            $params['user_id'] = $this->auth->id;
            $params['weigh']   = (int)($params['weigh'] ?? 0);
            $params['status']  = $params['status'] ?? 'normal';

            Db::startTrans();
            try {
                $result = $this->model->save($params);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                $this->success('添加时间轴里程碑成功');
            } else {
                $this->error($this->model->getError());
            }
        }

        $projectId = (int)$this->request->param('project_id', 0);
        $this->view->assign('projectId', $projectId);
        return $this->view->fetch();
    }

    /**
     * 编辑时间轴里程碑
     */
    public function edit()
    {
        $id = $this->request->param('ids');
        $row = $this->model->where('id', $id)->find();
        if (!$row) {
            $this->error('时间轴记录不存在');
        }

        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (empty($params['title'])) {
                $this->error('请填写里程碑事件标题');
            }

            if (!empty($params['event_time']) && !is_numeric($params['event_time'])) {
                $params['event_time'] = strtotime($params['event_time']);
            }

            $params['weigh'] = (int)($params['weigh'] ?? 0);

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
     * 删除时间轴
     */
    public function del()
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式不正确');
        }

        $ids = (string)$this->request->post('ids');
        $idList = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idList)) {
            $this->error('请选择要删除的时间轴记录');
        }

        Db::startTrans();
        try {
            $count = $this->model->whereIn('id', $idList)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        $this->success(sprintf('成功删除 %d 条时间轴节点', $count));
    }
}
