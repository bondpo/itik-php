<?php

return [
    // Matrix Homeserver 基础地址，例如 https://matrix.yourdomain.com
    'base_url'    => env('MATRIX.BASE_URL', 'https://matrix.example.com'),

    // Matrix 域名服务器名称（用于拼接 @localpart:server_name）
    'server_name' => env('MATRIX.SERVER_NAME', 'example.com'),

    // Synapse 管理员 Access Token（在 Synapse 服务器上生成或通过管理员账号登录获取）
    'admin_token' => env('MATRIX.ADMIN_TOKEN', ''),

    // 请求超时时间（秒）
    'timeout'     => (float) env('MATRIX.TIMEOUT', 10.0),

    // 用户名前缀（避免与普通 Matrix 用户命名冲突，如 'u_'）
    'user_prefix' => env('MATRIX.USER_PREFIX', 'u_'),

    // 默认系统机器人账号 User ID（用于发送系统通知，如 @bot:example.com）
    'bot_user_id' => env('MATRIX.BOT_USER_ID', ''),
    // 系统机器人的 Access Token
    'bot_token'   => env('MATRIX.BOT_TOKEN', ''),
];
