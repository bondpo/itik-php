<?php
/**
 * App 升级配置（改这里即可发版，无需改库）
 *
 * pkg_type:
 * - apk：整包更新（当前 uni-app x VDOM 必须用这个）
 * - wgt：资源热更新（仅蒸汽模式 Vapor 可用）
 *
 * url 填可公网下载的安装包地址。
 */
return [
    'android' => [
        'version_name' => '1.0.2',
        'version_code' => 102,
        'force'        => false,
        'title'        => '发现新版本',
        'content'      => '优化体验与稳定性，建议更新。',
        'pkg_type'     => 'apk',
        'url'          => '', // 例：https://m.itiklink.com/downloads/itiklink-1.0.3.apk
    ],
    'ios' => [
        'version_name' => '1.0.2',
        'version_code' => 102,
        'force'        => false,
        'title'        => '发现新版本',
        'content'      => '请前往 App Store 更新。',
        'pkg_type'     => 'store',
        'url'          => '', // App Store 链接
    ],
];
