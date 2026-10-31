<?php

// +----------------------------------------------------------------------
// | API v1 —— 用户与角色
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use app\common\library\FrontendAuth;
use app\common\service\MatrixService;
use think\facade\Db;
use app\common\library\Ems;
use app\common\library\Wallet;

/**
 * 用户接口
 *
 * 路由：
 *   GET  /api/v1/users/me           当前用户档案（含角色与订阅状态）
 *   PUT  /api/v1/users/me           更新资料
 *   GET  /api/v1/users/me/identities 已绑定身份
 *   GET  /api/v1/users/me/subscription 当前订阅
 *   GET  /api/v1/users/me/bindings  邮箱/钱包绑定状态
 *   POST /api/v1/users/me/email/send-code  发送绑定邮箱验证码
 *   POST /api/v1/users/me/email/bind       绑定邮箱
 *   POST /api/v1/users/me/wallet/challenge 钱包绑定签名挑战
 *   POST /api/v1/users/me/wallet/bind      绑定钱包
 *   GET  /api/v1/roles/plans        套餐列表（公开）
 */
class User extends Base
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /**
     * 当前用户档案
     */
    public function me()
    {
        $userId = $this->userId();
        $user = Db::name('user')
            ->where('id', $userId)
            ->field('id,username,nickname,email,mobile,invite_code,inviter_id,avatar,wallet_address,gender,birthday,bio,money,score,level,group_id,lv_vip,lv_team,logintime')
            ->find();

        if (!$user) {
            $this->fail('用户不存在', self::CODE_NOT_FOUND);
        }

        $this->success('', [
            'user'         => [
                'id'          => (int)$user['id'],
                'username'    => $user['username'],
                'nickname'    => $user['nickname'],
                'email'       => $user['email'],
                'mobile'      => $user['mobile'],
                'invite_code' => (string)($user['invite_code'] ?? ''),
                'inviter_id'  => (int)($user['inviter_id'] ?? 0),
                'avatar'      => $user['avatar'],
                'gender'      => (int)$user['gender'],
                'bio'         => $user['bio'],
                'money'       => $user['money'],
                'score'       => (int)$user['score'],
                'level'       => (int)$user['level'],
                'lv_vip'      => (int)$user['lv_vip'],
                'lv_team'     => (int)$user['lv_team'],
                'lv_vip_name' => Db::name('user_lv_vip')->where('id', $user['lv_vip'])->value('name'),
                'lv_team_name'=> $user['lv_team'] == 1 ? '' : Db::name('user_lv_team')->where('id', $user['lv_team'])->value('name'),
                'wallet_address' => $user['wallet_address'],
            ],
            'role'         => $this->role,
            'subscription' => $this->subscription ? [
                'status'       => $this->subscription['status'],
                'plan_code'    => $this->subscription['plan_code'],
                'role_code'    => $this->subscription['role_code'],
                'start_at'     => (int)$this->subscription['start_at'],
                'end_at'       => (int)$this->subscription['end_at'],
                'grace_end_at' => (int)$this->subscription['grace_end_at'],
                'active'       => $this->subscriptionActive(),
            ] : null,
        ]);
    }

    /**
     * 更新资料（仅允许白名单字段）
     */
    public function update()
    {
        $userId = $this->userId();
        $allow = ['nickname', 'avatar', 'gender', 'birthday', 'bio'];
        $data = [];
        foreach ($allow as $field) {
            $val = $this->request->put($field, null);
            if ($val !== null) {
                $data[$field] = $val;
            }
        }
        if (!$data) {
            $this->fail('没有需要更新的字段', self::CODE_PARAM_MISSING);
        }
        if (isset($data['nickname'])) {
            $nickname = trim((string)$data['nickname']);
            if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 50) {
                $this->fail('昵称长度需在 2-50 字符之间', self::CODE_PARAM_INVALID);
            }
            $dup = Db::name('user')->where('nickname', $nickname)->where('id', '<>', $userId)->find();
            if ($dup) {
                $this->fail('该昵称已被占用', self::CODE_DUPLICATE);
            }
            $data['nickname'] = $nickname;
        }
        if (isset($data['bio']) && mb_strlen((string)$data['bio']) > 100) {
            $this->fail('简介不能超过 100 字符', self::CODE_PARAM_INVALID);
        }

        $data['update_time'] = time();
        Db::name('user')->where('id', $userId)->update($data);

        $this->success('更新成功', $data);
    }

    /**
     * 绑定状态（判断邮箱 / 钱包是否已绑定）
     */
    public function bindings()
    {
        $email  = $this->boundEmail();
        $wallet = $this->boundWallet();

        $this->success('', [
            'email'  => [
                'bound'      => $email !== null,
                'identifier' => $email,
            ],
            'wallet' => [
                'bound'   => $wallet !== null,
                'address' => $wallet,
            ],
        ]);
    }

    /**
     * 绑定邮箱 —— 发送验证码
     */
    public function emailSendCode()
    {
        $userId = $this->userId();
        $email  = (string)$this->request->post('email', '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('邮箱格式错误', self::CODE_PARAM_INVALID);
        }

        if ($this->boundEmail() !== null) {
            $this->fail('当前账号已绑定邮箱', self::CODE_STATE_INVALID);
        }

        // 目标邮箱不能被其它账号绑定
        if ($this->emailOccupied($email, $userId)) {
            $this->fail('该邮箱已被其他账号绑定', self::CODE_DUPLICATE);
        }

        // 频控：同一邮箱 60 秒内只允许发送一次
        $last = Db::name('ems')
            ->where('email', $email)
            ->where('event', 'bind_email')
            ->order('id', 'desc')
            ->find();
        if ($last && ($now = time()) - (int)$last['create_time'] < 60) {
            $this->fail('发送过于频繁，请稍后再试', self::CODE_RATE_LIMITED, [
                'retry_after' => 60 - ($now - (int)$last['create_time']),
            ]);
        }

        if (!Ems::send($email, null, 'bind_email')) {
            $this->fail(Ems::getLastErrorMessage() ?: '验证码发送失败', self::CODE_BIZ_FAIL);
        }

        $this->success('验证码已发送', ['email' => $email, 'expire' => 120]);
    }

    /**
     * 绑定邮箱 —— 校验验证码并完成绑定
     */
    public function emailBind()
    {
        $userId = $this->userId();
        $email  = (string)$this->request->post('email', '');
        $code   = (string)$this->request->post('code', '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('邮箱格式错误', self::CODE_PARAM_INVALID);
        }
        if (!$code) {
            $this->fail('请输入邮箱验证码', self::CODE_PARAM_MISSING);
        }

        if ($this->boundEmail() !== null) {
            $this->fail('当前账号已绑定邮箱', self::CODE_STATE_INVALID);
        }
        if ($this->emailOccupied($email, $userId)) {
            $this->fail('该邮箱已被其他账号绑定', self::CODE_DUPLICATE);
        }

        if (!Ems::check($email, $code, 'bind_email')) {
            $this->fail(Ems::getLastErrorMessage() ?: '验证码错误或已过期', self::CODE_BIZ_FAIL);
        }

        Db::startTrans();
        try {
            Db::name('user')->where('id', $userId)->update([
                'email'        => $email,
                'verification' => $this->mergeVerification($userId, 'email'),
                'update_time'  => time(),
            ]);
            Db::name('app_identity')->insert([
                'user_id'     => $userId,
                'type'        => 'email',
                'identifier'  => $email,
                'verified_at' => time(),
                'create_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
        } catch (\Throwable) {
            Db::rollback();
            $this->fail('绑定失败，请稍后再试', self::CODE_SERVER_ERROR);
        }

        Ems::flush($email, 'bind_email');
        $this->success('邮箱绑定成功', ['email' => $email]);
    }

    /**
     * 修改密码 —— 校验验证码并完成修改
     */
    public function modifyPwd()
    {
        $userId = $this->userId();
        $code   = (string)$this->request->post('code', '');
        $password   = (string)$this->request->post('password', '');

        if (!$code) {
            $this->fail('请输入邮箱验证码', self::CODE_PARAM_MISSING);
        }

        if ($this->boundEmail() == null) {
            $this->fail('当前账号未绑定邮箱', self::CODE_STATE_INVALID);
        }

        if (empty($password) || strlen($password) < 6) {
            $this->fail('密码不能为空且长度不能少于6位');
        }

        if (!Ems::check($this->boundEmail(), $code, 'modify_pwd')) {
            $this->fail(Ems::getLastErrorMessage() ?: '验证码错误或已过期');
        }

        Db::startTrans();
        try {
            $auth = new FrontendAuth();
            $passwd = $auth->getEncryptPassword($password);
            Db::name('user')->where(['id' => $userId])->update(['password' => $passwd]);
            Db::commit();
        } catch (\Throwable) {
            Db::rollback();
            $this->fail('密码修改失败');
        }

        Ems::flush($this->boundEmail(), 'modify_pwd');
        $this->success('密码修改成功');
    }

    /**
     * 绑定钱包 —— 获取签名挑战（Nonce / Message）
     */
    public function walletChallenge()
    {
        $userId  = $this->userId();
        $address = (string)$this->request->post('address', '');

        if (!$address || !Wallet::validateAddress($address)) {
            $this->fail('钱包地址格式错误', self::CODE_PARAM_INVALID);
        }

        if ($this->boundWallet() !== null) {
            $this->fail('当前账号已绑定钱包', self::CODE_STATE_INVALID);
        }

        $address = Wallet::formatAddress($address);
        if ($this->walletOccupied($address, $userId)) {
            $this->fail('该钱包地址已被其他账号绑定', self::CODE_DUPLICATE);
        }

        $challenge = Wallet::createChallenge($address);
        if (!$challenge) {
            $this->fail(Wallet::getError() ?: '生成签名挑战失败', self::CODE_SERVER_ERROR);
        }

        $this->success('挑战生成成功', $challenge);
    }

    /**
     * 绑定钱包 —— 校验签名并完成绑定
     */
    public function walletBind()
    {
        $userId    = $this->userId();
        $address   = (string)$this->request->post('address', '');
        $signature = (string)$this->request->post('signature', '');

        if (!$address || !Wallet::validateAddress($address)) {
            $this->fail('钱包地址格式错误', self::CODE_PARAM_INVALID);
        }
        if (!$signature) {
            $this->fail('签名内容不能为空', self::CODE_PARAM_MISSING);
        }

        if ($this->boundWallet() !== null) {
            $this->fail('当前账号已绑定钱包', self::CODE_STATE_INVALID);
        }

        $walletAddress = Wallet::formatAddress($address);
        if ($this->walletOccupied($walletAddress, $userId)) {
            $this->fail('该钱包地址已被其他账号绑定', self::CODE_DUPLICATE);
        }

        if (!Wallet::verifySignature($walletAddress, $signature)) {
            $this->fail(Wallet::getError() ?: '钱包签名验证失败', self::CODE_UNAUTHORIZED);
        }

        Db::startTrans();
        try {
            Db::name('user')->where('id', $userId)->update([
                'wallet_address' => $walletAddress,
                'update_time'    => time(),
            ]);
            Db::name('app_identity')->insert([
                'user_id'     => $userId,
                'type'        => 'wallet',
                'identifier'  => $walletAddress,
                'verified_at' => time(),
                'create_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
        } catch (\Throwable) {
            Db::rollback();
            $this->fail('绑定失败，请稍后再试', self::CODE_SERVER_ERROR);
        }

        $this->success('钱包绑定成功', ['wallet_address' => $walletAddress]);
    }

    /**
     * 已绑定身份（邮箱/钱包，钱包后续阶段接入）
     */
    public function identities()
    {
        $list = Db::name('app_identity')
            ->where('user_id', $this->userId())
            ->field('id,type,identifier,verified_at,create_time')
            ->order('id', 'asc')
            ->select();

        // 脱敏展示：邮箱隐藏 @ 前部分字符
        $list = $list->toArray();
        foreach ($list as $k => $item) {
            if ($item['type'] === 'email') {
                $parts = explode('@', (string)$item['identifier']);
                $name  = $parts[0] ?? '';
                $list[$k]['identifier_masked'] = (mb_strlen($name) > 2
                        ? mb_substr($name, 0, 2) . str_repeat('*', max(1, mb_strlen($name) - 2))
                        : $name) . '@' . ($parts[1] ?? '');
            } else {
                $list[$k]['identifier_masked'] = mb_substr((string)$item['identifier'], 0, 6) . '...';
            }
        }

        $this->success('', ['list' => $list]);
    }

    /**
     * 当前订阅
     */
    public function subscription()
    {
        $list = Db::name('app_subscription')
            ->where('user_id', $this->userId())
            ->field('id,plan_code,role_code,status,start_at,end_at,grace_end_at,create_time')
            ->order('id', 'desc')
            ->limit(20)
            ->select();

        $this->success('', [
            'current'   => $this->subscription,
            'status'    => $this->subscriptionStatus(),
            'active'    => $this->subscriptionActive(),
            'history'   => $list,
        ]);
    }

    /**
     * 当前用户已绑定的邮箱（未绑定返回 null）
     */
    private function boundEmail(): ?string
    {
        $userId = $this->userId();
        $email  = Db::name('user')->where('id', $userId)->value('email');
        if (!empty($email)) {
            return (string)$email;
        }
        $identity = Db::name('app_identity')->where('user_id', $userId)->where('type', 'email')->value('identifier');
        return $identity ? (string)$identity : null;
    }

    /**
     * 当前用户已绑定的钱包地址（未绑定返回 null）
     */
    private function boundWallet(): ?string
    {
        $userId = $this->userId();
        $wallet = Db::name('user')->where('id', $userId)->value('wallet_address');
        if (!empty($wallet)) {
            return (string)$wallet;
        }
        $identity = Db::name('app_identity')->where('user_id', $userId)->where('type', 'wallet')->value('identifier');
        return $identity ? (string)$identity : null;
    }

    /**
     * 邮箱是否已被其他账号占用（user.email 或 app_identity）
     */
    private function emailOccupied(string $email, int $excludeUserId): bool
    {
        if (Db::name('user')->where('email', $email)->where('id', '<>', $excludeUserId)->find()) {
            return true;
        }
        return (bool)Db::name('app_identity')->where('type', 'email')->where('identifier', $email)->find();
    }

    /**
     * 钱包地址是否已被其他账号占用（user.wallet_address 或 app_identity）
     */
    private function walletOccupied(string $address, int $excludeUserId): bool
    {
        if (Db::name('user')->where('wallet_address', $address)->where('id', '<>', $excludeUserId)->find()) {
            return true;
        }
        return (bool)Db::name('app_identity')->where('type', 'wallet')->where('identifier', $address)->find();
    }

    /**
     * 合并 verification 字段（JSON），标记邮箱/手机号已验证
     */
    private function mergeVerification(int $userId, string $field, int $value = 1): string
    {
        $raw = (string)Db::name('user')->where('id', $userId)->value('verification');
        $verification = json_decode($raw, true);
        if (!is_array($verification)) {
            $verification = ['email' => 0, 'mobile' => 0];
        }
        $verification[$field] = $value;
        return json_encode($verification, JSON_UNESCAPED_UNICODE);
    }

    public function findFri(){
        $username = $this->request->post('username');
        $user = \app\common\model\User::whereOr('username', $username)->whereOr('invite_code', $username)->find();
        $params = [];
        if(!$user){
            $this->error('未找到好友');
        }else{
            $params[$user->id]['id'] = $user->id;
            $params[$user->id]['username'] = $user->username;
            $params[$user->id]['nickname'] = $user->nickname;
            $params[$user->id]['imUser'] = (new MatrixService())->buildUserId($user->id);
        }
        $this->success(__('Successful'), $params);
    }

    public function friends(){
        $ids = $this->request->post('ids');
        if (!$ids) {
            $this->error(__('Invalid parameters'));
        }
        $params = [];
        foreach ($ids as $id) {
            $user = \app\common\model\User::where('id', $id)->find();
            $params[$id]['id'] = $id;
            $params[$id]['username'] = $user->username;
            $params[$user->id]['nickname'] = $user->nickname;
            $params[$id]['imUser'] = (new MatrixService())->buildUserId($id);
        }
        if (!empty($params)) {
            $this->success(__('Successful'), $params);
        } else {
            $this->error('获取异常');
        }
    }
}
