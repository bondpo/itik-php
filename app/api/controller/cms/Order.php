<?php

// +----------------------------------------------------------------------
// | CMS API —— 订单（内容付费；支付渠道后接）
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 订单接口
 *
 * 路由：
 *   GET  /api/cms/orders           我的订单列表
 *   POST /api/cms/orders           创建订单（内容付费）
 *   GET  /api/cms/orders/detail    订单详情
 *   POST /api/cms/orders/cancel    取消未支付订单
 *   POST /api/cms/orders/notify    支付回调（渠道服务端调用）
 */
class Order extends Base
{
    protected $noNeedLogin = ['notify'];
    protected $noNeedRight = '*';

    /**
     * 我的订单
     */
    public function index()
    {
        $userId = $this->requireLogin();
        [$page, $limit] = $this->cmsPageParams();

        $query = Db::name('cms_order')->where('user_id', $userId);
        $status = (string)$this->request->param('status', '');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)
            ->field('id,orderid,archives_id,title,amount,payamount,paytype,paytime,createtime,status')
            ->select()->toArray();

        $this->success('', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * 创建订单
     * 说明：金额取自内容自身的 price 字段，不信任前端传值；支付渠道接入前状态为未支付。
     */
    public function save()
    {
        $userId = $this->requireLogin();
        $archivesId = (int)$this->request->post('archives_id', 0);
        if ($archivesId <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }

        $archives = Db::name('cms_archives')->where('id', $archivesId)->where('status', 'normal')->find();
        if (!$archives) {
            $this->fail('内容不存在', self::CODE_NOT_FOUND);
        }
        $price = (float)($archives['price'] ?? 0);
        if ($price <= 0) {
            $this->fail('该内容无需付费', self::CODE_STATE_INVALID);
        }

        // 已购校验：同内容已存在已支付订单则不重复下单
        $paid = Db::name('cms_order')->where('user_id', $userId)->where('archives_id', $archivesId)
            ->where('status', 'paid')->find();
        if ($paid) {
            $this->fail('你已购买该内容', self::CODE_DUPLICATE, ['orderid' => $paid['orderid']]);
        }

        // 幂等：同一用户 + 内容的未支付订单直接复用
        $pending = Db::name('cms_order')->where('user_id', $userId)->where('archives_id', $archivesId)
            ->where('status', 'unpaid')->find();
        if ($pending) {
            $this->success('订单已存在', ['order' => $pending, 'repeated' => true]);
        }

        $orderid = date('YmdHis') . mt_rand(1000, 9999) . sprintf('%04d', random_int(0, 9999));
        $id = (int)Db::name('cms_order')->insertGetId([
            'orderid'     => $orderid,
            'user_id'     => $userId,
            'archives_id' => $archivesId,
            'title'       => (string)$archives['title'],
            'amount'      => $price,
            'payamount'   => $price,
            'paytype'     => '',
            'paytime'     => 0,
            'method'      => 'cms',
            'ip'          => $this->request->ip(),
            'useragent'   => mb_substr((string)$this->request->header('user-agent', ''), 0, 255),
            'createtime'  => time(),
            'updatetime'  => time(),
            'status'      => 'unpaid',
        ]);

        $this->success('订单已创建', [
            'order'    => Db::name('cms_order')->where('id', $id)->find(),
            'repeated' => false,
            'pay_hint' => '支付渠道尚未接入，订单待支付',
        ]);
    }

    /**
     * 订单详情
     */
    public function detail()
    {
        $userId = $this->requireLogin();
        $orderid = trim((string)$this->request->param('orderid', ''));
        if ($orderid === '') {
            $this->fail('缺少订单号', self::CODE_PARAM_MISSING);
        }
        $order = Db::name('cms_order')->where('orderid', $orderid)->where('user_id', $userId)->find();
        if (!$order) {
            $this->fail('订单不存在', self::CODE_NOT_FOUND);
        }
        $this->success('', ['order' => $order]);
    }

    /**
     * 取消未支付订单
     */
    public function cancel()
    {
        $userId = $this->requireLogin();
        $orderid = trim((string)$this->request->post('orderid', ''));
        $order = Db::name('cms_order')->where('orderid', $orderid)->where('user_id', $userId)->find();
        if (!$order) {
            $this->fail('订单不存在', self::CODE_NOT_FOUND);
        }
        if ($order['status'] !== 'unpaid') {
            $this->fail('仅未支付订单可取消', self::CODE_STATE_INVALID, ['status' => $order['status']]);
        }
        Db::name('cms_order')->where('id', $order['id'])->update(['status' => 'canceled', 'updatetime' => time()]);
        $this->success('订单已取消');
    }

    /**
     * 支付回调（公开，渠道服务端调用）
     * 安全：开启验签后必须带正确签名；未开启时仅允许调试环境。
     */
    public function notify()
    {
        $orderid = trim((string)$this->request->post('orderid', ''));
        $paytype = (string)$this->request->post('paytype', '');
        $sign    = (string)$this->request->post('sign', '');

        if ($orderid === '') {
            $this->fail('缺少订单号', self::CODE_PARAM_MISSING);
        }
        $order = Db::name('cms_order')->where('orderid', $orderid)->find();
        if (!$order) {
            $this->fail('订单不存在', self::CODE_NOT_FOUND);
        }
        if ($order['status'] === 'paid') {
            $this->success('重复通知，已忽略', ['repeated' => true]);
        }

        $verifyEnabled = (int)$this->cmsConfig('pay_verify_sign', 0) === 1;
        if ($verifyEnabled) {
            $secret = (string)$this->cmsConfig('pay_sign_secret', '');
            $expect = md5($orderid . $paytype . $secret);
            if ($secret === '' || !hash_equals($expect, $sign)) {
                $this->fail('签名校验失败', self::CODE_FORBIDDEN);
            }
        } elseif (!app()->isDebug()) {
            $this->fail('支付渠道未接入，回调已拒绝', self::CODE_FORBIDDEN);
        }

        Db::name('cms_order')->where('id', $order['id'])->update([
            'status'     => 'paid',
            'paytype'    => $paytype,
            'paytime'    => time(),
            'updatetime' => time(),
        ]);

        $this->success('支付成功', ['orderid' => $orderid]);
    }
}
