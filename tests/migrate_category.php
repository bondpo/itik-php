<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/common.php';

use think\App;
use think\facade\Db;

$app = new App();
$app->initialize();

echo "1. 创建项目分类表 bd_app_project_category...\n";
Db::execute("
CREATE TABLE IF NOT EXISTS `bd_app_project_category` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `pid` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '上级分类ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '分类名称',
  `slug` varchar(100) NOT NULL DEFAULT '' COMMENT '分类标识代码',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '分类图标或Emoji',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '分类描述',
  `weigh` int(11) NOT NULL DEFAULT 0 COMMENT '权重排序',
  `status` varchar(20) NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=隐藏',
  `create_time` bigint(20) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` bigint(20) NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_status` (`status`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目分类表';
");

echo "2. 检查 bd_app_project 表结构...\n";
$hasCol = Db::query("SHOW COLUMNS FROM `bd_app_project` LIKE 'category_id'");
if (empty($hasCol)) {
    Db::execute("ALTER TABLE `bd_app_project` ADD COLUMN `category_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '项目主分类ID' AFTER `cover`, ADD KEY `idx_category_id` (`category_id`)");
    echo "   已新增 category_id 字段及索引\n";
} else {
    echo "   category_id 字段已存在\n";
}

echo "迁移完成！\n";
