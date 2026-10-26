<?php

namespace app\common\model\i18n;

use think\Model;
use think\facade\Db;

class Translation extends Model
{
    protected $name = 'app_translation';
    protected $autoWriteTimestamp = false;

    public static function enqueue(string $type, array $source, string $field, string $locale, string $sourceLocale): void
    {
        $text = (string)($source[$field] ?? '');
        $hash = hash('sha256', $text);
        $key = ['resource_type' => $type, 'resource_id' => $source['id'], 'field' => $field, 'locale' => $locale];
        $row = self::where($key)->find();
        if ($row && $row['source_hash'] === $hash && $row['source_locale'] === $sourceLocale && $row['status'] !== 'stale') {
            return;
        }
        if ($row && $row['origin'] === 'manual') {
            self::where('id', $row['id'])->where('origin', 'manual')->where('source_hash', $row['source_hash'])->update(['status' => 'stale', 'update_time' => time()]);
            return;
        }
        $data = ['source_locale' => $sourceLocale, 'source_hash' => $hash, 'source_text' => $text,
            'translated_text' => '', 'status' => trim($text) === '' ? 'published' : 'pending',
            'attempts' => 0, 'error' => '', 'available_at' => 0, 'update_time' => time()];
        if ($row) {
            self::where('id', $row['id'])->where('origin', 'machine')->where('source_hash', $row['source_hash'])->update($data);
        } else {
            self::create(array_merge($key, $data, ['origin' => 'machine', 'create_time' => time()]));
        }
    }

    public static function ready(int $limit, array $locales = []): array
    {
        // 进程异常退出的任务在超时后重新领取，重试上限避免持续消耗配额。
        self::where('status', 'processing')->where('update_time', '<', time() - 3600)
            ->update(['status' => 'failed', 'available_at' => time(), 'error' => '任务执行超时']);
        $query = self::whereIn('status', ['pending', 'failed'])->where('attempts', '<', 3);
        if ($locales) {
            $query->whereIn('locale', $locales);
        }
        return $query
            ->where('available_at', '<=', time())->order('id')->limit($limit)->select()->toArray();
    }

    public static function claim(array $row): bool
    {
        return self::where('id', $row['id'])->where('source_hash', $row['source_hash'])
            ->whereIn('status', ['pending', 'failed'])->where('origin', 'machine')
            ->update(['status' => 'processing', 'attempts' => $row['attempts'] + 1, 'update_time' => time()]) > 0;
    }

    public static function finish(array $row, array $data): void
    {
        self::where('id', $row['id'])->where('source_hash', $row['source_hash'])
            ->where('status', 'processing')->where('origin', 'machine')->update($data + ['update_time' => time()]);
    }

    public static function forRecords(string $type, array $ids, string $locale): array
    {
        return $ids ? self::where('resource_type', $type)->whereIn('resource_id', $ids)
            ->where('locale', $locale)->where('status', 'published')->select()->toArray() : [];
    }

    public static function applySearch($query, string $type, array $fields, string $keyword, ?string $locale): void
    {
        $definition = Resource::definition($type);
        $fields = array_values(array_intersect($fields, $definition['fields']));
        $sourceTable = Db::name($definition['table'])->getTable();
        $translationTable = (new self())->getTable();
        $query->where(function ($search) use ($fields, $keyword, $locale, $type, $sourceTable, $translationTable) {
            foreach ($fields as $field) {
                $search->whereOr($field, 'like', '%' . $keyword . '%');
            }
            if ($locale === null) {
                return;
            }
            $search->whereOr(function ($translated) use ($fields, $keyword, $locale, $type, $sourceTable, $translationTable) {
                $translated->whereExists(function ($subquery) use ($fields, $keyword, $locale, $type, $sourceTable, $translationTable) {
                    $subquery->table($translationTable)->alias('i18n_search')->fieldRaw('1')
                        ->where('i18n_search.resource_type', $type)->where('i18n_search.locale', $locale)
                        ->where('i18n_search.status', 'published')->whereLike('i18n_search.translated_text', '%' . $keyword . '%')
                        ->whereColumn('i18n_search.resource_id', $sourceTable . '.id')
                        ->where(function ($versions) use ($fields, $sourceTable) {
                            foreach ($fields as $field) {
                                $versions->whereOr(function ($version) use ($field, $sourceTable) {
                                    $version->where('i18n_search.field', $field)
                                        ->whereRaw('i18n_search.source_hash = SHA2(COALESCE(' . $sourceTable . '.' . $field . ", ''), 256)");
                                });
                            }
                        });
                });
            });
        });
    }

    public static function revise(int $id, string $text, string $status, string $expectedHash): void
    {
        if (!in_array($status, ['review', 'published'], true)) {
            throw new \InvalidArgumentException('译文状态不合法');
        }
        $row = self::find($id);
        if (!$row) {
            throw new \RuntimeException('译文不存在');
        }
        $source = Resource::current($row['resource_type'], (int)$row['resource_id']);
        if (!$source) {
            throw new \RuntimeException('原文不存在或未发布');
        }
        $current = (string)($source[$row['field']] ?? '');
        if (hash('sha256', $current) !== $expectedHash) {
            throw new \RuntimeException('原文已发生变化，请刷新后重新修订');
        }
        $row->save(['source_text' => $current, 'source_hash' => $expectedHash, 'translated_text' => $text,
            'origin' => 'manual', 'status' => $status, 'error' => '', 'update_time' => time()]);
    }

    public static function retry(int $id): void
    {
        $row = self::find($id);
        if (!$row || $row['origin'] === 'manual') {
            throw new \RuntimeException('译文不存在或已人工锁定，请通过编辑修订');
        }
        $source = Resource::current($row['resource_type'], (int)$row['resource_id']);
        if (!$source) {
            throw new \RuntimeException('原文不存在或未发布');
        }
        $text = (string)($source[$row['field']] ?? '');
        $row->save(['source_text' => $text, 'source_hash' => hash('sha256', $text), 'status' => 'pending',
            'attempts' => 0, 'error' => '', 'available_at' => 0, 'update_time' => time()]);
    }
}
