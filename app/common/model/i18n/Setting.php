<?php

namespace app\common\model\i18n;

use think\Model;

class Setting extends Model
{
    protected $name = 'app_i18n_setting';
    protected $autoWriteTimestamp = false;

    public static function defaults(): array
    {
        return ['enabled' => 0, 'appid' => '', 'secret' => '', 'source_locale' => 'zh-cn',
            'target_locales' => ['en'], 'auto_publish' => 0, 'timeout' => 10, 'interval_ms' => 1100];
    }

    public static function options(): array
    {
        $row = self::find(1);
        return array_replace(self::defaults(), $row ? json_decode($row->getData('value'), true, 512, JSON_THROW_ON_ERROR) : []);
    }

    public static function saveOptions(array $input): void
    {
        $old = self::options();
        $languages = config('i18n.languages');
        $targets = array_values(array_unique((array)($input['target_locales'] ?? [])));
        $source = (string)($input['source_locale'] ?? 'zh-cn');
        if (!isset($languages[$source]) || !$targets || array_diff($targets, array_keys($languages)) || in_array($source, $targets, true)) {
            throw new \InvalidArgumentException('请选择有效且不同的原文语言和目标语言');
        }
        // 更换原文语言会改变所有已有译文的语义，已有数据时禁止直接切换。
        if ($source !== $old['source_locale'] && Translation::count() > 0) {
            throw new \InvalidArgumentException('已有译文时不能更换原文语言，请先完成原文数据迁移');
        }
        $options = [
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'appid' => trim((string)($input['appid'] ?? '')),
            'secret' => trim((string)($input['secret'] ?? '')) ?: $old['secret'],
            'source_locale' => $source, 'target_locales' => $targets,
            'auto_publish' => empty($input['auto_publish']) ? 0 : 1,
            'timeout' => max(1, min(60, (int)($input['timeout'] ?? 10))),
            'interval_ms' => max(100, min(60000, (int)($input['interval_ms'] ?? 1100))),
        ];
        if ($options['enabled'] && ($options['appid'] === '' || $options['secret'] === '')) {
            throw new \InvalidArgumentException('启用翻译前请填写百度 APPID 和密钥');
        }
        $row = self::find(1) ?: new self();
        $row->save(['id' => 1, 'value' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
