<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器（咨询会话）
// +----------------------------------------------------------------------

namespace app\admin\controller\consult;

use app\common\controller\Backend;
use think\facade\Db;

class Log extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\Conversation();
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
                ->with(['initiator'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        $this->view->assign('statusList', ['open' => '进行中', 'closed' => '已关闭']);
        $this->view->assign('typeList', ['consult' => '留言咨询']);
        return $this->view->fetch();
    }

    /**
     * 查看某个会话的留言（供后台客服/风控核查）
     * 只读，不改变用户侧已读状态。
     */
    public function messages()
    {
        $cid = (int)$this->request->param('conversation_id', 0);
        if ($cid <= 0) {
            $this->error('缺少会话 ID');
        }
        $page = max(1, (int)$this->request->param('page', 1));
        $limit = max(1, min(200, (int)$this->request->param('limit', 50)));
        try {
            $data = $this->model->getMessages($cid, $page, $limit);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('', null, $data);
    }

    public function del()
    {
        if (!$this->request->isPost()) {
            $this->error('请使用 POST 请求');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string)$this->request->post('ids', ''))),
            fn($id) => $id > 0
        )));
        if (!$ids) {
            $this->error('请选择要删除的会话');
        }
        try {
            $count = $this->model->deleteWithMessages($ids, (int)$this->auth->id, $this->request->ip());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已删除 ' . $count . ' 个会话');
    }

    /**
     * 关闭会话（运营介入）
     */
    public function close()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids'))));
        if (!$ids) {
            $this->error('请选择要关闭的会话');
        }
        Db::name('app_conversation')->whereIn('id', $ids)->update(['status' => 'closed', 'update_time' => time()]);
        foreach ($ids as $id) {
            Db::name('app_audit_log')->insert([
                'actor_type' => 'admin', 'actor_id' => (int)$this->auth->id,
                'action' => 'conversation.close', 'target_type' => 'conversation', 'target_id' => $id,
                'ip' => $this->request->ip(), 'create_time' => time(),
            ]);
        }
        $this->success('已关闭 ' . count($ids) . ' 个会话');
    }
}
