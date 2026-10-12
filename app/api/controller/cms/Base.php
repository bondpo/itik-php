<?php

// +----------------------------------------------------------------------
// | CMS API —— 基类（前后端分离，仅返回 JSON，不含任何模板渲染）
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;
use think\facade\Config;
use app\api\controller\v1\Base as ApiBase;

/**
 * CMS 前台展示 API 基类
 *
 * 设计要点：
 * 1. 前后端分离：本层只输出 JSON，不加载 view_path / taglib / 模板替换字符串；
 * 2. 数据逻辑复用 app\common\model\cms\* 与 app\common\library\cms\Service；
 * 3. 只读接口为主，写操作（评论/表单/订单/会员）走各自控制器。
 */
class Base extends ApiBase
{
    /** 默认每页条数 */
    protected const DEFAULT_LIMIT = 20;
    protected const MAX_LIMIT = 50;

    /** CMS 配置（缓存于请求内） */
    protected $cmsConfig = [];

    public function initialize()
    {
        // 注意：这里不调用 parent::initialize() 中的 Api 鉴权逻辑前先准备 CMS 配置
        parent::initialize();
        $this->cmsConfig = (array)get_addon_config('cms');

        // 前台模型依赖的 CMS 运行配置（原由渲染型 Base 设置，此处迁移过来）
        Config::set([
            'indexurl' => $this->request->root() . '/api/cms/index',
        ], 'cms');
        Config::set(['type' => '\\app\\common\\library\\cms\\Bootstrap'], 'paginate');

        $this->request->filter('trim,strip_tags,htmlspecialchars');
    }

    /**
     * CMS 配置（供控制器读取，避免重复获取）
     */
    protected function cmsConfig(string $key = '', $default = null)
    {
        if ($key === '') {
            return $this->cmsConfig;
        }
        return $this->cmsConfig[$key] ?? $default;
    }

    /**
     * 统一的列表分页参数
     * @return array{0:int,1:int}
     */
    protected function cmsPageParams(): array
    {
        $page  = max(1, (int)$this->request->param('page', 1));
        $limit = (int)$this->request->param('limit', self::DEFAULT_LIMIT);
        $limit = $limit > 0 ? min($limit, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        return [$page, $limit];
    }

    /**
     * CMS 列表排序（白名单，防注入）
     * @return array{0:string,1:string}
     */
    protected function cmsOrderParams(array $allow = [], string $default = 'weigh'): array
    {
        $allow = $allow ?: ['id', 'weigh', 'publishtime', 'createtime', 'updatetime', 'views', 'likes', 'comments', 'diyname'];
        $orderby  = (string)$this->request->param('orderby', $default);
        $orderway = strtolower((string)$this->request->param('orderway', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($orderby, $allow, true)) {
            $orderby = $default;
        }
        return [$orderby, $orderway];
    }

    /** 表字段缓存（避免重复 SHOW COLUMNS） */
    protected static array $tableFieldsCache = [];

    /**
     * 取表实际字段（带前缀）
     */
    protected function tableFields(string $table): array
    {
        if (isset(self::$tableFieldsCache[$table])) {
            return self::$tableFieldsCache[$table];
        }
        $full = config('database.connections.mysql.prefix') . $table;
        $fields = [];
        try {
            foreach (Db::query("SHOW COLUMNS FROM `{$full}`") as $col) {
                $fields[] = $col['Field'];
            }
        } catch (\Throwable $e) {
            $fields = [];
        }
        return self::$tableFieldsCache[$table] = $fields;
    }

    /**
     * 字段名与真实表结构求交集（自适应，避免硬编码字段名导致 SQL 报错）
     */
    protected function onlyExisting(string $table, array $fields): array
    {
        $exists = $this->tableFields($table);
        if (!$exists) {
            return $fields;
        }
        return array_values(array_intersect($fields, $exists));
    }

    /**
     * 逗号串形式的字段白名单（用于 field()）
     */
    protected function fieldString(string $table, array $fields): string
    {
        $list = $this->onlyExisting($table, $fields);
        return $list ? implode(',', $list) : '*';
    }

    /**
     * 内容输出白名单过滤：避免把内部字段（模板、路径等）泄露给前端
     * 注意：不存在的字段会被自动忽略
     */
    protected function pick(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $out[$f] = $row[$f];
            }
        }
        return $out;
    }

    /**
     * 批量白名单过滤
     */
    protected function pickAll($rows, array $fields): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->pick((array)$row, $fields);
        }
        return $out;
    }

    /**
     * 栏目树（仅输出 id/name/type 等展示字段，不含模板字段）
     */
    protected function channelTree(int $parentId = 0, string $type = ''): array
    {
        $query = Db::name('cms_channel')->where('status', 'normal');
        if ($type !== '') {
            $query->where('type', $type);
        }
        $fields = $this->fieldString('cms_channel', [
            'id','parent_id','type','model_id','name','image','flag','seotitle','keywords','description',
            'diyname','outlink','linktype','linkid','items','weigh','pagesize','vip','listtype','iscontribute','isnav',
        ]);
        $rows = $query->field($fields)
            ->order('weigh', 'desc')->order('id', 'asc')->select()->toArray();

        $tree = [];
        foreach ($rows as $r) {
            $r['items'] = (int)$r['items'];
            $tree[(int)$r['id']] = $r;
        }
        $result = [];
        foreach ($tree as $id => $node) {
            if ((int)$node['parent_id'] === $parentId) {
                $node['children'] = $this->collectChildren($tree, $id);
                $result[] = $node;
            }
        }
        if ($parentId === 0) {
            return $result;
        }
        return $this->collectChildren($tree, $parentId);
    }

    /**
     * 递归收集子栏目
     */
    protected function collectChildren(array $tree, int $parentId): array
    {
        $children = [];
        foreach ($tree as $id => $node) {
            if ((int)$node['parent_id'] === $parentId) {
                $node['children'] = $this->collectChildren($tree, $id);
                $children[] = $node;
            }
        }
        return $children;
    }

    /**
     * 仅返回当前登录用户可用的信息（用于会员中心类接口）
     */
    protected function requireLogin(): int
    {
        if (!$this->auth || !$this->auth->isLogin()) {
            $this->fail('请先登录', self::CODE_UNAUTHORIZED);
        }
        return (int)$this->auth->id;
    }
}
