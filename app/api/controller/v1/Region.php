<?php

// +----------------------------------------------------------------------
// | API v1 —— 地区联动（公开）
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;

/**
 * 地区接口
 *
 * 路由：
 *   GET /api/v1/regions?parent_id=0&lang=zh   取某父级下的子地区（联动）
 *   GET /api/v1/regions/tree?lang=zh&depth=3  取完整地区树
 *   GET /api/v1/regions/path?code=440305      取某地区的祖先链（用于回显）
 */
class Region extends Base
{
    protected $noNeedLogin = ['index', 'tree', 'path'];
    protected $noNeedRight = '*';

    /**
     * 地区联动：返回指定父级的直接子地区
     */
    public function index()
    {
        $parentId = (int) $this->request->param('parent_id', 0);
        $lang     = $this->langParam();

        $list = Db::name('region')
            ->where('parent_id', $parentId)
            ->where('status', 'normal')
            ->field('id,parent_id,level,code,name_zh,name_en,py')
            ->order('weigh', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // 一次查询标记哪些节点还有子级
        $ids = array_column($list, 'id');
        $hasChildren = [];
        if ($ids) {
            $hasChildren = Db::name('region')
                ->whereIn('parent_id', $ids)
                ->where('status', 'normal')
                ->group('parent_id')
                ->column('parent_id');
        }

        foreach ($list as $k => $item) {
            $item['name']         = $this->resolveName($item, $lang);
            $item['has_children'] = in_array($item['id'], $hasChildren, false);
            $list[$k]             = $item;
        }

        $this->success('', $list);
    }

    /**
     * 完整地区树（可按 parent_id + depth 裁剪）
     */
    public function tree()
    {
        $lang     = $this->langParam();
        $parentId = (int) $this->request->param('parent_id', 0);
        $depth    = max(1, min((int) $this->request->param('depth', 3), 4));

        $rows = Db::name('region')
            ->where('status', 'normal')
            ->field('id,parent_id,level,code,name_zh,name_en,py')
            ->order('weigh', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $byParent = [];
        foreach ($rows as $row) {
            $row['name'] = $this->resolveName($row, $lang);
            $byParent[(int) $row['parent_id']][] = $row;
        }

        $build = function (int $pid, int $level) use (&$build, $byParent, $depth): array {
            $nodes = $byParent[$pid] ?? [];
            $tree  = [];
            foreach ($nodes as $node) {
                $item = $node;
                if ($level < $depth && !empty($byParent[(int) $node['id']])) {
                    $item['children'] = $build((int) $node['id'], $level + 1);
                } else {
                    $item['children'] = [];
                }
                $tree[] = $item;
            }
            return $tree;
        };

        $this->success('', $build($parentId, 1));
    }

    /**
     * 地区祖先链（按 code 或 id 反查，用于表单回显）
     */
    public function path()
    {
        $id   = (int) $this->request->param('id', 0);
        $code = (string) $this->request->param('code', '');
        $lang = $this->langParam();

        $query = Db::name('region')->field('id,parent_id,level,code,name_zh,name_en,py');
        if ($id > 0) {
            $node = $query->where('id', $id)->find();
        } elseif ($code !== '') {
            $node = $query->where('code', $code)->order('level', 'desc')->find();
        } else {
            $node = null;
        }

        if (!$node) {
            $this->fail('地区不存在', self::CODE_NOT_FOUND);
        }

        $chain = [$node];
        $guard = 0;
        while ((int) $node['parent_id'] > 0 && $guard++ < 8) {
            $parent = Db::name('region')
                ->field('id,parent_id,level,code,name_zh,name_en,py')
                ->where('id', (int) $node['parent_id'])
                ->find();
            if (!$parent) {
                break;
            }
            array_unshift($chain, $parent);
            $node = $parent;
        }

        foreach ($chain as $k => $item) {
            $chain[$k]['name'] = $this->resolveName($item, $lang);
        }

        $this->success('', ['list' => $chain]);
    }

    /**
     * 语言参数：zh / en
     */
    private function langParam(): string
    {
        return str_starts_with(strtolower($this->app->lang->getLangSet()), 'en') ? 'en' : 'zh';
    }

    /**
     * 名称解析（优先请求语言，缺失回退另一语言）
     */
    private function resolveName(array $item, string $lang): string
    {
        if ($lang === 'en' && !empty($item['name_en'])) {
            return (string) $item['name_en'];
        }
        return $item['name_zh'] !== '' ? (string) $item['name_zh'] : (string) $item['name_en'];
    }
}
