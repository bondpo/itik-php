<?php

namespace app\admin\controller\cms;

use app\admin\model\cms\Channel;
use app\admin\model\cms\ChannelAdmin;
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
    protected $noNeedRight = ['check_element_available', 'suggestion', 'copy', 'tags', 'move', 'flag'];
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
        $config = get_cms_config();
        if (!empty($config['archivesdatalimit']) && $config['archivesdatalimit'] != 'all') {
            $this->dataLimit = $config['archivesdatalimit'];
        }

        // 复制/修改标签均检测编辑权限
        if (in_array($this->request->action(), ['copy', 'tags', 'move', 'flag']) && !$this->auth->check('cms/archives/edit')) {
            Event::trigger('admin_nopermission', $this);
            $this->error(__('You have no permission'), '');
        }

        // 是否超级管理员
        $this->isSuperAdmin = $this->auth->isSuperAdmin();
        $channelList = [];
        $disabledIds = [];
        $all = collection(Channel::order("weigh desc,id desc")->select())->toArray();

        // 允许的栏目
        $this->channelIds = $this->isSuperAdmin || empty($config['channelallocate']) ? Channel::column('id') : ChannelAdmin::getAdminChanneIds();
        $parentChannelIds = Channel::where('id', 'in', $this->channelIds)->column('parent_id');
        $parentChannelIds = array_unique($parentChannelIds);
        $parentChannelList = Db::name('cms_channel')->where('id', 'in', $parentChannelIds)->where('parent_id', '<>', 0)->field('id,parent_id,name')->select();
        $tree = Tree::instance()->init($all, 'parent_id');
        foreach ($parentChannelList as $channel) {
            $parentChannelIds = array_merge($parentChannelIds, $tree->getParentsIds($channel['parent_id'], true));
        }
        $this->channelIds = array_merge($parentChannelIds, $this->channelIds);
        foreach ($all as $k => $v) {
            $state = ['opened' => true];
            if ($v['type'] == 'link') {
                $disabledIds[] = $v['id'];
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
        $channelOptions = $tree->getTree(0, "<option value=@id @selected @disabled>@spacer@name</option>", '', $disabledIds);
        $this->view->assign('channelOptions', $channelOptions);
        $this->view->assign('channelList', $channelList);

        $this->view->assign("statusList", $this->model->getStatusList());
        $this->view->assign("flagList", $this->model->getFlagList());
    }

    /**
     * 查看
     */
    public function index()
    {
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            $this->relationSearch = true;
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();
            if (!$this->auth->isSuperAdmin()) {
                $this->model->where('channel_id', 'in', $this->channelIds);
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

            $list = $this->model
                ->with(['Channel'])
                ->alias('archives')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $this->result('', $list, $total);
        }

        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
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
        if (is_array($adminIds) && !in_array($row[$this->dataLimitField], $adminIds)) {
            $this->error(__('You have no permission'));
        }
        if (!$this->isSuperAdmin && !in_array($row['channel_id'], $this->channelIds)) {
            $this->error(__('You have no permission'));
        }
        if ($this->request->isPost()) {
            return parent::edit($ids);
        }

        $disabledIds = [];
        $all = Db::name("cms_channel")->order("weigh DESC,id DESC")->select();
        foreach ($all as $v) {
            if ($v['type'] == 'link') {
                $disabledIds[] = $v['id'];
            }
        }
        $tree = Tree::instance()->init($all, 'parent_id');
        $channelOptions = $tree->getTree(0, "<option value=@id @selected @disabled>@spacer@name</option>", $row['channel_id'], $disabledIds);
        $this->view->assign('channelOptions', $channelOptions);
        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 删除
     * @param mixed $ids
     */
    public function del($ids = "")
    {
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
        $config = get_cms_config();
        $list = $this->model->onlyTrashed()->select();
        if ($list) {
            $ids = [];
            $refreshIds = [];
            foreach ($list as $item) {
                if ($item['status'] == 'normal' && !empty($config['score']['postarchives'])) {
                    User::score($config['score']['postarchives'], $item['user_id'], '发布文章');
                }
                $ids[] = $item['id'];
                $refreshIds[] = $item['channel_id'];
            }
            $refreshIds = array_filter(array_unique($refreshIds));
            Db::name('cms_archives')->where($pk, 'in', $ids)->update(['deletetime' => null]);
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
        $ids = $ids ?: $this->request->param('ids');
        if ($ids) {
            $channel_id = $this->request->post('channel_id');
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }
            $this->model->where($pk, 'in', $ids);
            $channel = Channel::find($channel_id);
            if ($channel) {
                $result = $this->model
                    ->where('channel_id', '<>', $channel['id'])
                    ->update(['channel_id' => $channel_id]);
                if ($result) {
                    $this->success();
                } else {
                    $this->error(__('No rows were updated'));
                }
            } else {
                $this->error(__('未找到目标栏目'));
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
        $ids = $ids ?: $this->request->param('ids');
        if ($ids) {
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }
            $archivesList = $this->model->where('id', 'in', $ids)->select();
            foreach ($archivesList as $item) {
                try {
                    $data = $item->toArray();
                    $data['title'] = $data['title'] . "_copy";
                    $data['status'] = 'hidden';
                    unset($data['id']);
                    \app\admin\model\cms\Archives::create($data, true);
                } catch (\Throwable $e) {
                    //
                }
            }
            $this->success();
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
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
        $ids = $ids ?: $this->request->param('ids');
        if ($ids) {
            $tags = $this->request->post('tags');
            $newTagsArr = array_filter(explode(',', (string)$tags));
            if ($newTagsArr) {
                $pk = $this->model->getPk();
                $adminIds = $this->getDataLimitAdminIds();
                if (is_array($adminIds)) {
                    $this->model->where($this->dataLimitField, 'in', $adminIds);
                }
                $archivesList = $this->model->where($pk, 'in', $ids)->select();
                foreach ($archivesList as $item) {
                    $tagsArr = explode(',', (string)$item['tags']);
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
        $ids = $ids ?: $this->request->param('ids');
        if ($ids) {
            $type = $this->request->post('type');
            $flag = $this->request->post('flag');
            $changeFlagArr = array_filter(explode(',', (string)$flag));
            if ($changeFlagArr) {
                $pk = $this->model->getPk();
                $adminIds = $this->getDataLimitAdminIds();
                if (is_array($adminIds)) {
                    $this->model->where($this->dataLimitField, 'in', $adminIds);
                }
                $archivesList = $this->model->where($pk, 'in', $ids)->select();
                foreach ($archivesList as $item) {
                    $flagArr = explode(',', (string)$item['flag']);
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
        $q = trim((string)$this->request->request("q"));
        $id = trim((string)$this->request->request("id/d"));
        $list = [];
        $result = $this->model->where("title|keywords|description", "like", "%{$q}%")->where('id', '<>', $id)->limit(10)->order("id", "desc")->select();
        foreach ($result as $item) {
            $image = $item['image'] ?: '/assets/addons/cms/img/noimage.png';
            $list[] = [
                'id'          => $item['id'],
                'url'         => $item['fullurl'],
                'image'       => cdnurl($image),
                'title'       => $item['title'],
                'create_date' => datetime($item['createtime']),
                'status'      => $item['status'],
                'status_text' => $item['status_text'],
                'deletetime'  => $item['deletetime']
            ];
        }
        return json($list);
    }
}
