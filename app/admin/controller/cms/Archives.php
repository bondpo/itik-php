<?php

namespace app\admin\controller\cms;

use app\admin\model\cms\Channel;
use app\admin\model\cms\ChannelAdmin;
use app\admin\model\cms\Modelx;
use app\common\controller\Backend;
use app\common\model\User;
use app\common\library\cms\Tree;
use think\facade\Db;
use think\db\Query;
use think\facade\Event;

/**
 * 内容表
 *
 * @icon fa fa-file-text-o
 */
class Archives extends Backend
{
    use \app\common\library\cms\traits\RecycleBin;


    /**
     * Archives模型对象
     */
    protected $model = null;
    protected $noNeedRight = ['get_fields_html', 'check_element_available', 'suggestion', 'copy', 'special', 'tags', 'move', 'flag', 'get_channel_model_info'];
    protected $channelIds = [];
    protected $isSuperAdmin = false;
    protected $searchFields = 'id,title';

    protected $modelValidate = true;
    protected $modelSceneValidate = true;

    /**
     * 删除事件是否已注册
     * @var bool
     */
    protected static $deleteEventRegistered = false;

    /**
     * 销毁事件是否已注册
     * @var bool
     */
    protected static $destroyEventRegistered = false;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\cms\Archives;
        $config = get_addon_config('cms');
        if ($config['archivesdatalimit'] != 'all') {
            $this->dataLimit = $config['archivesdatalimit'];
        }

        //复制/加入专题/修改标签均检测编辑权限
        if (in_array($this->request->action(), ['copy', 'special', 'tags', 'move', 'flag']) && !$this->auth->check('cms/archives/edit')) {
            Event::trigger('admin_nopermission', $this);
            $this->error(__('You have no permission'), '');
        }

        //是否超级管理员
        $this->isSuperAdmin = $this->auth->isSuperAdmin();
        $channelList = [];
        $disabledIds = [];
        $all = collection(Channel::order("weigh desc,id desc")->select())->toArray();

        //允许的栏目
        $this->channelIds = $this->isSuperAdmin || !$config['channelallocate'] ? Channel::column('id') : ChannelAdmin::getAdminChanneIds();
        $parentChannelIds = Channel::where('id', 'in', $this->channelIds)->column('parent_id');
        $parentChannelIds = array_unique($parentChannelIds);
        $parentChannelList = \think\facade\Db::name('cms_channel')->where('id', 'in', $parentChannelIds)->where('parent_id', '<>', 0)->field('id,parent_id,name')->select();
        $tree = Tree::instance()->init($all, 'parent_id');
        foreach ($parentChannelList as $index => $channel) {
            $parentChannelIds = array_merge($parentChannelIds, $tree->getParentsIds($channel['parent_id'], true));
        }
        $this->channelIds = array_merge($parentChannelIds, $this->channelIds);
        foreach ($all as $k => $v) {
            $state = ['opened' => true];
            if ($v['type'] == 'link' || (!($config['channelcontribute'] ?? true) && $v['type'] == 'channel' ? true : false)) {
                $disabledIds[] = $v['id'];
            }
            if ($v['type'] == 'link') {
                $state['checkbox_disabled'] = true;
            }
            if (!$this->isSuperAdmin) {
                if (!in_array($v['id'], $parentChannelIds) && !in_array($v['id'], $this->channelIds)) {
                    unset($all[$k]);
                    continue;
                }
            }
            $channelList[] = [
                'id'       => $v['id'],
                'parent'   => $v['parent_id'] ?: '0',
                'text'     => __($v['name']),
                'type'     => $v['type'],
                'expand'   => true,
                'disabled' => in_array($v['id'], $disabledIds),
                'state'    => $state
            ];
        }
        $tree = Tree::instance()->init($all, 'parent_id');
        $channelOptions = $tree->getTree(0, "<option model='@model_id' value=@id @selected @disabled>@spacer@name</option>", '', $disabledIds);
        $secondChannelOptions = $tree->getTree(0, "<option model='@model_id' value=@id disabled>@spacer@name</option>", '', $disabledIds);
        $this->view->assign('channelOptions', $channelOptions);
        $this->view->assign('secondChannelOptions', $secondChannelOptions);
        $this->assignconfig('channelList', $channelList);
        $this->assignconfig('spiderRecord', intval($config['spiderrecord'] ?? 0));

        $this->assignconfig("flagList", $this->model->getFlagList());
        $this->view->assign("flagList", $this->model->getFlagList());
        $this->view->assign("statusList", $this->model->getStatusList());

