<?php

namespace app\admin\controller\cms;

use app\common\controller\Backend;

/**
 * 区块表
 *
 * @icon fa fa-th-large
 */
class Block extends Backend
{

    /**
     * Block模型对象
     */
    protected $model = null;
    protected $searchFields = 'id,name,type,title,url';
    protected $noNeedRight = ['selectpage_type'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\cms\Block;
        $this->view->assign("statusList", $this->model->getStatusList());
        $this->view->assign("nameList", $this->model->getNameList());
    }

    public function index()
    {
        $typeArr = \app\admin\model\cms\Block::distinct('type')->column('type');
        $this->view->assign('typeList', $typeArr);
        $this->assignconfig('typeList', $typeArr);

        //主表字段
        $fields = \app\admin\model\cms\Fields::getJsFieldsList('block');
        $this->assignconfig('fields', $fields);

        return parent::index();
    }

    public function selectpage_type()
    {
        $list = [];
        $word = (array)$this->request->request("q_word/a");
        $field = $this->request->request('showField');
        $keyValue = $this->request->request('keyValue');
        if (!$keyValue) {
            if (array_filter($word)) {
                foreach ($word as $k => $v) {
                    $list[] = ['id' => $v, $field => $v];
                }
            }
            $typeArr = \app\admin\model\cms\Block::column('type');
            $typeArr = array_unique($typeArr);
            foreach ($typeArr as $index => $item) {
                $list[] = ['id' => $item, $field => $item];
            }
        } else {
            $list[] = ['id' => $keyValue, $field => $keyValue];
        }
        return json(['total' => count($list), 'list' => $list]);
    }

    /**
     * 添加
     */
    public function add()
    {
        $values = [];
        $fields = \app\common\library\cms\Service::getCustomFields('block', 0, $values);

        $this->view->assign('fields', $fields);
        $this->view->assign('values', $values);
        return parent::add();
    }

    public function edit($ids = null)
    {
        $values = \app\admin\model\cms\Block::find($ids);
        if (!$values) {
            $this->error(__('No Results were found'));
        }
        $values = $values->toArray();
        $fields = \app\common\library\cms\Service::getCustomFields('block', 0, $values);

        $this->view->assign('fields', $fields);
        $this->view->assign('values', $values);
        return parent::edit($ids);
    }

}
