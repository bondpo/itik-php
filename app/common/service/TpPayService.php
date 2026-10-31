<?php

// +----------------------------------------------------------------------
// | TokenPocket DeepLink 链上 USDT 支付（BSC）
// | tp-init → transfer deeplink → TP POST tp-callback → 验链 → 入账
// +----------------------------------------------------------------------

namespace app\common\service;

use app\common\service\app\SubscriptionService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Request;

class TpPayService
{
    public const CACHE_PREFIX = 'tp_pay:';
    public const CACHE_ORDER_PREFIX = 'tp_pay_order:';
    public const TTL_SECONDS = 3600;

    public const USDT_CONTRACT = '0x55d398326f99059fF775485246999027B3197955';
    public const USDT_DECIMALS = 18;
    public const CHAIN_ID = '56';
    public const TOPIC_TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    public const SCHEME_URL = 'tpoutside://pull.activity?param=';

    /**
     * @return array{action_id:string,order_no:string,to:string,amount:float,deeplink:string,expire:int,tp_params:array,auto_paid?:bool}
     */
    public function createPaySession(int $userId, string $orderNo): array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '' || $userId <= 0) {
            throw new \InvalidArgumentException('参数无效');
        }

        $order = Db::name('app_order')->where('order_no', $orderNo)->where('user_id', $userId)->find();
        if (!$order) {
            throw new \RuntimeException('订单不存在');
        }
        if ((string)$order['status'] !== SubscriptionService::ORDER_PENDING) {
            throw new \RuntimeException('订单状态不可支付：' . $order['status']);
        }
        if ((int)$order['expire_at'] > 0 && (int)$order['expire_at'] < time()) {
            throw new \RuntimeException('订单已过期');
        }

        $usd = (float)($order['usd'] ?? 0);
        if ($usd <= 0) {
            $svc = new SubscriptionService();
            $paid = $svc->paySuccess($orderNo, 'BLC', 'blc-' . $orderNo, ['source' => 'tp-init-blc']);
            return [
                'action_id' => '',
                'order_no'  => $orderNo,
                'to'        => '',
                'amount'    => 0.0,
                'deeplink'  => '',
                'expire'    => 0,
                'tp_params' => [],
                'auto_paid' => true,
                'order'     => $paid['order'] ?? [],
            ];
        }

        $to = $this->receiveAddress();
        if ($to === '') {
            throw new \RuntimeException('收款地址未配置');
        }

        $oldActionId = (string)Cache::get(self::CACHE_ORDER_PREFIX . $orderNo, '');
        if ($oldActionId !== '') {
            $old = Cache::get(self::CACHE_PREFIX . $oldActionId);
            if (is_array($old) && (int)($old['expire_at'] ?? 0) > time() && ($old['status'] ?? '') === 'pending') {
                return $this->formatSessionResponse($old);
            }
        }

        $ttl = self::TTL_SECONDS;
        $expireAtOrder = (int)($order['expire_at'] ?? 0);
        if ($expireAtOrder > time()) {
            $ttl = min($ttl, $expireAtOrder - time());
        }
        $ttl = max(60, $ttl);

        $actionId = 'pay_' . bin2hex(random_bytes(16));
        $baseUrl = Request::domain() ?: ('https://' . (Request::host() ?: 'm.itiklink.com'));
        $callbackUrl = $baseUrl . '/api/v1/billing/pay/tp-callback';
        $dappName = 'ITIK';
        $dappIcon = $baseUrl . '/assets/img/logo.png';

        $tpParams = [
            'protocol'    => 'TokenPocket',
            'version'     => '2.0',
            'dappName'    => $dappName,
            'dappIcon'    => $dappIcon,
            'action'      => 'transfer',
            'actionId'    => $actionId,
            'to'          => $to,
            'amount'      => $usd,
            'contract'    => self::USDT_CONTRACT,
            'symbol'      => 'USDT',
            'decimal'     => self::USDT_DECIMALS,
            'desc'        => 'order:' . $orderNo,
            'memo'        => 'order:' . $orderNo,
            'callbackUrl' => $callbackUrl,
            'blockchains' => [
                ['chainId' => self::CHAIN_ID, 'network' => 'ethereum'],
            ],
        ];

        $encoded = urlencode(json_encode($tpParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $deeplink = self::SCHEME_URL . $encoded;

        $session = [
            'action_id'  => $actionId,
            'order_no'   => $orderNo,
            'user_id'    => $userId,
            'to'         => $to,
            'amount'     => $usd,
            'contract'   => self::USDT_CONTRACT,
            'status'     => 'pending',
            'tx_hash'    => '',
            'deeplink'   => $deeplink,
            'tp_params'  => $tpParams,
            'created_at' => time(),
            'expire_at'  => time() + $ttl,
            'expire'     => $ttl,
        ];

        Cache::set(self::CACHE_PREFIX . $actionId, $session, $ttl);
        Cache::set(self::CACHE_ORDER_PREFIX . $orderNo, $actionId, $ttl);

        try {
            Db::name('app_order')->where('id', $order['id'])->update([
                'pay_action_id' => $actionId,
                'update_time'   => time(),
            ]);
        } catch (\Throwable $e) {
            // 无 pay_action_id 列时忽略
        }

        return $this->formatSessionResponse($session);
    }

    /**
     * @param array $raw TP POST 原始数据
     * @return array{ok:bool,repeated:bool,order_no:string,tx_hash:string,message:string}
     */
    public function handleCallback(array $raw): array
    {
        $actionId = $this->pickStr($raw, 'actionId');
        if ($actionId === '' && isset($raw['action_id'])) {
            $actionId = trim((string)$raw['action_id']);
        }

        $result = $raw['result'] ?? null;
        if ($result === null && isset($raw['data']) && is_array($raw['data'])) {
            $data = $raw['data'];
            if ($actionId === '') {
                $actionId = $this->pickStr($data, 'actionId');
            }
            if (isset($data['result'])) {
                $result = $data['result'];
            }
            $raw = array_merge($raw, $data);
        }

        $txHash = $this->extractTxHash($raw);
        $action = $this->pickStr($raw, 'action');

        if ($actionId === '') {
            Log::warning('[tp-pay] callback missing actionId', ['raw' => $raw]);
            return ['ok' => false, 'repeated' => false, 'order_no' => '', 'tx_hash' => '', 'message' => 'missing actionId'];
        }

        $session = Cache::get(self::CACHE_PREFIX . $actionId);
        if (!is_array($session)) {
            Log::warning('[tp-pay] session expired', ['actionId' => $actionId]);
            return ['ok' => false, 'repeated' => false, 'order_no' => '', 'tx_hash' => $txHash, 'message' => 'session expired'];
        }

        $orderNo = (string)($session['order_no'] ?? '');

        if ((string)$result === '0' || (int)$result === 0) {
            $session['status'] = 'cancelled';
            Cache::set(self::CACHE_PREFIX . $actionId, $session, 600);
            return ['ok' => false, 'repeated' => false, 'order_no' => $orderNo, 'tx_hash' => '', 'message' => 'user cancelled'];
        }

        if ($txHash === '') {
            return ['ok' => false, 'repeated' => false, 'order_no' => $orderNo, 'tx_hash' => '', 'message' => 'missing tx hash'];
        }

        if (($session['status'] ?? '') === 'confirmed' && strcasecmp((string)($session['tx_hash'] ?? ''), $txHash) === 0) {
            return ['ok' => true, 'repeated' => true, 'order_no' => $orderNo, 'tx_hash' => $txHash, 'message' => 'already confirmed'];
        }

        $this->verifyBscUsdtTransfer(
            $txHash,
            (string)$session['to'],
            (float)$session['amount'],
            (string)($session['contract'] ?? self::USDT_CONTRACT)
        );

        $svc = new SubscriptionService();
        $ret = $svc->paySuccess($orderNo, 'USDT', $txHash, [
            'source'   => 'tp-callback',
            'actionId' => $actionId,
            'action'   => $action,
            'raw'      => $raw,
        ]);

        $order = $ret['order'] ?? Db::name('app_order')->where('order_no', $orderNo)->find();
        if ($order) {
            if ((string)$order['status'] === SubscriptionService::ORDER_BROADCAST) {
                Db::name('app_order')->where('id', $order['id'])->update([
                    'status'      => SubscriptionService::ORDER_PAID,
                    'update_time' => time(),
                ]);
                $order['status'] = SubscriptionService::ORDER_PAID;
            }
            if ((string)$order['status'] === SubscriptionService::ORDER_PAID) {
                try {
                    (new Ds())->orderAct((int)$order['id']);
                } catch (\Throwable $e) {
                    Log::error('[tp-pay] orderAct fail: ' . $e->getMessage());
                }
            }
        }

        $session['status'] = 'confirmed';
        $session['tx_hash'] = $txHash;
        Cache::set(self::CACHE_PREFIX . $actionId, $session, 86400);

        return [
            'ok'       => true,
            'repeated' => !empty($ret['repeated']),
            'order_no' => $orderNo,
            'tx_hash'  => $txHash,
            'message'  => 'ok',
        ];
    }

    public function verifyBscUsdtTransfer(string $txHash, string $to, float $amountUsd, string $contract = self::USDT_CONTRACT): void
    {
        $txHash = strtolower(trim($txHash));
        if (!preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw new \InvalidArgumentException('交易哈希格式错误');
        }

        $to = strtolower($this->normalizeAddress($to));
        $contract = strtolower($this->normalizeAddress($contract));
        if ($to === '' || $contract === '') {
            throw new \RuntimeException('收款或合约地址无效');
        }

        $receipt = $this->rpc('eth_getTransactionReceipt', [$txHash]);
        if (!is_array($receipt) || empty($receipt)) {
            throw new \RuntimeException('链上尚未找到该交易，请稍后重试');
        }

        $status = strtolower((string)($receipt['status'] ?? '0x0'));
        if ($status !== '0x1' && $status !== '1') {
            throw new \RuntimeException('链上交易失败');
        }

        $expectWei = $this->usdToWei($amountUsd);
        $matched = false;
        $logs = is_array($receipt['logs'] ?? null) ? $receipt['logs'] : [];

        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $logAddr = strtolower((string)($log['address'] ?? ''));
            if ($logAddr !== $contract) {
                continue;
            }
            $topics = $log['topics'] ?? [];
            if (!is_array($topics) || count($topics) < 3) {
                continue;
            }
            if (strtolower((string)$topics[0]) !== self::TOPIC_TRANSFER) {
                continue;
            }
            $toFromTopic = $this->addressFromTopic(strtolower((string)$topics[2]));
            if ($toFromTopic !== $to) {
                continue;
            }
            $valueWei = $this->hexToDecString((string)($log['data'] ?? '0x0'));
            if ($this->bcCompare($valueWei, $expectWei) >= 0) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new \RuntimeException('未匹配到足额 USDT 转账（收款地址或金额不符）');
        }
    }

    protected function formatSessionResponse(array $session): array
    {
        return [
            'action_id' => (string)$session['action_id'],
            'order_no'  => (string)$session['order_no'],
            'to'        => (string)$session['to'],
            'amount'    => (float)$session['amount'],
            'deeplink'  => (string)$session['deeplink'],
            'expire'    => (int)($session['expire'] ?? self::TTL_SECONDS),
            'tp_params' => $session['tp_params'] ?? [],
        ];
    }

    protected function receiveAddress(): string
    {
        $w = get_sys_config('wallet');
        if (is_array($w)) {
            $w = $w['address'] ?? ($w['wallet'] ?? '');
        }
        return $this->normalizeAddress((string)$w);
    }

    protected function normalizeAddress(string $addr): string
    {
        $addr = trim($addr);
        if ($addr === '') {
            return '';
        }
        if (strpos($addr, '0x') !== 0 && strpos($addr, '0X') !== 0) {
            $addr = '0x' . $addr;
        }
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $addr)) {
            return '';
        }
        return $addr;
    }

    protected function pickStr(array $o, string $key): string
    {
        if (!isset($o[$key])) {
            return '';
        }
        $v = trim((string)$o[$key]);
        if ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'undefined') {
            return '';
        }
        return $v;
    }

    protected function extractTxHash(array $raw): string
    {
        foreach (['txID', 'txId', 'txHash', 'tx_hash', 'hash', 'transactionHash', 'trade_no'] as $k) {
            $h = $this->pickStr($raw, $k);
            if (preg_match('/^0x[a-fA-F0-9]{64}$/', $h)) {
                return strtolower($h);
            }
        }
        return '';
    }

    protected function usdToWei(float $usd): string
    {
        $s = number_format($usd, 8, '.', '');
        if (function_exists('bcmul')) {
            return bcmul($s, bcpow('10', (string)self::USDT_DECIMALS, 0), 0);
        }
        return (string)(int)round($usd * (10 ** self::USDT_DECIMALS));
    }

    protected function hexToDecString(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (strpos($hex, '0x') === 0) {
            $hex = substr($hex, 2);
        }
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }
        if (function_exists('gmp_init')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = function_exists('bcmul') ? bcmul($dec, '16', 0) : (string)((float)$dec * 16);
            $digit = hexdec($hex[$i]);
            $dec = function_exists('bcadd') ? bcadd($dec, (string)$digit, 0) : (string)((float)$dec + $digit);
        }
        return $dec;
    }

    protected function bcCompare(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, 0);
        }
        return $a <=> $b;
    }

    protected function addressFromTopic(string $topic): string
    {
        $topic = strtolower(trim($topic));
        if (strpos($topic, '0x') === 0) {
            $topic = substr($topic, 2);
        }
        if (strlen($topic) < 40) {
            return '';
        }
        return '0x' . substr($topic, -40);
    }

    /** @return mixed */
    protected function rpc(string $method, array $params)
    {
        $url = (string)(get_sys_config('bsc_rpc') ?: '');
        if ($url === '') {
            $url = 'https://bsc-dataseed.binance.org/';
        }

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            throw new \RuntimeException('BSC RPC 请求失败: ' . ($err ?: ('HTTP ' . $code)));
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new \RuntimeException('BSC RPC 响应无效');
        }
        if (!empty($json['error'])) {
            $msg = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'])) : (string)$json['error'];
            throw new \RuntimeException('BSC RPC error: ' . $msg);
        }

        return $json['result'] ?? null;
    }
}
