<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\App;

// [ 应用入口文件 ]

// 抑制已废弃标记（Deprecated）直接输出到响应体：
// 例如 PHP 8.5 下 symfony/translation 的函数签名告警会污染 JSON 响应，导致前端无法解析
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

$rootPath = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR;
// 安装检测-s
if (!is_file($rootPath . 'install.lock')) {
    header("location:./install.php");
    exit;
}

// 安装检测-e
// 执行HTTP应用并响应
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);
