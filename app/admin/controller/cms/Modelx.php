<?php

namespace app\admin\controller\cms;

use modules\cms\library\Service;
use app\common\controller\Backend;
use think\facade\Db;
use think\Exception;

/**
 * 内容模型表
 *
 * @icon fa fa-th
 */
class Modelx extends Backend
{

    /**
     * Model模型对象
     */
    protected $model = null;
    protected $searchFields = 'id,name,table';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\cms\Modelx;
    }

    /**
     * 删除
     * @param mixed $ids
     */
    public function edit($ids = "")
    {
        $channelList = \app\admin\model\cms\Channel::where('model_id', $ids)->select();
        $this->view->assign('channelList', $channelList);
        return parent::edit($ids);
    }

    /**
     * 复制模型
     */
    public function duplicate($ids = "")
    {
        $row = $this->model->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds)) {
            if (!in_array($row[$this->dataLimitField], $adminIds)) {
                $this->error(__('You have no permission'));
            }
        }
        if ($this->request->isPost()) {
            $table = $this->request->request("table");
            $tableInfo = null;
            try {
                $tableInfo = \think\facade\Db::name($table)->getTableInfo();
            } catch (\Exception $e) {
            }
            if ($tableInfo) {
                $this->error("模型表名已经存在");
            }
            $prefix = config('database.prefix');
            if (preg_match("/^" . $prefix . "/i", $table)) {
                $this->error("请勿添加表前缀:" . $prefix);
            }
            $data = [
                'name'       => $row->getData('name') . '_copy',
                'table'      => $table,
                'fields'     => $row->fields,
                'setting'    => $row->setting,
            ];
            $modelx = \app\admin\model\cms\Modelx::create($data, true);
            $fieldsList = \app\admin\model\cms\Fields::where('source', 'model')->where('source_id', $row['id'])->select();
            foreach ($fieldsList as $index => $item) {
                $data = $item->toArray();
                $data['source_id'] = $modelx->id;
                unset($data['id'], $data['createtime'], $data['updatetime']);
                \app\admin\model\cms\Fields::create($data, true);
            }
            $this->success();
        }
        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

}
