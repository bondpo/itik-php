<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => env('CACHE_DRIVER', 'file'),

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => app()->getRuntimePath() . 'cache' . DIRECTORY_SEPARATOR,
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'  => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // 更多的缓存连接
        // redis缓存
        'redis'   =>  [
            // 驱动方式
            'type'          => 'redis',
            // 服务器地址
            'host'          => env('REDIS_HOSTNAME', '127.0.0.1'),
            // 端口
            'port'          => env('REDIS_PORT', '6379'),
            // 密码
            'password'      => env('REDIS_PASSWORD', ''),
            // 缓存有效期 0表示永久缓存
            'expire'        => 3600 ,
            // 缓存前缀
            'prefix'        => env('REDIS_PREFIX', ''),
            // 缓存标签前缀
            'tag_prefix'    => env('CACHE_TAG_PREFIX', '').'ITIK:',
            // 数据库 0号数据库
            'select'        => intval(env('REDIS_SELECT', 0)),
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'     => [],
            // 服务端主动关闭
            'timeout'       => 0,
            // Swoole 已提供应用层连接池，避免 PhpRedis 持久连接被淘汰后仍占用底层连接。
            'persistent'    => false,
        ],
    ],
    // gzde数据压缩
    'is_gzde' => false,
    // 所有数据缓存
    'is_data' => true,
];
