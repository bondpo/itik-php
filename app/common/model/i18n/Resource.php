<?php

namespace app\common\model\i18n;

use think\Model;
use think\facade\Db;

class Resource extends Model
{
    protected $autoWriteTimestamp = false;

    public static function definition(string $type): array
    {
        $definition = config('i18n.resources.' . $type);
        if (!$definition) {
            throw new \InvalidArgumentException('不支持的翻译资源');
        }
        return $definition;
    }

    private static function resourceQuery(string $type)
    {
        $definition = self::definition($type);
        // ORM 在构造模型时即读取表结构，动态表查询必须先指定表名。
        $query = Db::name($definition['table'])->where($definition['status_field'] ?? 'status', $definition['status']);
        if (!empty($definition['soft_delete'])) {
            $query->whereNull('deletetime');
        }
        return $query;
    }

    public static function batch(string $type, int $afterId, int $limit = 100): array
    {
        return self::resourceQuery($type)->where('id', '>', $afterId)->order('id')->limit($limit)->select()->toArray();
    }

    public static function current(string $type, int $id): ?array
    {
        $row = self::resourceQuery($type)->where('id', $id)->find();
        return $row ?: null;
    }
}
