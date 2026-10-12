<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// $Id$
// [ 内置服务器路由脚本 ]
// 说明：静态资源（css/js/图片等）直接由内置服务器返回，其余请求交给 index.php
// 注意：不同启动方式（php -S / php think run）下 $_SERVER 的键可能缺失，
//       这里做兼容处理，避免静态资源被错误地转发给 index.php 导致样式/脚本 404

$docroot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$script  = (string)($_SERVER['SCRIPT_NAME'] ?? '');

// 1) 常规判断：DOCUMENT_ROOT + SCRIPT_NAME 命中真实文件
if ($script !== '' && $docroot !== '' && is_file($docroot . $script)) {
    return false;
}

// 2) 回退判断：内置服务器提供的 SCRIPT_FILENAME 命中真实文件（且不是入口文件）
$scriptFile = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($scriptFile !== '' && is_file($scriptFile) && realpath($scriptFile) !== realpath(__DIR__ . '/index.php')) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';

require __DIR__ . '/index.php';
