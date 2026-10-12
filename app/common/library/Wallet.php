<?php

// +----------------------------------------------------------------------
// | BADOUCMS [ 八斗网站系统 ]
// +----------------------------------------------------------------------
// | 去中心化钱包服务（EVM / 以太坊 personal_sign 验签与 Challenge 质询）
// +----------------------------------------------------------------------

namespace app\common\library;

use Elliptic\EC;
use kornrunner\Keccak;
use think\facade\Cache;
use think\facade\Config;

/**
 * 去中心化钱包服务类
 */
class Wallet
{
    /**
     * 错误信息
     */
    protected static $error = '';

    /**
     * Challenge 缓存前缀
     */
    protected const CACHE_PREFIX = 'wallet_challenge:';

    /**
     * 校验钱包地址格式（0x 开头，40 位 16 进制字符）
     *
     * @param string $address
     * @return bool
     */
    public static function validateAddress(string $address): bool
    {
        return (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', trim($address));
    }

    /**
     * 格式化钱包地址（统一为小写，确保索引与匹配唯一性）
     *
     * @param string $address
     * @return string
     */
    public static function formatAddress(string $address): string
    {
        return strtolower(trim($address));
    }

    /**
     * 生成钱包签名挑战（Challenge / Nonce）
     * 符合 EIP-4361 (SIWE - Sign-In with Ethereum) 核心规范
     *
     * @param string $address 钱包地址
     * @param int    $ttl     有效期（秒），默认 300 秒（5分钟）
     * @return array|false
     */
    public static function createChallenge(string $address, int $ttl = 300)
    {
        if (!self::validateAddress($address)) {
            self::$error = '钱包地址格式不正确';
            return false;
        }

        $address = self::formatAddress($address);
        $nonce = bin2hex(random_bytes(16)); // 32位随机安全字符串
        $domain = request()->host() ?: 'itiklink.com';
        $uri = request()->domain() ?: ('https://' . $domain);
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z');

        // 标准 EIP-4361 / SIWE 签名文本模板
        $message = sprintf(
            "%s wants you to sign in with your Ethereum account:\n" .
            "%s\n\n" .
            "Sign in to ITIK with your decentralized wallet.\n\n" .
            "URI: %s\n" .
            "Version: 1\n" .
            "Chain ID: 1\n" .
            "Nonce: %s\n" .
            "Issued At: %s",
            $domain,
            $address,
            $uri,
            $nonce,
            $issuedAt
        );

        $payload = [
            'address'   => $address,
            'nonce'     => $nonce,
            'message'   => $message,
            'issued_at' => $issuedAt,
            'expire'    => time() + $ttl,
        ];

        // 缓存 Challenge，Key 绑定地址小写
        Cache::set(self::CACHE_PREFIX . $address, $payload, $ttl);

        return [
            'address'  => $address,
            'nonce'    => $nonce,
            'message'  => $message,
            'expire'   => $ttl,
        ];
    }

    /**
     * 验证钱包签名
     *
     * @param string $address   声明的钱包地址
     * @param string $signature 客户端钱包签名结果（0x 开头的 65 字节十六进制）
     * @param string|null $message 待签名的原始消息（若为空则自动取缓存的 Challenge 消息）
     * @return bool
     */
    public static function verifySignature(string $address, string $signature, ?string $message = null): bool
    {
        self::$error = '';

        if (!self::validateAddress($address)) {
            self::$error = '钱包地址格式不正确';
            return false;
        }

        $address = self::formatAddress($address);
        $cacheKey = self::CACHE_PREFIX . $address;
        $cached = Cache::get($cacheKey);

        if (!$cached || empty($cached['message'])) {
            self::$error = '签名挑战已过期或不存在，请重新获取';
            return false;
        }

        // 优先使用服务端缓存的准确 message，防止重放与篡改
        $targetMessage = $cached['message'];

        // 验证签名格式
        $signature = trim($signature);
        if (!preg_match('/^0x[a-fA-F0-9]{130}$/', $signature)) {
            self::$error = '签名格式错误';
            return false;
        }

        try {
            // 恢复以太坊地址
            $recoveredAddress = self::recoverAddress($targetMessage, $signature);
            if (!$recoveredAddress || strtolower($recoveredAddress) !== $address) {
                self::$error = '签名验证失败，公钥地址不匹配';
                return false;
            }

            // 验签成功后，立即销毁缓存（一次性消费，杜绝重放攻击）
            Cache::delete($cacheKey);
            return true;
        } catch (\Throwable $e) {
            self::$error = '签名恢复异常: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 从以太坊 personal_sign 恢复签名者地址
     *
     * @param string $message   原始消息内容
     * @param string $signature 签名十六进制
     * @return string|false     成功返回 0x... 小写地址，失败返回 false
     */
    public static function recoverAddress(string $message, string $signature)
    {
        // 1. 去除 0x 前缀并拆分 r, s, v
        $sig = substr($signature, 2);
        if (strlen($sig) !== 130) {
            return false;
        }

        $r = substr($sig, 0, 64);
        $s = substr($sig, 64, 64);
        $v = substr($sig, 128, 2);

        $recId = hexdec($v);
        // 处理标准的 27 / 28，或 EIP-155 链 ID 衍生值
        if ($recId >= 27) {
            $recId -= 27;
        }

        // 2. 以太坊 personal_sign 消息加前缀
        $prefix = "\x19Ethereum Signed Message:\n" . strlen($message);
        $msgHash = Keccak::hash($prefix . $message, 256);

        // 3. secp256k1 椭圆曲线公钥恢复
        $ec = new EC('secp256k1');
        $pubKey = $ec->recoverPubKey($msgHash, ['r' => $r, 's' => $s], $recId);
        if (!$pubKey) {
            return false;
        }

        // 4. 导出未压缩公钥十六进制（去掉开头的 04 未压缩标识）
        $pubHex = $pubKey->encode('hex');
        $pubWithoutPrefix = substr($pubHex, 2);

        // 5. 对 64 字节公钥做 Keccak-256 哈希，截取最后 20 字节（40 个 16 进制字符）
        $hash = Keccak::hash(hex2bin($pubWithoutPrefix), 256);
        $recoveredAddress = '0x' . substr($hash, -40);

        return strtolower($recoveredAddress);
    }

    /**
     * 获取最新错误信息
     *
     * @return string
     */
    public static function getError(): string
    {
        return self::$error;
    }
}
