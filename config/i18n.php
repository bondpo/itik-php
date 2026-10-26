<?php

return [
    // 应用语言标识与百度语言代码分离，扩展语种无需修改业务表。
    'languages' => [
        'zh-cn' => ['name' => '简体中文', 'baidu' => 'zh'],
        'en' => ['name' => 'English', 'baidu' => 'en'],
        'zh-tw' => ['name' => '繁體中文', 'baidu' => 'cht'],
        'ja' => ['name' => '日本語', 'baidu' => 'jp'],
        'ko' => ['name' => '한국어', 'baidu' => 'kor'],
        'fr' => ['name' => 'Français', 'baidu' => 'fra'],
        'de' => ['name' => 'Deutsch', 'baidu' => 'de'],
        'es' => ['name' => 'Español', 'baidu' => 'spa'],
        'ru' => ['name' => 'Русский', 'baidu' => 'ru'],
        'ar' => ['name' => 'العربية', 'baidu' => 'ara'],
        'pt' => ['name' => 'Português', 'baidu' => 'pt'],
    ],
    // 仅允许翻译展示字段，标识、状态、金额及用户隐私不进入翻译服务。
    'resources' => [
        'api_message' => ['table' => 'app_i18n_message', 'fields' => ['text'], 'status' => 'normal'],
        'project' => ['table' => 'app_project', 'fields' => ['name', 'subtitle', 'summary', 'content'], 'status' => 'normal', 'soft_delete' => true],
        'content' => ['table' => 'app_content', 'fields' => ['title', 'summary', 'content'], 'status' => 'normal', 'soft_delete' => true],
        'category' => ['table' => 'app_project_category', 'fields' => ['name', 'description'], 'status' => 'normal'],
        'plan' => ['table' => 'app_plan', 'fields' => ['name'], 'status' => 'normal'],
        'promotion_package' => ['table' => 'app_promotion_package', 'fields' => ['name'], 'status' => 'normal'],
        'timeline' => ['table' => 'app_project_timeline', 'fields' => ['title', 'tag', 'summary', 'content'], 'status' => 'normal'],
        'material' => ['table' => 'app_project_material', 'fields' => ['title'], 'status_field' => 'review_status', 'status' => 'normal'],
    ],
];
