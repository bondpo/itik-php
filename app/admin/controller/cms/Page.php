<?php

namespace app\admin\controller\cms;

use app\common\controller\Backend;
use think\facade\Event;

/**
 * 单页表
 *
 * @icon fa fa-file
 */
class Page extends Backend
{
    use \modules\cms\library\traits\RecycleBin;


    /**
     * Page模型对象
     */
    protected $model = null;
    protected $searchFields = 'id,title,seotitle';
    protected $noNeedRight = ['select', 'selectpage_type', 'check_element_available'];

    public function initialize(): void
    {
        parent::initialize();
        $config = get_addon_config('cms');
        if ($config['pagedatalimit'] != 'all') {
            $this->dataLimit = $config['pagedatalimit'];
        }

        $config = get_addon_config('cms');
        $this->assignconfig('spiderRecord', intval($config['spiderrecord']?? 0));

        $this->model = new \app\admin\model\cms\Page;
        $this->view->assign("flagList", $this->model->getFlagList());
        $this->view->assign("statusList", $this->model->getStatusList());
        $this->assignconfig("flagList", $this->model->getFlagList());
    }

    /**
     * 查看
     */
    public function index()
    {
        $typeArr = \app\admin\model\cms\Page::distinct('type')->column('type');
        $this->view->assign('typeList', $typeArr);
        $this->assignconfig('typeList', $typeArr);
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);


            \app\admin\model\cms\SpiderLog::render($list, 'page');

            $this->result('', $list->items(), $list->total());
        }

        //主表字段
        $fields = \app\admin\model\cms\Fields::getJsFieldsList('page');
        $this->assignconfig('fields', $fields);

        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
        $values = [];
        $fields = \modules\cms\library\Service::getCustomFields('page', 0, $values);

        $this->view->assign('fields', $fields);
        $this->view->assign('values', $values);
        return parent::add();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        $values = \app\admin\model\cms\Page::find($ids);
        if (!$values) {
            $this->error(__('No Results were found'));
        }
        $values = $values->toArray();
        $fields = \modules\cms\library\Service::getCustomFields('page', 0, $values);

        $this->view->assign('fields', $fields);
        $this->view->assign('values', $values);
        return parent::edit($ids);
    }

    /**
     * 选择单页
     */
    public function select()
    {
        if (!$this->auth->check('cms/page/index')) {
            Event::trigger('admin_nopermission', $this);
            $this->error(__('You have no permission'), '');
        }
        $typeArr = \app\admin\model\cms\Page::distinct('type')->column('type');
        $this->view->assign('typeList', $typeArr);
        $this->assignconfig('typeList', $typeArr);
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $list = collection($list)->toArray();
            $this->result('', $list, $total);
        }
        return $this->view->fetch();
    }

    /**
     * 动态下拉选择类型
     * @internal
     */
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
            $typeArr = \app\admin\model\cms\Page::column('type');
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
     * 检测元素是否可用
     * @internal
     */
    public function check_element_available()
    {
        $id = $this->request->request('id');
        $name = $this->request->request('name');
        $value = $this->request->request('value');
        $name = substr($name, 4, -1);
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        if ($id) {
            $this->model->where('id', '<>', $id);
        }
        $exist = $this->model->where($name, $value)->find();
        if ($exist) {
            $this->error(__('The data already exist'));
        } else {
            $this->success();
        }
    }
}
