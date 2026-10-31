<?php

return [
    // 多应用初始化会重载默认语言，须在应用初始化后重新解析语言参数和请求头。
    \think\middleware\LoadLangPack::class,
];
