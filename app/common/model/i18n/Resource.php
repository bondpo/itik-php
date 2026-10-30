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

    /** CMS 正文可能存于模型副表；扫描、审核和展示须使用同一份原文。 */
    public static function withCmsBody(array $rows, bool $lock = false): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (!empty($row['model_id'])) {
                $groups[(int)$row['model_id']][] = (int)$row['id'];
            }
        }
        if (!$groups) {
            return $rows;
        }
        $models = Db::name('cms_model')->whereIn('id', array_keys($groups))->column('table', 'id');
        $bodies = [];
        foreach ($groups as $modelId => $ids) {
            $table = $models[$modelId] ?? '';
            // 表名仅来自后台模型配置，仍限制为合法标识符。
            if (!$table || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                continue;
            }
            $fields = Db::name($table)->getTableFields();
            if (!in_array('content', $fields, true)) {
                continue;
            }
            foreach (Db::name($table)->whereIn('id', $ids)->lock($lock)->field('id,content')->select() as $addon) {
                $bodies[(int)$addon['id']] = $addon['content'];
            }
        }
        foreach ($rows as &$row) {
            if (array_key_exists((int)$row['id'], $bodies)) {
                $row['content'] = $bodies[(int)$row['id']];
            }
        }
        return $rows;
    }

    public static function batch(string $type, int $afterId, int $limit = 100): array
    {
        $rows = self::resourceQuery($type)->where('id', '>', $afterId)->order('id')->limit($limit)->select()->toArray();
        return $type === 'cms_archive' ? self::withCmsBody($rows) : $rows;
    }

    public static function current(string $type, int $id, bool $lock = false): ?array
    {
        $row = self::resourceQuery($type)->where('id', $id)->lock($lock)->find();
        return $row ? ($type === 'cms_archive' ? self::withCmsBody([$row], $lock)[0] : $row) : null;
    }
}
