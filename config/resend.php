<?php

// +----------------------------------------------------------------------
// | Resend 邮件服务配置
// +----------------------------------------------------------------------

return [
    // Resend 接口密钥
    'api_key'    => env('resend.api_key', 're_SduyGGWY_BusBGD2MKjKaxYqxuYKooLhi'),
    // 发信邮箱（需为在 Resend 已验证的域名）
    'from_email' => env('resend.from_email', 'service@itiklink.com'),
    // 发件人显示名称
    'from_name'  => env('resend.from_name', 'ITIK'),
    // Resend 接口地址
    'api_url'    => 'https://api.resend.com/emails',
];
