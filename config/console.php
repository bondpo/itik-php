<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'crud' => 'app\\admin\\command\\Crud',
        'subscription:expire' => 'app\\command\\SubscriptionExpire',
        'recommend:generate' => 'app\\command\\RecommendGenerate',
        'seed:fake' => 'app\\command\\FakeData',
        'matrix:test' => 'app\\command\\TestMatrix',
        'region:import' => 'app\\command\\ImportRegion',
        'calc' => 'app\\command\\Calc',
    ],
];
