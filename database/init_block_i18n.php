<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use think\facade\Db;
use think\facade\Cache;

$prefix = (string)config('database.connections.mysql.prefix');
if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
    throw new RuntimeException('数据库表前缀不合法');
}
$table = $prefix . 'cms_block';
if (!Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'lang'")) {
    Db::execute("ALTER TABLE `{$table}` ADD COLUMN `lang` varchar(20) NOT NULL DEFAULT '' COMMENT '展示语言，空表示所有语言' AFTER `name`");
}
Cache::tag('cms')->clear();
echo "区块展示语言字段已就绪，现有广告保持为所有语言通用。\n";
