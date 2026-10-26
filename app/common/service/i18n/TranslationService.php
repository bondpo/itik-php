<?php

namespace app\common\service\i18n;

use app\common\model\i18n\Resource;
use app\common\model\i18n\Message;
use app\common\model\i18n\Setting;
use app\common\model\i18n\Translation;

class TranslationService
{
    private array $options;

    public function __construct(?array $options = null)
    {
        $this->options = $options ?? Setting::options();
    }

    public function locale(): string
    {
        $locale = strtolower(str_replace('_', '-', (string)app()->lang->getLangSet()));
        return $this->supports($locale) ? $locale : $this->options['source_locale'];
    }

    private function supports(string $locale): bool
    {
        return $locale === $this->options['source_locale'] || in_array($locale, $this->options['target_locales'], true);
    }

    public static function overlay(array $row, array $translations): array
    {
        foreach ($translations as $translation) {
            $field = $translation['field'];
            if (array_key_exists($field, $row) && $translation['status'] === 'published'
                && hash('sha256', (string)$row[$field]) === $translation['source_hash']) {
                $row[$field] = $translation['translated_text'];
            }
        }
        return $row;
    }

    public function rows(string $type, $rows, ?string $locale = null): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $locale = $locale ?? $this->locale();
        if (!$this->options['enabled'] || !$this->supports($locale) || $locale === ($type === 'api_message' ? 'zh-cn' : $this->options['source_locale']) || !$rows) {
            return $rows;
        }
        $fields = Resource::definition($type)['fields'];
        $translations = Translation::forRecords($type, array_column($rows, 'id'), $locale);
        $byId = [];
        foreach ($translations as $translation) {
            if (in_array($translation['field'], $fields, true)) {
                $byId[$translation['resource_id']][] = $translation;
            }
        }
        foreach ($rows as &$row) {
            $row = self::overlay($row, $byId[$row['id'] ?? 0] ?? []);
        }
        return $rows;
    }

    public function row(string $type, array $row): array
    {
        return $this->rows($type, [$row])[0];
    }

    public function categories(array $rows): array
    {
        $flat = [];
        $collect = function (array $nodes) use (&$collect, &$flat) {
            foreach ($nodes as $node) {
                $flat[] = $node;
                $collect($node['children'] ?? []);
            }
        };
        $collect($rows);
        $localized = array_column($this->rows('category', $flat), null, 'id');
        $apply = function (array $nodes) use (&$apply, $localized) {
            foreach ($nodes as &$node) {
                $children = isset($node['children']) ? $apply($node['children']) : null;
                $node = $localized[$node['id']] ?? $node;
                if ($children !== null) {
                    $node['children'] = $children;
                }
            }
            return $nodes;
        };
        return $apply($rows);
    }

    public function projects($rows): array
    {
        $rows = $this->rows('project', $rows);
        $categories = [];
        foreach ($rows as $row) {
            if (!empty($row['category']) && is_array($row['category'])) {
                $categories[] = $row['category'];
            } elseif (!empty($row['category_id']) && isset($row['category_name'])) {
                $categories[] = ['id' => $row['category_id'], 'name' => $row['category_name']];
            }
        }
        $categories = array_column($this->rows('category', $categories), null, 'id');
        foreach ($rows as &$row) {
            if (!empty($row['category']['id'])) {
                $row['category'] = $categories[$row['category']['id']] ?? $row['category'];
            } elseif (!empty($row['category_id']) && isset($row['category_name'])) {
                $row['category_name'] = $categories[$row['category_id']]['name'] ?? $row['category_name'];
            }
        }
        return $rows;
    }

    public function message(string $text): string
    {
        if ($text === '' || !$this->options['enabled'] || $this->locale() === 'zh-cn') {
            return $text;
        }
        // 现有语言包中的英文键也统一映射到中文原文目录。
        $dictionary = require root_path() . 'app/api/lang/zh-cn.php';
        $source = Message::source($dictionary[$text] ?? $text);
        return $source ? $this->row('api_message', $source)['text'] : $text;
    }

    public function formatMessage(string $text, array $values): string
    {
        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{' . $key . '}'] = (string)$value;
        }
        return strtr($this->message($text), $replace);
    }

    public function search($query, string $type, array $fields, string $keyword): void
    {
        $locale = $this->locale();
        Translation::applySearch($query, $type, $fields, $keyword,
            $this->options['enabled'] && $locale !== $this->options['source_locale'] ? $locale : null);
    }

    public function scan(): int
    {
        if (!$this->options['enabled']) {
            return 0;
        }
        Message::syncCatalog();
        $count = 0;
        foreach (config('i18n.resources') as $type => $definition) {
            $afterId = 0;
            do {
                $rows = Resource::batch($type, $afterId);
                foreach ($rows as $row) {
                    foreach ($definition['fields'] as $field) {
                        if (!array_key_exists($field, $row)) {
                            continue;
                        }
                        foreach (array_unique(array_merge($this->options['target_locales'], $type === 'api_message' ? [$this->options['source_locale']] : [])) as $locale) {
                            $sourceLocale = $type === 'api_message' ? 'zh-cn' : $this->options['source_locale'];
                            if ($locale === $sourceLocale) {
                                continue;
                            }
                            Translation::enqueue($type, $row, $field, $locale, $sourceLocale);
                            $count++;
                        }
                    }
                    $afterId = (int)$row['id'];
                }
            } while (count($rows) === 100);
        }
        return $count;
    }

    public function work(int $limit, ?BaiduTranslator $translator = null): array
    {
        $counts = ['success' => 0, 'failed' => 0];
        if (!$this->options['enabled']) {
            return $counts;
        }
        $translator = $translator ?? new BaiduTranslator($this->options);
        $languages = config('i18n.languages');
        foreach (Translation::ready($limit, array_unique(array_merge($this->options['target_locales'], [$this->options['source_locale']]))) as $task) {
            if (!$this->supports($task['locale']) || !Translation::claim($task)) {
                continue;
            }
            try {
                $source = Resource::current($task['resource_type'], (int)$task['resource_id']);
                if (!$source || hash('sha256', (string)($source[$task['field']] ?? '')) !== $task['source_hash']) {
                    Translation::finish($task, ['status' => 'stale']);
                    continue;
                }
                $text = $translator->translate($task['source_text'], $languages[$task['source_locale']]['baidu'], $languages[$task['locale']]['baidu']);
                $source = Resource::current($task['resource_type'], (int)$task['resource_id']);
                if (!$source || hash('sha256', (string)($source[$task['field']] ?? '')) !== $task['source_hash']) {
                    Translation::finish($task, ['status' => 'stale']);
                    continue;
                }
                Translation::finish($task, ['translated_text' => $text, 'error' => '',
                    'status' => $this->options['auto_publish'] ? 'published' : 'review']);
                $counts['success']++;
            } catch (\Throwable $e) {
                Translation::finish($task, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255),
                    'available_at' => time() + 60 * (2 ** (int)$task['attempts'])]);
                $counts['failed']++;
            }
        }
        return $counts;
    }
}
