<?php

declare(strict_types=1);

namespace app\common\service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\facade\Config;
use think\facade\Log;

/**
 * Matrix Synapse 服务端集成组件
 * 基于 Matrix Client-Server API 与 Synapse Admin API
 */
class MatrixService
{
    protected Client $client;
    protected string $baseUrl;
    protected string $serverName;
    protected string $adminToken;
    protected string $userPrefix;
    protected string $botUserId;
    protected string $botToken;

    public function __construct()
    {
        $this->baseUrl    = rtrim(Config::get('matrix.base_url', 'http://127.0.0.1:8008'), '/');
        $this->serverName = Config::get('matrix.server_name', 'localhost');
        $this->adminToken = Config::get('matrix.admin_token', '');
        $this->userPrefix = Config::get('matrix.user_prefix', 'u_');
        $this->botUserId  = Config::get('matrix.bot_user_id', '');
        $this->botToken   = Config::get('matrix.bot_token', '');

        $timeout = (float) Config::get('matrix.timeout', 10.0);

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => $timeout,
            'headers'  => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'http_errors' => false,
        ]);
    }

    /**
     * 构建合规的 Matrix 完整 User ID（例如 @u_123:example.com）
     */
    public function buildUserId(string|int $identifier): string
    {
        $identifier = (string) $identifier;
        if (str_starts_with($identifier, '@') && str_contains($identifier, ':')) {
            return $identifier;
        }

        // 移除非法字符，Matrix localpart 规范建议为小写字母、数字及 . _ = - /
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9._=-]/', '', $identifier));
        return sprintf('@%s%s:%s', $this->userPrefix, $clean, $this->serverName);
    }

    /**
     * 【用户注册联动】通过 Synapse Admin API 创建或更新 Matrix 账号
     *
     * @param string|int $identifier 业务用户标识（UID 或 唯一用户名）
     * @param string     $password   用户密码（或系统生成的强随机密码）
     * @param string     $displayName 昵称
     * @param string     $avatarUrl   头像 MXC 协议地址或外部地址
     * @param bool       $isAdmin     是否设为 Matrix 管理员
     * @return array{success: bool, user_id: string, data?: array, error?: string}
     */
    public function registerUser(
        string|int $identifier,
        string $password,
        string $displayName = '',
        string $avatarUrl = '',
        bool $isAdmin = false
    ): array {
        $matrixUserId = $this->buildUserId($identifier);

        $payload = [
            'password'    => $password,
            'displayname' => $displayName ?: (string) $identifier,
            'admin'       => $isAdmin,
            'deactivated' => false,
        ];
        if (!empty($avatarUrl)) {
            $payload['avatar_url'] = $avatarUrl;
        }

        try {
            $response = $this->client->put("/_synapse/admin/v2/users/{$matrixUserId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->adminToken}",
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true) ?? [];

            // 200: 更新成功，201: 创建成功
            if (in_array($statusCode, [200, 201], true)) {
                return [
                    'success' => true,
                    'user_id' => $matrixUserId,
                    'data'    => $body,
                ];
            }

            Log::error('[Matrix] Register failed: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            return [
                'success' => false,
                'user_id' => $matrixUserId,
                'error'   => $body['error'] ?? 'Matrix registration failed',
            ];
        } catch (GuzzleException $e) {
            Log::error('[Matrix] Register exception: ' . $e->getMessage());
            return [
                'success' => false,
                'user_id' => $matrixUserId,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * 【登录联动方案 A】用户账密登录 Matrix，换取 Access Token
     *
     * @param string|int $identifier 用户名或 UID
     * @param string     $password   用户在 Matrix 绑定的密码
     * @param string     $deviceName 设备展示名（如 Web 端、App 端）
     * @return array{success: bool, access_token?: string, device_id?: string, user_id?: string, error?: string}
     */
    public function loginWithPassword(string|int $identifier, string $password, string $deviceName = 'BadouCMS Client'): array
    {
        $matrixUserId = $this->buildUserId($identifier);

        $payload = [
            'type' => 'm.login.password',
            'identifier' => [
                'type' => 'm.id.user',
                'user' => $matrixUserId,
            ],
            'password' => $password,
            'initial_device_display_name' => $deviceName,
        ];

        try {
            $response = $this->client->post('/_matrix/client/v3/login', [
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?? [];

            if ($response->getStatusCode() === 200 && !empty($body['access_token'])) {
                return [
                    'success'      => true,
                    'user_id'      => $body['user_id'] ?? $matrixUserId,
                    'access_token' => $body['access_token'],
                    'device_id'    => $body['device_id'] ?? '',
                    'home_server'  => $body['home_server'] ?? $this->serverName,
                ];
            }

            return [
                'success' => false,
                'error'   => $body['error'] ?? 'Matrix login failed',
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * 【登录联动方案 B (免密无感绑定)】通过 Admin API 直接为已在业务端登录的用户发放 Matrix Token
     * 优势：业务系统无需存储/同步用户的 Matrix 明文密码，即便用户通过短信/微信/第三方扫码登录，也能直接无缝获取 IM Token
     *
     * @param string|int $identifier 业务用户标识
     * @return array{success: bool, access_token?: string, user_id?: string, error?: string}
     */
    public function createTokenDirect(string|int $identifier): array
    {
        $matrixUserId = $this->buildUserId($identifier);

        try {
            // Synapse Admin API: POST /_synapse/admin/v1/users/<user_id>/login
            $response = $this->client->post("/_synapse/admin/v1/users/{$matrixUserId}/login", [
                'headers' => [
                    'Authorization' => "Bearer {$this->adminToken}",
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true) ?? [];

            if ($response->getStatusCode() === 200 && !empty($body['access_token'])) {
                return [
                    'success'      => true,
                    'user_id'      => $matrixUserId,
                    'access_token' => $body['access_token'],
                    'home_server'  => $this->serverName,
                    'base_url'     => $this->baseUrl,
                ];
            }

            return [
                'success' => false,
                'error'   => $body['error'] ?? 'Matrix admin token generation failed',
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * 更新用户资料（昵称、头像）
     */
    public function updateProfile(string|int $identifier, ?string $displayName = null, ?string $avatarUrl = null): bool
    {
        $matrixUserId = $this->buildUserId($identifier);
        $payload = [];

        if ($displayName !== null) {
            $payload['displayname'] = $displayName;
        }
        if ($avatarUrl !== null) {
            $payload['avatar_url'] = $avatarUrl;
        }

        if (empty($payload)) {
            return true;
        }

        try {
            $response = $this->client->put("/_synapse/admin/v2/users/{$matrixUserId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->adminToken}",
                ],
                'json' => $payload,
            ]);

            return in_array($response->getStatusCode(), [200, 201], true);
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * 【单聊房间创建】创建一个 1v1 私聊房间并邀请对方
     *
     * @param string     $userToken 当前用户的 Matrix Access Token
     * @param string|int $targetIdentifier 对方用户标识
     * @return array{success: bool, room_id?: string, error?: string}
     */
    public function createDirectChat(string $userToken, string|int $targetIdentifier): array
    {
        $targetUserId = $this->buildUserId($targetIdentifier);

        $payload = [
            'preset'     => 'trusted_private_chat',
            'is_direct'  => true,
            'invite'     => [$targetUserId],
            'visibility' => 'private',
        ];

        try {
            $response = $this->client->post('/_matrix/client/v3/createRoom', [
                'headers' => [
                    'Authorization' => "Bearer {$userToken}",
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?? [];

            if ($response->getStatusCode() === 200 && !empty($body['room_id'])) {
                return [
                    'success' => true,
                    'room_id' => $body['room_id'],
                ];
            }

            return [
                'success' => false,
                'error'   => $body['error'] ?? 'Create direct chat failed',
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * 【群聊房间创建】
     *
     * @param string $userToken 创建者的 Matrix Access Token
     * @param string $roomName 群名称
     * @param array  $inviteIdentifiers 邀请加入的成员列表
     * @param bool   $isPublic 是否公开房间
     */
    public function createGroupChat(string $userToken, string $roomName, array $inviteIdentifiers = [], bool $isPublic = false): array
    {
        $invites = array_map(fn($id) => $this->buildUserId($id), $inviteIdentifiers);

        $payload = [
            'name'       => $roomName,
            'preset'     => $isPublic ? 'public_chat' : 'private_chat',
            'visibility' => $isPublic ? 'public' : 'private',
            'invite'     => $invites,
        ];

        try {
            $response = $this->client->post('/_matrix/client/v3/createRoom', [
                'headers' => [
                    'Authorization' => "Bearer {$userToken}",
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?? [];

            if ($response->getStatusCode() === 200 && !empty($body['room_id'])) {
                return [
                    'success' => true,
                    'room_id' => $body['room_id'],
                ];
            }

            return [
                'success' => false,
                'error'   => $body['error'] ?? 'Create group room failed',
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * 【系统消息推送】使用系统机器人向指定房间发送通知消息
     */
    public function sendSystemNotice(string $roomId, string $message): array
    {
        if (empty($this->botToken)) {
            return ['success' => false, 'error' => 'Bot token not configured'];
        }

        $txnId = uniqid('txn_', true);
        $payload = [
            'msgtype' => 'm.notice',
            'body'    => $message,
        ];

        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/send/m.room.message/{$txnId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->botToken}",
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?? [];

            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => $body['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * 【注册与登录通用联动】检查/创建 Matrix 用户并签发其 IM Access Token
     * 若未配置 admin_token，则静默跳过并返回 null，不影响主流程业务运行。
     *
     * @param int|string $identifier 业务系统中的用户 ID 或标识
     * @param string     $password   用户密码（可为空，为空则生成随机保护密码）
     * @param string     $displayName 用户昵称
     * @param string     $avatarUrl   用户头像
     * @return array{user_id: string, access_token: string, home_server: string, base_url: string}|null
     */
    public function syncUserOnAuth(int|string $identifier, string $password = '', string $displayName = '', string $avatarUrl = ''): ?array
    {
        if (empty($this->adminToken) || empty($this->baseUrl)) {
            return null;
        }

        try {
            $matrixUserId = $this->buildUserId($identifier);
            $imPassword = $password ?: bin2hex(random_bytes(12));

            // 1. 确保 Matrix 账号存在（创建或更新）
            $regResult = $this->registerUser($identifier, $imPassword, $displayName, $avatarUrl);
            if (!$regResult['success']) {
                Log::warning("[Matrix] syncUserOnAuth register failed for {$matrixUserId}: " . ($regResult['error'] ?? ''));
            }

            // 2. 免密换取用户专属 Matrix Access Token
            $tokenResult = $this->createTokenDirect($identifier);
            if (!$tokenResult['success'] || empty($tokenResult['access_token'])) {
                Log::warning("[Matrix] syncUserOnAuth createTokenDirect failed for {$matrixUserId}: " . ($tokenResult['error'] ?? ''));
                return null;
            }

            // 3. 记录到 app_identity（若表存在且结构匹配）
            if (is_numeric($identifier)) {
                try {
                    \think\facade\Db::name('app_identity')->replace()->insert([
                        'user_id'     => (int) $identifier,
                        'type'        => 'matrix',
                        'identifier'  => $matrixUserId,
                        'verified_at' => time(),
                        'create_time' => time(),
                        'update_time' => time(),
                    ]);
                } catch (\Throwable $e) {
                    // 忽略写入失败，避免影响认证主流程
                }
            }

            return [
                'user_id'      => $matrixUserId,
                'access_token' => $tokenResult['access_token'],
                'home_server'  => $this->serverName,
                'base_url'     => $this->baseUrl,
            ];
        } catch (\Throwable $e) {
            Log::error('[Matrix] syncUserOnAuth exception: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // 1. 群组与房间精细化管理
    // =========================================================================

    /**
     * 邀请成员进入房间/群聊
     */
    public function inviteMember(string $userToken, string $roomId, string|int $targetIdentifier): array
    {
        $targetUserId = $this->buildUserId($targetIdentifier);
        try {
            $response = $this->client->post("/_matrix/client/v3/rooms/{$roomId}/invite", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => ['user_id' => $targetUserId],
            ]);
            return [
                'success' => in_array($response->getStatusCode(), [200, 201], true),
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 将成员移出房间（踢人）
     */
    public function kickMember(string $userToken, string $roomId, string|int $targetIdentifier, string $reason = ''): array
    {
        $targetUserId = $this->buildUserId($targetIdentifier);
        try {
            $response = $this->client->post("/_matrix/client/v3/rooms/{$roomId}/kick", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => [
                    'user_id' => $targetUserId,
                    'reason'  => $reason,
                ],
            ]);
            return [
                'success' => $response->getStatusCode() === 200,
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 封禁/拉黑群成员（禁止再次加入）
     */
    public function banMember(string $userToken, string $roomId, string|int $targetIdentifier, string $reason = ''): array
    {
        $targetUserId = $this->buildUserId($targetIdentifier);
        try {
            $response = $this->client->post("/_matrix/client/v3/rooms/{$roomId}/ban", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => [
                    'user_id' => $targetUserId,
                    'reason'  => $reason,
                ],
            ]);
            return [
                'success' => $response->getStatusCode() === 200,
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 解除封禁群成员
     */
    public function unbanMember(string $userToken, string $roomId, string|int $targetIdentifier): array
    {
        $targetUserId = $this->buildUserId($targetIdentifier);
        try {
            $response = $this->client->post("/_matrix/client/v3/rooms/{$roomId}/unban", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => ['user_id' => $targetUserId],
            ]);
            return [
                'success' => $response->getStatusCode() === 200,
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 用户主动退出房间
     */
    public function leaveRoom(string $userToken, string $roomId): array
    {
        try {
            $response = $this->client->post("/_matrix/client/v3/rooms/{$roomId}/leave", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
            ]);
            return [
                'success' => $response->getStatusCode() === 200,
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 加入指定房间或群聊
     */
    public function joinRoom(string $userToken, string $roomIdOrAlias): array
    {
        try {
            $response = $this->client->post("/_matrix/client/v3/join/" . urlencode($roomIdOrAlias), [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success' => $response->getStatusCode() === 200,
                'room_id' => $body['room_id'] ?? $roomIdOrAlias,
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 获取房间成员列表（用于后台展示成员或群聊详情）
     * 优先使用 Synapse Admin API（支持管理员无需在群内也能查看全量成员）
     */
    public function getRoomMembers(string $userToken, string $roomId): array
    {
        $token = $userToken ?: $this->adminToken;

        // 1. 优先尝试 Synapse Admin API（上帝视角，无需在房间内）
        if (!empty($this->adminToken)) {
            try {
                $response = $this->client->get("/_synapse/admin/v1/rooms/{$roomId}/members", [
                    'headers' => ['Authorization' => "Bearer {$this->adminToken}"],
                ]);

                if ($response->getStatusCode() === 200) {
                    $body = json_decode((string) $response->getBody(), true) ?? [];
                    $memberIds = $body['members'] ?? [];
                    $members = [];
                    foreach ($memberIds as $mId) {
                        $members[] = [
                            'user_id'     => $mId,
                            'displayname' => $mId,
                            'avatar_url'  => '',
                        ];
                    }
                    return [
                        'success' => true,
                        'members' => $members,
                        'total'   => $body['total'] ?? count($members),
                    ];
                }
            } catch (\Throwable) {
                // 忽略异常，降级尝试 Client API
            }
        }

        // 2. 降级尝试 Client-Server API（要求调用者在房间内）
        try {
            $response = $this->client->get("/_matrix/client/v3/rooms/{$roomId}/members", [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            $members = [];
            foreach (($body['chunk'] ?? []) as $event) {
                if (($event['type'] ?? '') === 'm.room.member' && ($event['content']['membership'] ?? '') === 'join') {
                    $members[] = [
                        'user_id'     => $event['state_key'] ?? '',
                        'displayname' => $event['content']['displayname'] ?? ($event['state_key'] ?? ''),
                        'avatar_url'  => $event['content']['avatar_url'] ?? '',
                    ];
                }
            }
            return [
                'success' => $response->getStatusCode() === 200,
                'members' => $members,
                'total'   => count($members),
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'members' => []];
        }
    }

    /**
     * 设置群公告 / Topic
     */
    public function setRoomTopic(string $userToken, string $roomId, string $topic): array
    {
        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/state/m.room.topic", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => ['topic' => $topic],
            ]);
            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => json_decode((string) $response->getBody(), true)['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 群权限级别管理（用于设置群管理员 Power Level 或全员禁言）
     *
     * @param array<string|int, int> $userLevels 用户角色等级映射（如 [1001 => 50, 1002 => 100]）
     * @param int|null $eventsDefault 全员发言门槛（如设为 50 则普通成员(0)无法发言，实现全员禁言）
     */
    public function setPowerLevels(string $userToken, string $roomId, array $userLevels = [], ?int $eventsDefault = null): array
    {
        try {
            // 1. 获取现有 power_levels 状态
            $getResponse = $this->client->get("/_matrix/client/v3/rooms/{$roomId}/state/m.room.power_levels", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
            ]);
            $levels = json_decode((string) $getResponse->getBody(), true) ?? [];

            // 2. 合并更新用户级别
            foreach ($userLevels as $uid => $lvl) {
                $levels['users'][$this->buildUserId($uid)] = (int) $lvl;
            }

            // 3. 更新发言限制（全员禁言场景）
            if ($eventsDefault !== null) {
                $levels['events_default'] = $eventsDefault;
            }

            // 4. 写回状态事件
            $putResponse = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/state/m.room.power_levels", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $levels,
            ]);

            return [
                'success' => $putResponse->getStatusCode() === 200,
                'data'    => json_decode((string) $putResponse->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 【后台运营专用】通过 Synapse Admin API 强制解散并彻底清理房间
     */
    public function deleteRoomAdmin(string $roomId, bool $purge = true): array
    {
        try {
            $response = $this->client->delete("/_synapse/admin/v1/rooms/{$roomId}", [
                'headers' => ['Authorization' => "Bearer {$this->adminToken}"],
                'json'    => ['purge' => $purge],
            ]);
            return [
                'success' => $response->getStatusCode() === 200,
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 获取用户已加入的全部房间列表
     */
    public function getUserJoinedRooms(string $userToken): array
    {
        try {
            $response = $this->client->get('/_matrix/client/v3/joined_rooms', [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'      => $response->getStatusCode() === 200,
                'joined_rooms' => $body['joined_rooms'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'joined_rooms' => []];
        }
    }

    // =========================================================================
    // 2. 消息与富媒体交互增强
    // =========================================================================

    /**
     * 发送普通纯文本消息
     */
    public function sendTextMessage(string $userToken, string $roomId, string $message): array
    {
        $txnId = uniqid('txn_', true);
        $payload = [
            'msgtype' => 'm.text',
            'body'    => $message,
        ];

        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/send/m.room.message/{$txnId}", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => $body['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 发送富文本 (HTML) 消息
     */
    public function sendHtmlMessage(string $userToken, string $roomId, string $htmlBody, string $plainText = ''): array
    {
        $txnId = uniqid('txn_', true);
        $payload = [
            'msgtype'        => 'm.text',
            'body'           => $plainText ?: strip_tags($htmlBody),
            'format'         => 'org.matrix.custom.html',
            'formatted_body' => $htmlBody,
        ];

        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/send/m.room.message/{$txnId}", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => $body['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 发送自定义业务卡片消息（如订单通知、预约提醒、交互卡片）
     */
    public function sendCustomCard(string $userToken, string $roomId, string $title, array $cardPayload, string $summary = ''): array
    {
        $txnId = uniqid('txn_', true);
        $payload = [
            'msgtype'     => 'm.notice',
            'body'        => $summary ?: "[卡片消息] {$title}",
            'custom_card' => [
                'title'     => $title,
                'timestamp' => time(),
                'payload'   => $cardPayload,
            ],
        ];

        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/send/m.room.message/{$txnId}", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => $body['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 上传媒体文件到 Matrix 媒体存储库，返回 MXC 统一资源标识符 (mxc://...)
     */
    public function uploadMedia(string $userToken, string $fileContent, string $mimeType = 'application/octet-stream', string $filename = ''): array
    {
        try {
            $url = '/_matrix/media/v3/upload' . ($filename ? '?filename=' . urlencode($filename) : '');
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => "Bearer {$userToken}",
                    'Content-Type'  => $mimeType,
                ],
                'body' => $fileContent,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'     => $response->getStatusCode() === 200,
                'content_uri' => $body['content_uri'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 发送图片消息
     */
    public function sendImageMessage(string $userToken, string $roomId, string $mxcUri, string $imageName = 'image.png', array $info = []): array
    {
        $txnId = uniqid('txn_', true);
        $payload = [
            'msgtype' => 'm.image',
            'body'    => $imageName,
            'url'     => $mxcUri,
            'info'    => $info, // 可包含 w, h, mimetype, size 等
        ];

        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/send/m.room.message/{$txnId}", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => $body['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 消息撤回 / 违规内容屏蔽 (Redact)
     */
    public function redactMessage(string $userToken, string $roomId, string $eventId, string $reason = ''): array
    {
        $txnId = uniqid('txn_', true);
        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/redact/{$eventId}/{$txnId}", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => ['reason' => $reason],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => $body['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // 3. 1v1 单聊房间去重复用与历史记录
    // =========================================================================

    /**
     * 查询是否已存在与目标用户的 1v1 私聊房间（基于 m.direct 账户数据）
     */
    public function findDirectRoom(string $userToken, string|int $currentIdentifier, string|int $targetIdentifier): ?string
    {
        $currentUserId = $this->buildUserId($currentIdentifier);
        $targetUserId  = $this->buildUserId($targetIdentifier);

        try {
            $response = $this->client->get("/_matrix/client/v3/user/{$currentUserId}/account_data/m.direct", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
            ]);
            $directs = json_decode((string) $response->getBody(), true) ?? [];

            if (!empty($directs[$targetUserId]) && is_array($directs[$targetUserId])) {
                $candidate = $directs[$targetUserId][0] ?? null;
                if ($candidate) {
                    $joined = $this->getUserJoinedRooms($userToken);
                    if (in_array($candidate, $joined['joined_rooms'] ?? [], true)) {
                        return $candidate;
                    }
                }
            }
            return null;
        } catch (GuzzleException) {
            return null;
        }
    }

    /**
     * 获取或创建 1v1 私聊房间（带去重功能，已存在则直接返回旧房间 ID）
     */
    public function getOrCreateDirectChat(string $userToken, string|int $currentIdentifier, string|int $targetIdentifier): array
    {
        // 1. 尝试复用已有房间
        $existingRoom = $this->findDirectRoom($userToken, $currentIdentifier, $targetIdentifier);
        if ($existingRoom) {
            return [
                'success' => true,
                'room_id' => $existingRoom,
                'is_new'  => false,
            ];
        }

        // 2. 创建新单聊房间
        $result = $this->createDirectChat($userToken, $targetIdentifier);
        if ($result['success'] && !empty($result['room_id'])) {
            $result['is_new'] = true;
            // 异步维护 m.direct 记录
            $currentUserId = $this->buildUserId($currentIdentifier);
            $targetUserId  = $this->buildUserId($targetIdentifier);
            try {
                $this->client->put("/_matrix/client/v3/user/{$currentUserId}/account_data/m.direct", [
                    'headers' => ['Authorization' => "Bearer {$userToken}"],
                    'json'    => [$targetUserId => [$result['room_id']]],
                ]);
            } catch (\Throwable) {}
        }
        return $result;
    }

    /**
     * 拉取房间历史消息（用于客服会话回溯或消息漫游）
     */
    public function getRoomMessages(string $userToken, string $roomId, int $limit = 30, string $dir = 'b', ?string $from = null): array
    {
        $query = [
            'dir'   => $dir,
            'limit' => $limit,
        ];
        if ($from) {
            $query['from'] = $from;
        }

        try {
            $response = $this->client->get("/_matrix/client/v3/rooms/{$roomId}/messages", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'query'   => $query,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success' => $response->getStatusCode() === 200,
                'chunk'   => $body['chunk'] ?? [],
                'start'   => $body['start'] ?? '',
                'end'     => $body['end'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'chunk' => []];
        }
    }

    // =========================================================================
    // 4. 账号风控与后台运营（Synapse Admin API）
    // =========================================================================

    /**
     * 【后台运营】冻结 / 禁用 Matrix 账号（同步本地封禁）
     */
    public function deactivateUserAdmin(string|int $identifier, bool $erase = false): array
    {
        $matrixUserId = $this->buildUserId($identifier);
        try {
            $response = $this->client->post("/_synapse/admin/v1/deactivate/{$matrixUserId}", [
                'headers' => ['Authorization' => "Bearer {$this->adminToken}"],
                'json'    => ['erase' => $erase],
            ]);
            return [
                'success' => $response->getStatusCode() === 200,
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 【后台运营】重置用户 Matrix 密码并可选择注销现有设备
     */
    public function resetPasswordAdmin(string|int $identifier, string $newPassword, bool $logoutDevices = true): array
    {
        $matrixUserId = $this->buildUserId($identifier);
        try {
            $response = $this->client->post("/_synapse/admin/v1/reset_password/{$matrixUserId}", [
                'headers' => ['Authorization' => "Bearer {$this->adminToken}"],
                'json'    => [
                    'new_password'   => $newPassword,
                    'logout_devices' => $logoutDevices,
                ],
            ]);
            return [
                'success' => $response->getStatusCode() === 200,
                'data'    => json_decode((string) $response->getBody(), true) ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 【后台运营】查询用户在线设备会话列表
     */
    public function getUserDevicesAdmin(string|int $identifier): array
    {
        $matrixUserId = $this->buildUserId($identifier);
        try {
            $response = $this->client->get("/_synapse/admin/v2/users/{$matrixUserId}/devices", [
                'headers' => ['Authorization' => "Bearer {$this->adminToken}"],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success' => $response->getStatusCode() === 200,
                'devices' => $body['devices'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'devices' => []];
        }
    }

    // =========================================================================
    // 5. 音视频通话支持 (WebRTC / TURN 凭据)
    // =========================================================================

    /**
     * 获取 WebRTC 音视频呼叫所需的临时 TURN / STUN 服务器认证凭据
     */
    public function getTurnCredentials(string $userToken): array
    {
        try {
            $response = $this->client->get('/_matrix/client/v3/voip/turnServer', [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'  => $response->getStatusCode() === 200,
                'uris'     => $body['uris'] ?? [],
                'username' => $body['username'] ?? '',
                'password' => $body['password'] ?? '',
                'ttl'      => $body['ttl'] ?? 0,
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // 6. 即时互动与社交体验 (Reaction / Receipt / Typing / Presence)
    // =========================================================================

    /**
     * 对某条消息进行表情回应/点赞（Reaction）
     */
    public function sendReaction(string $userToken, string $roomId, string $targetEventId, string $emojiKey = '👍'): array
    {
        $txnId = uniqid('txn_', true);
        $payload = [
            'm.relates_to' => [
                'rel_type' => 'm.annotation',
                'event_id' => $targetEventId,
                'key'      => $emojiKey,
            ],
        ];

        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/send/m.reaction/{$txnId}", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'  => $response->getStatusCode() === 200,
                'event_id' => $body['event_id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 发送已读回执（标记某条消息已读，消除未读红点计数）
     */
    public function sendReadReceipt(string $userToken, string $roomId, string $eventId): bool
    {
        try {
            $response = $this->client->post("/_matrix/client/v3/rooms/{$roomId}/receipt/m.read/{$eventId}", [
                'headers' => [
                    'Authorization' => "Bearer {$userToken}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => (object)[],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * 发送“正在输入中...”状态
     */
    public function sendTyping(string $userToken, string $roomId, string|int $identifier, bool $typing = true, int $timeoutMs = 5000): bool
    {
        $userId = $this->buildUserId($identifier);
        try {
            $response = $this->client->put("/_matrix/client/v3/rooms/{$roomId}/typing/{$userId}", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => [
                    'typing'  => $typing,
                    'timeout' => $timeoutMs,
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * 设置用户在线状态（Presence）与个性签名
     *
     * @param string $presence 'online' | 'offline' | 'unavailable'
     */
    public function setPresence(string $userToken, string|int $identifier, string $presence = 'online', string $statusMsg = ''): bool
    {
        $userId = $this->buildUserId($identifier);
        $payload = ['presence' => $presence];
        if (!empty($statusMsg)) {
            $payload['status_msg'] = $statusMsg;
        }

        try {
            $response = $this->client->put("/_matrix/client/v3/presence/{$userId}/status", [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $payload,
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    // =========================================================================
    // 7. 搜索与广场探索
    // =========================================================================

    /**
     * 用户目录全局搜索（按昵称/账号模糊查找，用于加好友或拉人）
     */
    public function searchUsers(string $userToken, string $searchTerm, int $limit = 20): array
    {
        try {
            $response = $this->client->post('/_matrix/client/v3/user_directory/search', [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => [
                    'search_term' => $searchTerm,
                    'limit'       => $limit,
                ],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success' => $response->getStatusCode() === 200,
                'results' => $body['results'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'results' => []];
        }
    }

    /**
     * 获取公开房间广场列表（供公共群发现页展示）
     */
    public function getPublicRooms(?string $searchTerm = null, int $limit = 20, string $userToken = ''): array
    {
        $payload = ['limit' => $limit];
        if ($searchTerm) {
            $payload['filter'] = ['generic_search_term' => $searchTerm];
        }

        $token = $userToken !== '' ? $userToken : $this->adminToken;
        $headers = [];
        if (!empty($token)) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        try {
            $response = $this->client->post('/_matrix/client/v3/publicRooms', [
                'headers' => $headers,
                'json'    => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'     => $response->getStatusCode() === 200,
                'chunk'       => $body['chunk'] ?? [],
                'total_rooms' => $body['total_room_count_estimate'] ?? 0,
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'chunk' => []];
        }
    }

    /**
     * 消息全文检索
     */
    public function searchMessages(string $userToken, string $searchTerm, array $roomIds = [], int $limit = 20): array
    {
        $searchCategories = [
            'room_events' => [
                'search_term' => $searchTerm,
                'order_by'    => 'recent',
            ],
        ];
        if (!empty($roomIds)) {
            $searchCategories['room_events']['filter'] = [
                'rooms' => $roomIds,
            ];
        }

        try {
            $response = $this->client->post('/_matrix/client/v3/search', [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => ['search_categories' => $searchCategories],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success' => $response->getStatusCode() === 200,
                'results' => $body['search_categories']['room_events'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'results' => []];
        }
    }

    // =========================================================================
    // 8. 移动端离线推送 (Sygnal Pusher)
    // =========================================================================

    /**
     * 注册/更新移动端 App 离线推送设备 (Pushers)
     *
     * @param string $pushKey 设备推送 Token（如 APNs DeviceToken 或 FCM Token）
     * @param string $appId App 标识（如 cn.example.app）
     * @param string $appDisplayName 展示名称（如 iOS App）
     * @param string $url Sygnal 推送网关地址（如 http://sygnal:5000/_matrix/push/v1/notify）
     */
    public function setPusher(
        string $userToken,
        string $pushKey,
        string $appId,
        string $appDisplayName,
        string $url,
        string $lang = 'zh-CN'
    ): bool {
        $payload = [
            'pushkey'           => $pushKey,
            'kind'              => 'http',
            'app_id'            => $appId,
            'app_display_name'  => $appDisplayName,
            'device_display_name' => $appDisplayName,
            'lang'              => $lang,
            'data'              => ['url' => $url],
        ];

        try {
            $response = $this->client->post('/_matrix/client/v3/pushers/set', [
                'headers' => ['Authorization' => "Bearer {$userToken}"],
                'json'    => $payload,
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    // =========================================================================
    // 9. 自动化诊断与测试套件 (Test Suite)
    // =========================================================================

    /**
     * 服务器连通性与版本探测
     */
    public function ping(): array
    {
        try {
            $start = microtime(true);
            $response = $this->client->get('/_matrix/client/versions');
            $costMs = round((microtime(true) - $start) * 1000, 2);
            $body = json_decode((string) $response->getBody(), true) ?? [];

            return [
                'success'  => $response->getStatusCode() === 200,
                'cost_ms'  => $costMs,
                'versions' => $body['versions'] ?? [],
                'status'   => $response->getStatusCode(),
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * 一键执行全套功能集成测试并生成结构化诊断报告
     *
     * @param string|int $userA 测试用户 A
     * @param string|int $userB 测试用户 B
     * @return array{all_passed: bool, total_cost_ms: float, steps: array}
     */
    public function runTestSuite(string|int $userA = 'm_test_a', string|int $userB = 'm_test_b'): array
    {
        $steps = [];
        $startTime = microtime(true);

        $addStep = function (string $name, bool $passed, string $message = '', array $meta = []) use (&$steps) {
            $steps[] = [
                'name'    => $name,
                'passed'  => $passed,
                'message' => $message,
                'meta'    => $meta,
            ];
            return $passed;
        };

        // 步骤 1: 探测连通性
        $ping = $this->ping();
        if (!$addStep('1. 服务器连通性探测 (ping)', $ping['success'], $ping['success'] ? "连通正常, 耗时 {$ping['cost_ms']}ms" : ($ping['error'] ?? '无法连接服务器'))) {
            return [
                'all_passed'    => false,
                'total_cost_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'steps'         => $steps,
            ];
        }

        // 步骤 2: 管理员鉴权与用户注册
        $regA = $this->registerUser($userA, 'P@ssword123456!', '测试人员A');
        $regB = $this->registerUser($userB, 'P@ssword123456!', '测试人员B');
        $addStep('2. 用户自动同步注册 (Admin API)', $regA['success'] && $regB['success'], "注册 A: " . ($regA['success'] ? 'OK' : $regA['error']) . " / 注册 B: " . ($regB['success'] ? 'OK' : $regB['error']));

        // 步骤 3: 免密换取用户专属 Token
        $tokenA = $this->createTokenDirect($userA);
        $tokenB = $this->createTokenDirect($userB);
        $tokenPassed = !empty($tokenA['access_token']) && !empty($tokenB['access_token']);
        $addStep('3. 免密签发用户 Token (Admin Login)', $tokenPassed, $tokenPassed ? "用户A与用户B均成功签发专属 AccessToken" : "Token 签发失败");

        if (!$tokenPassed) {
            return [
                'all_passed'    => false,
                'total_cost_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'steps'         => $steps,
            ];
        }

        $userTokenA = $tokenA['access_token'];
        $userTokenB = $tokenB['access_token'];

        // 步骤 4: 更新资料（昵称与状态）
        $profileRes = $this->updateProfile($userA, '测试人员A(已更新)');
        $addStep('4. 用户资料修改 (Update Profile)', $profileRes, $profileRes ? '昵称更新成功' : '资料修改失败');

        // 步骤 5: 1v1 单聊房间去重创建
        $directRoom = $this->getOrCreateDirectChat($userTokenA, $userA, $userB);
        $dmPassed = !empty($directRoom['room_id']);
        $dmRoomId = $directRoom['room_id'] ?? '';
        $addStep('5. 1v1 私聊创建与去重复用 (Direct Room)', $dmPassed, $dmPassed ? "房间ID: {$dmRoomId} (新创建: " . ($directRoom['is_new'] ? '是' : '否') . ")" : ($directRoom['error'] ?? '失败'));

        $lastEventId = '';
        if ($dmPassed) {
            // 步骤 6: 发送富文本与卡片消息
            $htmlMsg = $this->sendHtmlMessage($userTokenA, $dmRoomId, '<b>你好！</b> 这是来自 MatrixService 的<strong>自动化集成测试消息</strong>。');
            $cardMsg = $this->sendCustomCard($userTokenA, $dmRoomId, '测试订单推送', ['order_id' => 'TEST_1001', 'amount' => '$99.00']);
            $msgPassed = !empty($htmlMsg['event_id']) && !empty($cardMsg['event_id']);
            $lastEventId = $htmlMsg['event_id'] ?? '';
            $addStep('6. 发送富文本及卡片消息 (Send Messages)', $msgPassed, $msgPassed ? "富文本与卡片消息已成功投递到单聊房间" : "消息发送失败");

            // 步骤 7: 即时互动（表情点赞与已读回执）
            if ($lastEventId) {
                // 用户 B 接受邀请进入单聊房间
                $this->joinRoom($userTokenB, $dmRoomId);

                $react = $this->sendReaction($userTokenB, $dmRoomId, $lastEventId, '🚀');
                $receipt = $this->sendReadReceipt($userTokenB, $dmRoomId, $lastEventId);
                $typing = $this->sendTyping($userTokenB, $dmRoomId, $userB, true, 3000);
                $addStep('7. 即时互动 (点赞Reaction / 已读回执 / 正在输入)', !empty($react['event_id']) && $receipt && $typing, "点赞/回执/输入状态反馈均正常");
            }
        }

        // 步骤 8: 群聊创建与权限控制
        $group = $this->createGroupChat($userTokenA, '自动化测试群组', [$userB]);
        $grpPassed = !empty($group['room_id']);
        $grpRoomId = $group['room_id'] ?? '';
        $addStep('8. 创建群组与拉人 (Create Group)', $grpPassed, $grpPassed ? "群房间ID: {$grpRoomId}" : ($group['error'] ?? '失败'));

        if ($grpPassed) {
            $topicRes = $this->setRoomTopic($userTokenA, $grpRoomId, '这是由自动化测试设置的群公告');
            $permRes = $this->setPowerLevels($userTokenA, $grpRoomId, [$userB => 50]);
            $addStep('9. 群公告与权限分配 (Topic & PowerLevels)', !empty($topicRes['event_id']) && $permRes['success'], "群公告已更新，成员B已提拔为房管(50)");
        }

        // 步骤 9: 搜索与广场探索
        $userSearch = $this->searchUsers($userTokenA, '测试人员', 5);
        $publicRooms = $this->getPublicRooms(null, 5);
        $searchPassed = $userSearch['success'] && $publicRooms['success'];
        $addStep('10. 搜索与广场探索 (Search Users & Public Rooms)', $searchPassed, "用户搜索与广场列表接口调用正常");

        // 步骤 10: 资源清理与解散
        $cleaned = true;
        if ($dmRoomId) {
            $del1 = $this->deleteRoomAdmin($dmRoomId);
            $cleaned = $cleaned && $del1['success'];
        }
        if ($grpRoomId) {
            $del2 = $this->deleteRoomAdmin($grpRoomId);
            $cleaned = $cleaned && $del2['success'];
        }
        $addStep('11. 资源回收与销毁 (Delete Rooms Admin)', $cleaned, "测试房间已安全解散清理");

        $allPassed = !in_array(false, array_column($steps, 'passed'), true);

        return [
            'all_passed'    => $allPassed,
            'total_cost_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'steps'         => $steps,
        ];
    }

    /**
     * 【反向对账】获取 Matrix 上的全量房间列表（Synapse Admin API）
     */
    public function getAllRoomsAdmin(int $limit = 100, ?string $from = null, string $searchTerm = ''): array
    {
        $query = ['limit' => $limit];
        if ($from) {
            $query['from'] = $from;
        }
        if ($searchTerm !== '') {
            $query['search_term'] = $searchTerm;
        }

        try {
            $response = $this->client->get('/_synapse/admin/v1/rooms', [
                'headers' => ['Authorization' => "Bearer {$this->adminToken}"],
                'query'   => $query,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];
            return [
                'success'     => $response->getStatusCode() === 200,
                'rooms'       => $body['rooms'] ?? [],
                'total_rooms' => $body['total_rooms'] ?? 0,
                'next_batch'  => $body['next_batch'] ?? '',
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'rooms' => []];
        }
    }

    /**
     * 【风控强化】一键下线指定用户的所有设备会话
     */
    public function revokeAllDevicesAdmin(string|int $identifier): array
    {
        $matrixUserId = $this->buildUserId($identifier);
        try {
            $devicesRes = $this->getUserDevicesAdmin($identifier);
            $deviceIds = array_column($devicesRes['devices'] ?? [], 'device_id');
            if (empty($deviceIds)) {
                return ['success' => true, 'count' => 0];
            }

            $response = $this->client->post("/_synapse/admin/v2/users/{$matrixUserId}/delete_devices", [
                'headers' => ['Authorization' => "Bearer {$this->adminToken}"],
                'json'    => ['devices' => $deviceIds],
            ]);

            return [
                'success' => $response->getStatusCode() === 200,
                'count'   => count($deviceIds),
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'count' => 0];
        }
    }

    /**
     * 检测系统机器人 (Bot) 的存活状态与配置
     */
    public function getBotStatus(): array
    {
        if (empty($this->botToken) || empty($this->botUserId)) {
            return [
                'configured' => false,
                'user_id'    => $this->botUserId,
                'valid'      => false,
                'status'     => '未配置',
                'device_id'  => '',
            ];
        }

        try {
            $response = $this->client->get('/_matrix/client/v3/account/whoami', [
                'headers' => ['Authorization' => "Bearer {$this->botToken}"],
            ]);
            $body = json_decode((string) $response->getBody(), true) ?? [];

            if ($response->getStatusCode() === 200 && !empty($body['user_id'])) {
                return [
                    'configured' => true,
                    'user_id'    => $body['user_id'],
                    'valid'      => true,
                    'status'     => '正常在线',
                    'device_id'  => $body['device_id'] ?? '',
                ];
            }

            return [
                'configured' => true,
                'user_id'    => $this->botUserId,
                'valid'      => false,
                'status'     => 'Token 失效',
                'error'      => $body['error'] ?? 'Authentication failed',
                'device_id'  => '',
            ];
        } catch (GuzzleException $e) {
            return [
                'configured' => true,
                'user_id'    => $this->botUserId,
                'valid'      => false,
                'status'     => '连接超时',
                'error'      => $e->getMessage(),
                'device_id'  => '',
            ];
        }
    }
}






