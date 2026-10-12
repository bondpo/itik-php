<?php

namespace app\admin\controller\cms;

use app\common\controller\Backend;
use app\admin\model\cms\Tag as TagModel;
use app\admin\model\cms\SpiderLog;

/**
 * 标签管理
 *
 * @icon fa fa-tags
 */
class Tag extends Backend
{
    /**
     * Tag模型对象
     * @var TagModel
     */
    protected $model = null;
    protected $searchFields = 'id,name,seotitle';
    protected $noNeedRight = ['selectpage', 'autocomplete'];
    protected $multiFields = 'autolink,status';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new TagModel();
    }

    /**
     * 查看
     */
    public function index()
    {
        if (!$this->isAjax()) {
            return $this->view->fetch();
        }

        [$where, $sort, $order, $offset, $limit] = $this->buildparams();

        $list = $this->model
            ->where($where)
            ->order($sort, $order)
            ->paginate($limit);

        SpiderLog::render($list, 'tag');

        $this->result('', $list->items(), $list->total());
    }

    /**
     * 标签选择器，允许在选择时录入不存在的标签
     */
    public function selectpage()
    {
        $word = array_filter(array_unique((array)$this->request->request('q_word/a')));
        $where = [];
        if ($word) {
            $where[] = ['name', 'like', '%' . reset($word) . '%'];
        }

        $list = [];
        foreach ($this->model->where($where)->limit(50)->select() as $item) {
            $list[] = ['id' => $item['id'], 'name' => $item['name']];
        }

        // 未匹配到的关键词作为新标签返回，保存内容时由模型自动创建
        $names = array_map('strtolower', array_column($list, 'name'));
        foreach ($word as $value) {
            if (!in_array(strtolower($value), $names)) {
                array_unshift($list, ['id' => $value, 'name' => $value]);
            }
        }

        $this->success('ok', '', ['list' => $list, 'total' => count($list)]);
    }

    /**
     * 标签名称自动补全
     */
    public function autocomplete()
    {
        $q = $this->request->request('q');
        return json(TagModel::where('name', 'like', '%' . $q . '%')->column('name'));
    }
}
