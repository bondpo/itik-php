<?php

// +----------------------------------------------------------------------
// | 多语言设置
// +----------------------------------------------------------------------
return [
    // 默认语言
    'default_lang'        => env('lang.default_lang', 'zh-cn'),
    // 自动侦测浏览器语言
    'auto_detect_browser' => true,
    // 允许的语言列表
    'allow_lang_list'     => array_keys((require __DIR__ . '/i18n.php')['languages']),
    // 多语言自动侦测变量名
    'detect_var'          => 'lang',
    // 是否使用Cookie记录
    'use_cookie'          => true,
    // 多语言cookie变量
    'cookie_var'          => 'think_lang',
    // 多语言header变量
    'header_var'          => 'think-lang',
    // 扩展语言包
    'extend_list'         => [],
    // Accept-Language转义为对应语言包名称
    'accept_language'     => [
        'zh-hans-cn' => 'zh-cn',
        'cn'         => 'zh-cn',
        'zh' => 'zh-cn', 'zh-hans' => 'zh-cn', 'zh-hant' => 'zh-tw',
        'en-us' => 'en', 'en-gb' => 'en', 'ja-jp' => 'ja', 'ko-kr' => 'ko',
        'zh-hant-tw' => 'zh-tw', 'fr-fr' => 'fr', 'de-de' => 'de',
        'es-es' => 'es', 'ru-ru' => 'ru', 'ar-sa' => 'ar', 'pt-br' => 'pt', 'pt-pt' => 'pt'
    ],
    // 是否支持语言分组
    'allow_group'         => false,
];
