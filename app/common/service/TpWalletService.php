<?php

// +----------------------------------------------------------------------
// | BADOUCMS [ 八斗网站系统 ]
// +----------------------------------------------------------------------
// | TokenPocket 钱包 DeepLink 授权登录与注册服务
// +----------------------------------------------------------------------

namespace app\common\service;

use app\common\library\FrontendAuth;
use app\common\library\Wallet;
use app\common\model\User as UserModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Request;

/**
 * TokenPocket 钱包 DeepLink 认证服务
 */
class TpWalletService
{
    /**
     * Ticket 会话缓存前缀
     */
    public const CACHE_TICKET_PREFIX = 'tp_ticket:';

    /**
     * 授权有效期（秒），默认 5 分钟
     */
    public const TTL_SECONDS = 300;

    /**
     * TP 钱包 URL Scheme 协议前缀
     */
    public const SCHEME_URL = 'tpoutside://pull.activity?param=';

    /**
     * TP 钱包 Universal Link 官方链接
     */
    public const UNIVERSAL_LINK_URL = 'https://tokenpocket.github.io/applink?param=';

    /**
     * 发起 TP 钱包 DeepLink 授权（生成唤起协议与签名挑战）
     *
     * @param array $options 启动参数
     * @return array
     */
    public function createAuthSession(array $options = []): array
    {
        $address = isset($options['address']) ? trim((string)$options['address']) : '';
        if ($address !== '' && !Wallet::validateAddress($address)) {
            throw new \InvalidArgumentException('钱包地址格式不正确');
        }

        $formattedAddress = $address !== '' ? Wallet::formatAddress($address) : '0x0000000000000000000000000000000000000000';
        $chainId = isset($options['chain_id']) ? (int)$options['chain_id'] : 1;
        $inviteCode = isset($options['invite_code']) ? trim((string)$options['invite_code']) : '';

        // 生成全局唯一的 Ticket 票据与安全 Nonce
        $ticket = 'tp_' . bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $domain = Request::host() ?: 'itiklink.com';
        $baseUrl = Request::domain() ?: ('https://' . $domain);
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z');

        // 生成符合 EIP-4361 (SIWE) 标准的签名文本
        $message = sprintf(
            "%s wants you to sign in with your Ethereum account:\n" .
            "%s\n\n" .
            "Sign in to ITIK with TokenPocket Wallet.\n\n" .
            "URI: %s\n" .
            "Version: 1\n" .
            "Chain ID: %d\n" .
            "Nonce: %s\n" .
            "Issued At: %s",
            $domain,
            $formattedAddress,
            $baseUrl,
            $chainId,
            $nonce,
            $issuedAt
        );

        // 回调地址（若前端未指定，则默认回调到服务端本接口）
        $callbackUrl = !empty($options['callback_url'])
            ? (string)$options['callback_url']
            : ($baseUrl . '/api/v1/auth/wallet/tp-login');

        $dappName = !empty($options['dapp_name']) ? (string)$options['dapp_name'] : 'ITIK';
        $dappIcon = !empty($options['dapp_icon']) ? (string)$options['dapp_icon'] : ($baseUrl . '/assets/img/logo.png');

        // 组装 TP Pull 规范参数
        $tpParams = [
            'action'      => 'sign',
            'actionId'    => $ticket,
            'protocol'    => 'TokenPocket',
            'version'     => '1.0',
            'dappName'    => $dappName,
            'dappIcon'    => $dappIcon,
            'message'     => $message,
            'callbackUrl' => $callbackUrl,
            'blockchains' => [
                [
                    'chainId' => (string)$chainId,
                    'network' => 'ethereum'
                ]
            ]
        ];

        $jsonParam = json_encode($tpParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encodedParam = urlencode($jsonParam);

        $deeplink = self::SCHEME_URL . $encodedParam;
        $universalLink = self::UNIVERSAL_LINK_URL . $encodedParam;

        // 缓存本次 Ticket 会话信息
        $sessionData = [
            'ticket'      => $ticket,
            'status'      => 'pending',
            'address'     => $address !== '' ? $formattedAddress : '',
            'nonce'       => $nonce,
            'message'     => $message,
            'chain_id'    => $chainId,
            'invite_code' => $inviteCode,
            'created_at'  => time(),
            'expire_at'   => time() + self::TTL_SECONDS,
        ];

        Cache::set(self::CACHE_TICKET_PREFIX . $ticket, $sessionData, self::TTL_SECONDS);

        return [
            'ticket'         => $ticket,
            'deeplink'       => $deeplink,
            'universal_link' => $universalLink,
            'message'        => $message,
            'nonce'          => $nonce,
            'expire'         => self::TTL_SECONDS,
            'tp_params'      => $tpParams,
        ];
    }

    /**
     * 校验钱包签名并执行自动登入与注册
     *
     * @param string      $address    钱包地址
     * @param string      $signature  以太坊签名结果 (0x...)
     * @param string|null $ticket     可选的会话 Ticket
     * @param string|null $message    可选的原始签名消息
     * @param string      $inviteCode 可选的推荐人邀请码
     * @return array
     */
    public function verifyAndLogin(
        string $address,
        string $signature,
        ?string $ticket = null,
        ?string $message = null,
        string $inviteCode = ''
    ): array {
        if (!Wallet::validateAddress($address)) {
            throw new \InvalidArgumentException('钱包地址格式不正确');
        }

        $walletAddress = Wallet::formatAddress($address);
        $signature = trim($signature);

        if (!preg_match('/^0x[a-fA-F0-9]{130}$/', $signature)) {
            throw new \InvalidArgumentException('签名格式错误');
        }

        $targetMessage = $message;
        $sessionData = null;

        if ($ticket) {
            $sessionData = Cache::get(self::CACHE_TICKET_PREFIX . $ticket);
            if (!$sessionData) {
                throw new \RuntimeException('授权会话已过期，请重新唤起钱包');
            }
            if (empty($targetMessage)) {
                $targetMessage = (string)($sessionData['message'] ?? '');
            }
            if ($inviteCode === '' && !empty($sessionData['invite_code'])) {
                $inviteCode = (string)$sessionData['invite_code'];
            }
        }

        if (empty($targetMessage)) {
            throw new \InvalidArgumentException('缺少签名验证原文');
        }

        // 调用底层 secp256k1 椭圆曲线还原签名地址
        $recoveredAddress = Wallet::recoverAddress($targetMessage, $signature);
        if (!$recoveredAddress || strtolower($recoveredAddress) !== $walletAddress) {
            throw new \RuntimeException('签名验证失败，签名公钥与钱包地址不匹配');
        }

        // 验签成功后，若有 Ticket 则将该票据设为已消费，防止重放攻击
        if ($ticket) {
            Cache::delete(self::CACHE_TICKET_PREFIX . $ticket);
        }

        $auth = FrontendAuth::instance();
        $isNewUser = false;

        // 开启数据库事务执行用户查询/自动注册
        UserModel::startTrans();
        try {
            // 1. 查询用户是否已注册
            $user = UserModel::getByWalletAddress($walletAddress);
            if (!$user) {
                // 兼容查询 identity 表
                $identity = Db::name('app_identity')
                    ->where('type', 'wallet')
                    ->where('identifier', $walletAddress)
                    ->find();
                if ($identity) {
                    $user = UserModel::find((int)$identity['user_id']);
                    if ($user && empty($user->wallet_address)) {
                        $user->wallet_address = $walletAddress;
                        $user->save();
                    }
                }
            }

            if ($user) {
                // 已有账号：检查账号状态
                if ($user->status !== 'normal') {
                    throw new \RuntimeException('账号已被锁定，请联系管理员');
                }
                $userId = (int)$user->id;
                $auth->direct($userId);
            } else {
                // 自动注册新用户
                $isNewUser = true;

                $baseName = 'w_' . substr($walletAddress, -8);
                $username = $baseName;
                if (UserModel::getByUsername($username)) {
                    $username = $baseName . '_' . mt_rand(100, 999);
                }

                $nickname = substr($walletAddress, 0, 6) . '...' . substr($walletAddress, -4);
                $visitorGroupId = (int)(Db::name('user_group')->where('rules', 'VISITOR')->value('id') ?: 0);
                $randomPassword = bin2hex(random_bytes(10));

                $extend = [
                    'nickname'       => $nickname,
                    'group_id'       => $visitorGroupId,
                    'wallet_address' => $walletAddress,
                ];
                if ($inviteCode !== '') {
                    $extend['invite_code'] = $inviteCode;
                }

                $registerResult = $auth->register($username, $randomPassword, '', '', $extend);
                if (!$registerResult) {
                    throw new \RuntimeException((string)$auth->getError() ?: '创建新用户失败');
                }

                $userId = (int)$auth->id;

                // 记录钱包身份凭证
                Db::name('app_identity')->insert([
                    'user_id'     => $userId,
                    'type'        => 'wallet',
                    'identifier'  => $walletAddress,
                    'verified_at' => time(),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                $user = $auth->getUser();
            }

            UserModel::commit();
        } catch (\Throwable $e) {
            UserModel::rollback();
            throw $e;
        }

        // 同步 IM (Matrix) 账号，非关键业务异常内部已兜底
        $matrixAuth = (new MatrixService())->syncUserOnAuth($userId, '', (string)($user->nickname ?? ''));

        // 存在推荐人时，自动在 IM 里互加为好友
        $inviterId = (int)($user->inviter_id ?? 0);
        if ($inviterId > 0) {
            (new MatrixService())->addDirectFriend((int)$user->id, (string)($matrixAuth['access_token'] ?? ''), $inviterId);
        }

        $resultData = [
            'user_id'        => $userId,
            'username'       => (string)$user->username,
            'nickname'       => (string)$user->nickname,
            'wallet_address' => (string)$user->wallet_address,
            'invite_code'    => (string)$user->invite_code,
            'inviter_id'     => (int)$user->inviter_id,
            'is_new_user'    => $isNewUser,
            'token'          => $auth->getToken(),
            'matrix'         => $matrixAuth,
        ];

        // 若传入了 ticket，把成功登录状态回写缓存，便于页面轮询获取
        if ($ticket) {
            Cache::set(self::CACHE_TICKET_PREFIX . $ticket, [
                'status'    => 'success',
                'data'      => $resultData,
                'expire_at' => time() + self::TTL_SECONDS,
            ], self::TTL_SECONDS);
        }

        return $resultData;
    }

    /**
     * 查询 Ticket 授权状态（供前端或轮询使用）
     *
     * @param string $ticket
     * @return array
     */
    public function checkTicketStatus(string $ticket): array
    {
        $cached = Cache::get(self::CACHE_TICKET_PREFIX . $ticket);
        if (!$cached) {
            return [
                'status'  => 'expired',
                'message' => '授权会话已过期或不存在'
            ];
        }

        if ($cached['status'] === 'success') {
            return [
                'status' => 'success',
                'data'   => $cached['data'] ?? []
            ];
        }

        return [
            'status'  => 'pending',
            'message' => '等待钱包端授权签名'
        ];
    }
}
