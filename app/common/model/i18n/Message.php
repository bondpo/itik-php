<?php

namespace app\common\model\i18n;

use think\Model;

class Message extends Model
{
    protected $name = 'app_i18n_message';
    protected $autoWriteTimestamp = false;

    public static function syncCatalog(): void
    {
        $messages = require root_path() . 'config/i18n_messages.php';
        $messages = array_unique(array_merge($messages, array_values(require root_path() . 'app/api/lang/zh-cn.php')));
        foreach ($messages as $text) {
            if (!is_string($text) || $text === '') {
                continue;
            }
            $hash = hash('sha256', $text);
            if (!self::where('source_hash', $hash)->find()) {
                self::create(['text' => $text, 'source_hash' => $hash, 'status' => 'normal']);
            }
        }
    }

    public static function source(string $text): ?array
    {
        $row = self::where('source_hash', hash('sha256', $text))->find();
        return $row ? $row->toArray() : null;
    }
}
