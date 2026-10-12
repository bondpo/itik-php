<?php

namespace app\common\library\cms;

use app\common\model\cms\Archives;
use app\common\library\FrontendAuth as Auth;
use app\common\model\User;
use badou\Http;
use badou\Random;
use think\facade\Db;
use think\Exception;
use think\facade\Event;
use think\Request;
use think\facade\View;
use Yansongda\Pay\Exceptions\GatewayException;

class Order
{

    /**
     * 获取查询条件
     * @return \Closure
     */
    protected static function getQueryCondition()
    {
        $condition = function ($query) {
            $auth = Auth::instance();
            $user_id = $auth->isLogin() ? $auth->id : 0;
            $ip = Request::instance()->ip(0, false);
            $config = get_addon_config('cms');
            //如果开启支付需要登录，则只判断user_id
            if ($config['ispaylogin']) {
                $query->where('user_id', $user_id);
            } else {
                if ($user_id) {
                    $query->whereOr('user_id', $user_id)->whereOr('ip', $ip);
                } else {
                    $query->where('user_id', 0)->where('ip', $ip);
                }
            }
        };
        return $condition;
    }

    /**
     * 检查订单
     * @param int $id 订单号
     * @return bool
     */
    public static function check($id)
    {
        $archives = Archives::find($id);
        if (!$archives) {
            return false;
        }
        $where = [
            'archives_id' => $id,
            'status'      => 'paid',
        ];

        //如果是作者则直接允许查看
        $auth = Auth::instance();
        $user_id = $auth->isLogin() ? $auth->id : 0;
        if ($user_id && $user_id == $archives->user_id) {
            return true;
        }

        //匹配已支付订单
        $order = \app\common\model\cms\Order::where($where)->where(self::getQueryCondition())->order('id', 'desc')->find();
        return $order ? true : false;
    }

    /**
     * 发起订单支付
     *
     * @param int    $archives_id 文档ID
     * @param string $paytype     支付类型
     * @param string $method      支付方法
     * @param string $openid      Openid
     * @param string $notifyurl   通知地址
     * @param string $returnurl   返回地址
     * @return mixed
     * @throws OrderException
     */
    public static function submit($archives_id, $paytype = 'wechat', $method = 'web', $openid = '', $notifyurl = '', $returnurl = '')
    {
        $archives = Archives::find($archives_id);
        if (!$archives) {
            throw new OrderException('文档未找到');
        }

        $order = \app\common\model\cms\Order::where('archives_id', $archives->id)
            ->where(self::getQueryCondition())
            ->order('id', 'desc')
            ->find();
        if ($order && $order['status'] == 'paid') {
            throw new OrderException('订单已支付');
        }

        $auth = Auth::instance();
        $request = Request::instance();
        if (!$order || (time() - $order->createtime) > 600 || $order->amount != $archives->price) {
            $orderid = date("YmdHis") . mt_rand(100000, 999999);
            $data = [
                'user_id'     => $auth->id ? $auth->id : 0,
                'orderid'     => $orderid,
                'archives_id' => $archives->id,
                'title'       => "付费阅读",
                'amount'      => $archives->price,
                'payamount'   => 0,
                'paytype'     => $paytype,
                'method'      => $method,
                'ip'          => $request->ip(0, false),
                'useragent'   => substr($request->server('HTTP_USER_AGENT'), 0, 255),
                'status'      => 'created'
            ];
            $order = \app\common\model\cms\Order::create($data);
        } else {
            //支付方式变更
            if (($order['method'] && $order['paytype'] == $paytype && $order['method'] != $method)) {
                $orderid = date("YmdHis") . mt_rand(100000, 999999);
                $order->save(['orderid' => $orderid]);
            }

            //更新支付类型和方法
            $order->save(['paytype' => $paytype, 'method' => $method]);

            if ($order->amount != $archives->price || $order->paytype != $paytype) {
                $order->amount = $archives->price;
                $order->paytype = $paytype;
                $order->save();
            }
        }

        //使用余额支付
        if ($paytype == 'balance') {
            if (!$auth->id) {
                throw new OrderException('需要登录后才能够支付');
            }
            if ($auth->money < $archives->price) {
                throw new OrderException('余额不足，无法进行支付');
            }
            Db::startTrans();
            try {
                User::money(-$archives->price, $auth->id, '购买付费文档:' . $archives['title']);
                self::settle($order->orderid, $archives->price);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                throw new OrderException($e->getMessage());
            }
            throw new OrderException('余额支付成功', 1);
        }

        $response = null;
        $result = Event::trigger('cms_order_submit', $order);
        if (!$result) {
            throw new OrderException("当前系统未配置在线支付渠道，请联系管理员");
        }
        return $result;
    }

    /**
     * 订单结算
     * @param mixed  $orderid   订单号
     * @param mixed  $payamount 金额
     * @param string $memo      备注
     * @return bool
     */
    public static function settle($orderid, $payamount, $memo = '')
    {
        $order = \app\common\model\cms\Order::getByOrderid($orderid);
        if (!$order) {
            return false;
        }
        if ($order['status'] != 'paid') {
            if ($payamount != $order->amount) {
                \think\facade\Log::write("[cms][pay][{$orderid}][订单支付金额不一致]");
                return false;
            }

            //计算收益
            $config = get_addon_config('cms');
            list($systemRatio, $userRatio) = explode(':', $config['archivesratio']);
            Db::startTrans();
            try {
                $order->payamount = $payamount;
                $order->paytime = time();
                $order->status = 'paid';
                $order->memo = $memo;
                $order->save();

                if ($order->payamount == $order->amount) {
                    User::money($systemRatio * $payamount, $config['system_user_id'], '付费文章收益');
                    User::money($userRatio * $payamount, $order->archives->user_id, '付费文章收益');
                }
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                return false;
            }
        }
        return true;
    }
}
