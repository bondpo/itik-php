<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 项目分类模型
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;

class ProjectCategory extends Model
{
    protected $name = 'app_project_category';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int',
        'weigh'       => 'int',
        'pid'         => 'int',
    ];

    /**
     * 上级分类
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'pid', 'id');
    }

    /**
     * 子分类列表
     */
    public function children()
    {
        return $this->hasMany(self::class, 'pid', 'id')->order('weigh', 'desc')->order('id', 'asc');
    }

    /**
     * 该分类下的所有项目
     */
    public function projects()
    {
        return $this->hasMany(Project::class, 'category_id', 'id');
    }

    /**
     * 获取分类树形结构
     */
    public static function getTree(bool $onlyNormal = true): array
    {
        $query = self::order('weigh', 'desc')->order('id', 'asc');
        if ($onlyNormal) {
            $query->where('status', 'normal');
        }
        $list = $query->select()->toArray();
        return self::buildTree($list, 0);
    }

    /**
     * 递归构建树形
     */
    public static function buildTree(array $items, int $pid = 0): array
    {
        $branch = [];
        foreach ($items as $item) {
            if ((int)$item['pid'] === $pid) {
                $children = self::buildTree($items, (int)$item['id']);
                if ($children) {
                    $item['children'] = $children;
                } else {
                    $item['children'] = [];
                }
                $branch[] = $item;
            }
        }
        return $branch;
    }
}