        $this->assignconfig('cms', ['archiveseditmode' => $config['archiveseditmode']]);
    }

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            $this->relationSearch = true;
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();
            if (!$this->auth->isSuperAdmin()) {
                $this->model->where('channel_id', 'in', $this->channelIds);
            }
            $fields = "archives.*";
            $model_id = $this->request->request("model_id");
            if ($model_id) {
                $model = Modelx::find($model_id);
                if ($model) {
                    $this->model->join($model['table'] . ' addon', 'addon.id=archives.id', 'LEFT');
                    $fields .= ",addon.*";
                }
            }
            $total = $this->model
                ->with('Channel')
                ->alias('archives')
                ->where($where)
                ->order($sort, $order)
                ->count();
            if (!$this->auth->isSuperAdmin()) {
                $this->model->where('channel_id', 'in', $this->channelIds);
            }

            $model_id = $this->request->request("model_id");
            if ($model_id) {
                $model = Modelx::find($model_id);
                if ($model) {
                    $this->model->join($model['table'] . ' addon', 'addon.id=archives.id', 'LEFT');
                }
            }
            $list = $this->model
                ->with(['Channel'])
                ->alias('archives')
                ->field($fields)
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            \app\admin\model\cms\SpiderLog::render($list, 'archives');
            $this->result('', $list, $total);
        }

        //主表字段
        $fields = \app\admin\model\cms\Fields::getJsFieldsList('archives');
        $this->assignconfig('fields', $fields);

        $modelList = \app\admin\model\cms\Modelx::select();
        $specialList = \app\admin\model\cms\Special::where('status', 'normal')->select();
        $this->view->assign('modelList', $modelList);
        $this->view->assign('specialList', $specialList);
        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
        $values = [];
        $fields = \app\common\library\cms\Service::getCustomFields('archives', 0, $values);

        $this->view->assign('fields', $fields);
        $this->view->assign('values', $values);
        return parent::add();
    }

    /**
     * 编辑
     *
     * @param mixed $ids
     */
    public function edit($ids = null)
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
        if (!$this->isSuperAdmin && !in_array($row['channel_id'], $this->channelIds)) {
            $this->error(__('You have no permission'));
        }
        if ($this->request->isPost()) {
            return parent::edit($ids);
        }
        $channel = Channel::find($row['channel_id']);
        if (!$channel) {
            //栏目未找到仍然可以编辑文档
            //$this->error(__('No specified channel found'));
        }
        $model = \app\admin\model\cms\Modelx::find($channel['model_id'] ?? $row['model_id']);
        if (!$model) {
            $this->error(__('No specified model found'));
        }
        $addon = db($model['table'])->where('id', $row['id'])->find();
        if ($addon) {
            $row->setRowData($addon);
        }

        $disabledIds = [];
        $all = Db::name("cms_channel")->order("weigh DESC,id DESC")->select();
        foreach ($all as $k => $v) {
            if ($v['type'] == 'link' || ($channel && $v['model_id'] != $channel['model_id']) || ($model['id'] != $v['model_id'])) {
                $disabledIds[] = $v['id'];
            }
        }
        $disabledIds = array_diff($disabledIds, [$row['channel_id']]);
        $tree = Tree::instance()->init($all, 'parent_id');
        $channelOptions = $tree->getTree(0, "<option model='@model_id' value=@id @selected @disabled>@spacer@name</option>", $row['channel_id'], $disabledIds);
        $this->view->assign('channelOptions', $channelOptions);
        $this->view->assign("row", $row);
        // 自定义字段来自内容所属模型
        $fields = [];
        $contentModel = $channel ? Modelx::find($channel['model_id']) : null;
        if ($contentModel) {
            $fields = \app\common\library\cms\Service::getCustomFields('model', $contentModel['id'], $row->toArray());
        }

        $this->view->assign('fields', $fields);
        $this->view->assign('values', $row->toArray());
        return $this->view->fetch();
    }

    /**
     * 删除
     * @param mixed $ids
     */
    public function del($ids = "")
    {
        // 事件回调只需注册一次，避免常驻进程下重复绑定
        if (!static::$deleteEventRegistered) {
            static::$deleteEventRegistered = true;
            \app\admin\model\cms\Archives::afterDelete(function ($row) {
                Channel::where('id', $row['channel_id'])->where('items', '>', 0)->setDec('items');
            });
        }
        parent::del($ids);
    }

    /**
     * 销毁
     * @param string $ids
     */
    public function destroy($ids = "")
    {
        $ids = $ids ?: $this->request->param('ids');
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', ['ids']));
        }
        if (!static::$destroyEventRegistered) {
            static::$destroyEventRegistered = true;
            \app\admin\model\cms\Archives::afterDelete(function ($row) {
                //删除副表
                $channel = Channel::find($row->channel_id);
                if ($channel) {
                    $model = Modelx::find($channel['model_id']);
                    if ($model) {
                        db($model['table'])->where("id", $row['id'])->delete();
                    }
                }
            });
        }
        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($this->model->onlyTrashed()->where($this->model->getPk(), 'in', $ids)->select() as $item) {
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
     * 还原
     * @param mixed $ids
     */
    public function restore($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $ids = $ids ?: $this->request->param('ids');
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', ['ids']));
        }
        $pk = $this->model->getPk();
        $config = get_addon_config('cms');
        $list = $this->model->onlyTrashed()->select();
        if ($list) {
            $ids = [];
            $refreshIds = [];
            foreach ($list as $index => $item) {
                if ($item['status'] == 'normal') {
                    User::score($config['score']['postarchives'], $item['user_id'], '发布文章');
                }
                $ids[] = $item['id'];
                $refreshIds[] = $item['channel_id'];
            }
            $refreshIds = array_filter(array_unique($refreshIds));
            // think-orm 4 的模型 restore 依赖 softDelete 默认值，这里直接清空删除标记
            \think\facade\Db::name('cms_archives')->where($pk, 'in', $ids)->update(['deletetime' => null]);
            Channel::refreshItems($refreshIds);
            $this->success();
        }
        $this->error(__('No rows were updated'));
    }

    /**
     * 移动
     * @param string $ids
     */
    public function move($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        if ($ids) {
            if (!$this->request->isPost()) {
                $this->error(__("Invalid parameters"));
            }
            $channel_id = $this->request->post('channel_id');
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }
            $this->model->where($pk, 'in', $ids);
            $channel = Channel::find($channel_id);
            if ($channel && $channel['type'] === 'list') {
                $channelNums = \app\admin\model\cms\Archives::with('channel')
                    ->where('archives.' . $pk, 'in', $ids)
                    ->where('channel_id', '<>', $channel['id'])
                    ->field('channel_id,COUNT(*) AS nums')
                    ->group('channel_id')
                    ->select();
                $result = $this->model
                    ->where('model_id', '=', $channel['model_id'])
                    ->where('channel_id', '<>', $channel['id'])
                    ->update(['channel_id' => $channel_id]);
                if ($result) {
                    $this->success();
                } else {
                    $this->error(__('No rows were updated'));
                }
            } else {
                $this->error(__('No rows were updated'));
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }

    /**
     * 复制选择行
     * @param string $ids
     */
    public function copy($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        if ($ids) {
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }
            $archivesList = $this->model->where('id', 'in', $ids)->select();
            foreach ($archivesList as $index => $item) {
                try {
                    $model = Modelx::find($item['model_id']);
                    $addon = \think\facade\Db::name($model['table'])->find($item['id']);
                    $data = $item->toArray();
                    $data = array_merge($data, $addon ?? []);
                    $data['title'] = $data['title'] . "_copy";
                    $data['status'] = 'hidden';
                    unset($data['id']);
                    \app\admin\model\cms\Archives::create($data, true);
                } catch (\Exception $e) {
                    //
                }
            }
            $this->success();
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }

    /**
     * 加入专题
     * @param string $ids
     */
    public function special($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        if ($ids) {
            $special_id = $this->request->post('special_id');
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }
            $special = \app\admin\model\cms\Special::find($special_id);
            if ($special) {
                $archivesList = $this->model->where($pk, 'in', $ids)->select();
                foreach ($archivesList as $index => $item) {
                    $special_ids = explode(',', $item['special_ids']);
                    if (!in_array($special['id'], $special_ids)) {
                        $special_ids[] = $special['id'];
                        $item->save(['special_ids' => implode(',', array_unique(array_filter($special_ids)))]);
                    }
                }
                $this->success();
            } else {
                $this->error(__('No rows were updated'));
            }
        }
        $this->error(__('Please select at least one row'));
    }

    /**
     * 加入标签
     * @param string $ids
     */
    public function tags($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        if ($ids) {
            $tags = $this->request->post('tags');
            $newTagsArr = array_filter(explode(',', $tags));
            if ($newTagsArr) {
                $pk = $this->model->getPk();
                $adminIds = $this->getDataLimitAdminIds();
                if (is_array($adminIds)) {
                    $this->model->where($this->dataLimitField, 'in', $adminIds);
                }
                $archivesList = $this->model->where($pk, 'in', $ids)->select();
                foreach ($archivesList as $index => $item) {
                    $tagsArr = explode(',', $item['tags']);
                    $tagsArr = array_merge($tagsArr, $newTagsArr);
                    $item->save(['tags' => implode(',', array_unique(array_filter($tagsArr)))]);
                }
                $this->success();
            } else {
                $this->error(__('标签数据不能为空'));
            }
        }
        $this->error(__('Please select at least one row'));
    }

    /**
     * 修改标志
     * @param string $ids
     */
    public function flag($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        if ($ids) {
            $type = $this->request->post('type');
            $flag = $this->request->post('flag');
            $changeFlagArr = array_filter(explode(',', $flag));
            if ($changeFlagArr) {
                $pk = $this->model->getPk();
                $adminIds = $this->getDataLimitAdminIds();
                if (is_array($adminIds)) {
                    $this->model->where($this->dataLimitField, 'in', $adminIds);
                }
                $archivesList = $this->model->where($pk, 'in', $ids)->select();
                foreach ($archivesList as $index => $item) {
                    $flagArr = explode(',', $item['flag']);
                    if ($type == 'add') {
                        $flagArr = array_merge($flagArr, $changeFlagArr);
                    } else {
                        $flagArr = array_diff($flagArr, $changeFlagArr);
                    }
                    $item->save(['flag' => implode(',', array_unique(array_filter($flagArr)))]);
                }
                $this->success();
            } else {
                $this->error(__('标志数据不能为空'));
            }
        }
        $this->error(__('Please select at least one row'));
    }

    /**
     * 获取栏目列表
     * @internal
     */
    public function get_fields_html()
    {
        $channel_id = $this->request->post('channel_id');
        $archives_id = $this->request->post('archives_id');
        $channel = Channel::with('model')->find($channel_id);
        if ($channel) {
            $model_id = $channel['model_id'];
            $values = [];
            if ($archives_id) {
                $values = db($channel['model']['table'])->where('id', $archives_id)->find();

                //优先从栏目获取模型ID，再从文档获取
                $archives = \app\admin\model\cms\Archives::find($archives_id);
                $model_id = $archives ? $archives['model_id'] : $model_id;
            }

            $fields = \app\common\library\cms\Service::getCustomFields('model', $model_id, $values);

            $model = Modelx::find($model_id);

            $setting = $model['setting'];
            $publishfields = $setting['publishfields'] ?? [];
            $titlelist = $setting['titlelist'] ?? [];

            $this->view->assign('channel', $channel);
            $this->view->assign('fields', $fields);
            $this->view->assign('values', $values);
            $this->success('', null, ['html' => $this->fetch('cms/common/fields'), 'publishfields' => $publishfields, 'titlelist' => $titlelist]);
        } else {
            $this->error(__('Please select channel'));
        }
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

    /**
     * 搜索建议
     * @internal
     */
    public function suggestion()
    {
        $config = get_addon_config('cms');
        $q = trim($this->request->request("q"));
        $id = trim($this->request->request("id/d"));
        $list = [];
        if ($config['searchtype'] == 'xunsearch') {
            // 全文检索组件（xunsearch）未安装，回退为标题模糊匹配
            $result = ['docs' => \think\facade\Db::name('cms_archives')->where('title', 'like', "%{$q}%")->limit(10)->select()->toArray()];
        } else {
            $result = $this->model->where("title|keywords|description", "like", "%{$q}%")->where('id', '<>', $id)->limit(10)->order("id", "desc")->select();
            foreach ($result as $index => $item) {
                $item['image'] = $item['image'] ? $item['image'] : '/assets/addons/cms/img/noimage.png';
                $list[] = ['id' => $item['id'], 'url' => $item['fullurl'], 'image' => cdnurl($item['image']), 'title' => $item['title'], 'create_date' => datetime($item['createtime']), 'status' => $item['status'], 'status_text' => $item['status_text'], 'deletetime' => $item['deletetime']];
            }
        }
        return json($list);
    }

    /**
     * 获取栏目字段
     */
    public function get_channel_model_info()
    {
        $fields = [];
        $model_id = 0;
        $ids = $this->request->post("ids");
        if ($ids) {
            $model_ids = Channel::where('id', 'in', $ids)->column('model_id');
            $model_ids = array_unique($model_ids);
            if (count($model_ids) === 1) {
                $model_id = reset($model_ids);
                $model = Modelx::find($model_id);
                if (!$model) {
                    $this->error('未找到对应模型');
                }
                $fields = \app\admin\model\cms\Fields::getJsFieldsList('model', $model['id']);
            }
        }
        $this->success("", null, ['fields' => $fields, 'model_id' => $model_id]);
    }
}
