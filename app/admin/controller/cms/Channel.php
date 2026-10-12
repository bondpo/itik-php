<?php

namespace app\admin\controller\cms;

use app\admin\model\Admin;
use app\admin\model\AdminGroupAccess;
use app\admin\model\cms\ChannelAdmin;
use app\common\controller\Backend;
use app\admin\model\cms\Channel as ChannelModel;
use app\common\library\cms\Tree;
use think\Exception;

/**
 * 栏目管理
 *
 * @icon fa fa-list
 */
class Channel extends Backend
{
    protected $channelList = [];
    protected $multiFields = ['weigh', 'status'];

    /**
     * Channel模型对象
     */
    protected $model = null;
    protected $noNeedRight = ['check_element_available'];

    protected $modelValidate = true;
    protected $modelSceneValidate = true;

    /**
     * @var Tree
     */
    protected $tree = null;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new ChannelModel();

        $this->tree = Tree::instance();
        $this->tree->init(collection($this->model->order('weigh desc,id desc')->select())->toArray(), 'parent_id');
        $this->channelList = $this->tree->getTreeList($this->tree->getTreeArray(0), 'name');

        $this->view->assign("channelList", $this->channelList);
        $this->view->assign("statusList", ChannelModel::getStatusList());
    }

    /**
     * 查看
     */
    public function index()
    {
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            $search = $this->request->request("search");
            $list = [];
            if ($search) {
                foreach ($this->channelList as $v) {
                    if (stripos($v['name'], $search) !== false || stripos($v['diyname'] ?? '', $search) !== false) {
                        $list[] = $v;
                    }
                }
            } else {
                $list = $this->channelList;
            }
            $list = array_values($list);
            foreach ($list as &$v) {
                $v['pid'] = $v['parent_id'];
            }
            $total = count($list);
            $this->result('', $list, $total);
        }

        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if (!$params) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            $params['type'] = $params['type'] ?? 'list';
            if ($this->dataLimit && $this->dataLimitFieldAutoFill) {
                $params[$this->dataLimitField] = $this->auth->id;
            }

            try {
                if ($this->modelValidate) {
                    $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.add' : $name) : $this->modelValidate;
                    $this->model->validateFailException()->validateData($validate);
                }

                $nameArr = array_filter(explode("\n", str_replace("\r\n", "\n", (string)($params['name'] ?? ''))));
                if (count($nameArr) > 1) {
                    foreach ($nameArr as $item) {
                        $itemArr = array_filter(explode('|', $item));
                        $rowParams = $params;
                        $rowParams['name'] = trim($itemArr[0]);
                        $rowParams['diyname'] = isset($itemArr[1]) ? trim($itemArr[1]) : '';
                        $result = (new ChannelModel())->allowField(true)->save($rowParams);
                        if ($result === false) {
                            throw new \RuntimeException($this->model->getError() ?: __('No rows were added'));
                        }
                    }
                } else {
                    $result = $this->model->allowField(true)->save($params);
                    if ($result === false) {
                        throw new \RuntimeException($this->model->getError() ?: __('No rows were added'));
                    }
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }

            $this->success();
        }

        $channelOptions = $this->tree->getTree(0, "<option value=@id @selected>@spacer@name</option>", 0, []);
        $this->view->assign('channelOptions', $channelOptions);
        return $this->view->fetch();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        $channel = ChannelModel::find($ids);
        if (!$channel) {
            $this->error(__('No Results were found'));
        }

        $childrenIds = $this->tree->getChildrenIds($channel['id'], true);
        $channelOptions = $this->tree->getTree(0, "<option value=@id @selected @disabled>@spacer@name</option>", $channel['parent_id'], $childrenIds);

        $this->view->assign('channelOptions', $channelOptions);
        $this->view->assign('childrenIds', $childrenIds);
        return parent::edit($ids);
    }

    /**
     * 栏目授权
     */
    public function admin()
    {
        $act = $this->request->param('act');
        $ids = $this->request->param('ids');
        if ($act && $ids) {
            if (!$this->request->isPost()) {
                $this->error(__("Invalid parameters"));
            }
            if ($act == 'remove') {
                ChannelAdmin::where('admin_id', $ids)->delete();
                $this->success('删除成功！');
            } elseif ($act == 'authorization') {
                $selected = ChannelAdmin::getAdminChanneIds($ids);
                $all = collection(ChannelModel::order("weigh desc,id desc")->select())->toArray();
                $channelList = [];
                foreach ($all as $v) {
                    $state = ['opened' => true];
                    $state['selected'] = in_array($v['id'], $selected);
                    $channelList[] = [
                        'id'     => $v['id'],
                        'parent' => $v['parent_id'] ? $v['parent_id'] : '#',
                        'text'   => __($v['name']),
                        'type'   => $v['type'],
                        'state'  => $state
                    ];
                }
                $this->success('成功', '', $channelList);
            } elseif ($act == 'save') {
                \think\facade\Db::startTrans();
                try {
                    ChannelAdmin::where('admin_id', $ids)->delete();
                    $channelIds = explode(",", (string)$this->request->post("channel_ids", $this->request->post("ids", "")));
                    $channelIds = array_filter($channelIds);
                    if ($channelIds) {
                        $listChannelIds = ChannelModel::where('type', 'list')->column('id');
                        $channelIds = array_intersect($channelIds, $listChannelIds);
                        $data = [];
                        foreach ($channelIds as $item) {
                            $data[] = ['admin_id' => $ids, 'channel_id' => $item];
                        }
                        if ($data) {
                            (new ChannelAdmin())->saveAll($data, true);
                        }
                    }
                    \think\facade\Db::commit();
                } catch (\Throwable $e) {
                    \think\facade\Db::rollback();
                    $this->error($e->getMessage());
                }
                $this->success("保存成功!");
            }
        }

        if ($this->request->isAjax()) {
            $list = \think\facade\Db::name("cms_channel_admin")
                ->group("admin_id")
                ->field("COUNT(*) as channels,admin_id")
                ->select();
            $adminChannelList = [];
            foreach ($list as $item) {
                $adminChannelList[$item['admin_id']] = $item['channels'];
            }

            $superAdminIds = AdminGroupAccess::where('group_id', 1)->column('uid');

            $adminList = Admin::order('id', 'desc')->field('id,username,nickname')->select();
            foreach ($adminList as $item) {
                $item->channels = $adminChannelList[$item['id']] ?? 0;
                $item->superadmin = in_array($item['id'], $superAdminIds);
            }
            $total = count($adminList);
            $this->result('', $adminList, $total);
        }
        $config = get_cms_config();
        $this->view->assign("isChannelAllocate", $config['channelallocate'] ?? 0);
        return $this->view->fetch();
    }

    /**
     * Selectpage搜索
     *
     * @internal
     */
    public function selectpage()
    {
        return parent::selectpage();
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
        if ($name == 'diyname') {
            if ($id) {
                $this->model->where('id', '<>', $id);
            }
            $exist = $this->model->where($name, $value)->find();
            if ($exist) {
                $this->error(__('The data already exist'));
            } else {
                $this->success();
            }
        } elseif ($name == 'name') {
            $nameArr = array_filter(explode("\n", str_replace("\r\n", "\n", $value)));
            if (count($nameArr) > 1) {
                foreach ($nameArr as $item) {
                    $itemArr = array_filter(explode('|', $item));
                    if (!isset($itemArr[1])) {
                        $this->error('格式:分类名称|自定义名称');
                    }
                    $exist = ChannelModel::getByDiyname($itemArr[1]);
                    if ($exist) {
                        $this->error('自定义名称[' . $itemArr[1] . ']已经存在');
                    }
                }
                $this->success();
            } else {
                $this->success();
            }
        }
    }
}
