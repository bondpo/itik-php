<?php

namespace app\admin\controller\i18n;

use app\common\controller\Backend;
use app\common\model\i18n\Translation as TranslationModel;
use app\common\model\i18n\Resource;

class Translation extends Backend
{
    protected $modelValidate = false;
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new TranslationModel();
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();
            $list = $this->model->where($where)->order($sort, $order)->paginate($limit);
            $this->result('', $list->items(), $list->total());
        }
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = $this->model->find((int)($ids ?? $this->request->param('ids')));
        if (!$row) {
            $this->error('译文不存在');
        }
        if ($this->request->isPost()) {
            $data = $this->request->post('row/a', []);
            try {
                // 人工译文按 HTML 白名单净化，避免富文本引入可执行内容。
                $text = xss_clean((string)($data['translated_text'] ?? ''));
                TranslationModel::revise((int)$row['id'], $text, (string)($data['status'] ?? 'review'), (string)($data['source_hash'] ?? ''));
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            $this->success('译文已保存并锁定，自动任务不会覆盖人工修订');
        }
        $source = Resource::current($row['resource_type'], (int)$row['resource_id']);
        if (!$source) {
            $this->error('原文不存在或未发布');
        }
        $row['source_text'] = (string)($source[$row['field']] ?? '');
        $row['source_hash'] = hash('sha256', $row['source_text']);
        $this->assign('row', $row);
        return $this->view->fetch();
    }

    public function retry($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error('请使用 POST 请求');
        }
        try {
            TranslationModel::retry((int)($ids ?? $this->request->param('ids')));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已加入翻译任务，等待 worker 执行');
    }

    // 禁用通用写入入口，所有修订均须执行原文版本检查。
    public function add()
    {
        $this->error('译文由翻译任务生成');
    }

    public function multi($ids = null)
    {
        $this->error('请逐条编辑并确认译文');
    }
}
