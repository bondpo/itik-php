<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use think\facade\Db;

$prefix = (string)config('database.connections.mysql.prefix');
if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
    throw new RuntimeException('数据库表前缀不合法');
}
$sql = str_replace('`bd_', '`' . $prefix, file_get_contents(__DIR__ . '/i18n.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    Db::execute($statement);
}
Db::transaction(function () {
    $now = time();
    $ensure = function (string $name, string $title, int $pid, string $type, bool $menu) use ($now): int {
        $id = Db::name('admin_rule')->where('name', $name)->value('id');
        if ($id) {
            return (int)$id;
        }
        return (int)Db::name('admin_rule')->insertGetId([
            'name' => $name, 'title' => $title, 'pid' => $pid, 'type' => $type,
            'ismenu' => $menu ? 1 : 0, 'icon' => 'fa fa-language', 'url' => '',
            'status' => 'normal', 'weigh' => 10, 'create_time' => $now, 'update_time' => $now,
        ]);
    };
    $top = $ensure('i18n', '国际化管理', 0, '0', true);
    $settings = $ensure('i18n.setting', '百度翻译配置', $top, '1', true);
    $ensure('i18n.setting/index', '查看与保存配置', $settings, '2', false);
    $translations = $ensure('i18n.translation', '译文管理', $top, '1', true);
    $ensure('i18n.translation/index', '查看译文', $translations, '2', false);
    $ensure('i18n.translation/edit', '修订和发布译文', $translations, '2', false);
    $ensure('i18n.translation/approve', '批量审核发布', $translations, '2', false);
    $ensure('i18n.translation/retry', '重新机器翻译', $translations, '2', false);
});
echo "国际化数据表和后台权限菜单已初始化，请为管理员分配对应权限。\n";
