<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use think\facade\Db;

Db::transaction(function () {
    $parentId = Db::name('admin_rule')->where('name', 'consult.log')->value('id');
    if (!$parentId) {
        throw new RuntimeException('请先初始化咨询管理菜单');
    }
    foreach (['messages' => '查看', 'del' => '删除'] as $action => $title) {
        $name = 'consult.log/' . $action;
        if (!Db::name('admin_rule')->where('name', $name)->find()) {
            Db::name('admin_rule')->insert([
                'name' => $name, 'title' => $title, 'pid' => $parentId, 'type' => '2',
                'ismenu' => 0, 'status' => 'normal', 'create_time' => time(), 'update_time' => time(),
            ]);
        }
    }
});
echo "咨询查看、删除权限节点已就绪，请按需为管理员角色分配权限。\n";
