<?php

// +----------------------------------------------------------------------
// | API v1 —— 认证（邮箱注册/登录；钱包登录预留）
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;
use app\common\library\Ems;
use app\common\library\Wallet;
use app\common\model\User as UserModel;
use app\common\service\MatrixService;
use app\common\service\TpWalletService;

/**
 * 认证接口
 *
 * 路由（见 route/api.php）：
 *   POST /api/v1/auth/email/send-code   发送邮箱验证码
 *   POST /api/v1/auth/email/register    邮箱注册
 *   POST /api/v1/auth/email/login       邮箱验证码登录
 *   POST /api/v1/auth/logout            退出登录
 *   POST /api/v1/auth/wallet/challenge  钱包挑战 (SIWE Nonce)
 *   POST /api/v1/auth/wallet/verify     钱包验签登录
 *   ALL  /api/v1/auth/wallet/tp-login   TokenPocket 钱包 DeepLink 授权登入与注册
 */
class Auth extends Base
{
    /** 无需登录 */
    protected $noNeedLogin = ['sendCode', 'register', 'login', 'walletChallenge', 'walletVerify', 'tpLogin'];
    /** 无需鉴权 */
    protected $noNeedRight = '*';

    /**
     * 发送邮箱验证码
     * event: register 注册 / login 登录 / resetpwd 重置密码
     */
    public function sendCode()
    {
        $email = (string)$this->request->post('email', '');
        $event = (string)$this->request->post('event', 'register');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('邮箱格式错误', self::CODE_PARAM_INVALID);
        }
        if (!preg_match('/^[a-z0-9_\-]{3,30}$/i', $event)) {
            $this->fail('事件名称错误', self::CODE_PARAM_INVALID);
        }

        // 注册场景：邮箱已存在则直接拒绝
        if ($event === 'register') {
            $exists = Db::name('app_identity')
                ->where('type', 'email')
                ->where('identifier', $email)
                ->find();
            if ($exists) {
                $this->fail('该邮箱已注册', self::CODE_DUPLICATE);
            }
        }

        // 频控：同一邮箱 60 秒内只允许发送一次
        $last = Db::name('ems')
            ->where('email', $email)
            ->where('event', $event)
            ->order('id', 'desc')
            ->find();
        if ($last && ($now = time()) - (int)$last['create_time'] < 60) {
            $this->fail('发送过于频繁，请稍后再试', self::CODE_RATE_LIMITED, [
                'retry_after' => 60 - ($now - (int)$last['create_time']),
            ]);
        }

        if (!Ems::send($email, null, $event)) {
            $this->fail(Ems::getLastErrorMessage() ?: '验证码发送失败', self::CODE_BIZ_FAIL);
        }

        $this->success('验证码已发送', ['email' => $email, 'expire' => 120]);
    }

    /**
     * 邮箱注册
     * 注册后默认归入「游客」角色组，购买套餐后升级角色。
     */
    public function register()
    {
        $email    = (string)$this->request->post('email', '');
        $code     = (string)$this->request->post('code', '');
        $password = (string)$this->request->post('password', '');
        $nickname   = (string)$this->request->post('nickname', '');
        $inviteCode = trim((string)$this->request->post('invite_code', ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('邮箱格式错误', self::CODE_PARAM_INVALID);
        }
        if (!$code) {
            $this->fail('请输入邮箱验证码', self::CODE_PARAM_MISSING);
        }
        if (strlen($password) < 6) {
            $this->fail('密码至少 6 位', self::CODE_PARAM_INVALID);
        }

        if (!Ems::check($email, $code, 'register')) {
            $this->fail(Ems::getLastErrorMessage() ?: '验证码错误或已过期', self::CODE_BIZ_FAIL);
        }

        // 用户名：优先使用邮箱前缀，冲突则追加随机串
        $username = preg_replace('/[^a-z0-9_]/i', '', strstr($email, '@', true) ?: 'u');
        $username = substr($username ?: 'u', 0, 20);
        if (Db::name('user')->where('username', $username)->find()) {
            $username .= mt_rand(100, 999);
        }

        $visitorGroupId = (int)(Db::name('user_group')->where('rules', self::ROLE_VISITOR)->value('id') ?: 0);

        $extend = [
            'nickname' => $nickname ?: $username,
            'group_id' => $visitorGroupId,
        ];
        if ($inviteCode !== '') {
            $extend['invite_code'] = $inviteCode;
        }

        $ret = $this->auth->register($username, $password, $email, '', $extend);
        if (!$ret) {
            $this->fail((string)$this->auth->getError() ?: '注册失败', self::CODE_BIZ_FAIL);
        }

        $userId = (int)$this->auth->id;

        // 记录身份凭证（唯一约束保证一个邮箱只绑定一个账号）
        try {
            Db::name('app_identity')->insert([
                'user_id'     => $userId,
                'type'        => 'email',
                'identifier'  => $email,
                'verified_at' => time(),
                'create_time' => time(),
                'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
            // 已存在则忽略（幂等）
        }

        $matrixAuth = (new MatrixService())->syncUserOnAuth($userId, $password, $nickname ?: $username);

        // 有推荐人时，注册成功后自动在 IM 里加为好友（失败不影响注册主流程）
        $inviterId = (int)($this->auth->inviter_id ?? 0);
        if ($inviterId > 0) {
            (new MatrixService())->addDirectFriend((int)$this->auth->id, (string)($matrixAuth['access_token'] ?? ''), $inviterId);
        }

        $this->success('注册成功', [
            'user_id'     => $userId,
            'username'    => $username,
            'email'       => $email,
            'invite_code' => (string)$this->auth->invite_code,
            'inviter_id'  => (int)$this->auth->inviter_id,
            'role'        => $this->resolveRole($userId),
            'token'       => $this->auth->getToken(),
            'matrix'      => $matrixAuth,
        ]);
    }

    /**
     * 邮箱验证码登录
     */
    public function login()
    {
        $email = (string)$this->request->post('email', '');
        $code  = (string)$this->request->post('code', '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('邮箱格式错误', self::CODE_PARAM_INVALID);
        }
        if (!$code) {
            $this->fail('请输入邮箱验证码', self::CODE_PARAM_MISSING);
        }

        $identity = Db::name('app_identity')->where('type', 'email')->where('identifier', $email)->find();
        if (!$identity) {
            $this->fail('该邮箱尚未注册', self::CODE_NOT_FOUND);
        }
        $userId = (int)$identity['user_id'];

        // 账号状态与封禁校验（风控在后续阶段接入 rule 表）
        $user = Db::name('user')->where('id', $userId)->field('id,status')->find();
        if (!$user || $user['status'] !== 'normal') {
            $this->fail('账号不可用，请联系客服', self::CODE_FORBIDDEN);
        }

        if (!Ems::check($email, $code, 'login')) {
            $this->fail(Ems::getLastErrorMessage() ?: '验证码错误或已过期', self::CODE_BIZ_FAIL);
        }

        // 直接登录（验证码已校验，无需密码）
        $this->auth->direct($userId);

        $nickname = (string)Db::name('user')->where('id', $userId)->value('nickname');
        $matrixAuth = (new MatrixService())->syncUserOnAuth($userId, '', $nickname);

        $this->success('登录成功', [
            'user_id'  => $userId,
            'nickname' => $nickname,
            'role'     => $this->resolveRole($userId),
            'token'    => $this->auth->getToken(),
            'matrix'   => $matrixAuth,
        ]);
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        $this->auth->logout();
        $this->success('已退出登录');
    }

    /**
     * 钱包登录 —— 获取签名挑战（Nonce / Message）
     */
    public function walletChallenge()
    {
        $address = (string)$this->request->post('address', '');
        if (!$address || !Wallet::validateAddress($address)) {
            $this->fail('钱包地址格式错误', self::CODE_PARAM_INVALID);
        }

        $challenge = Wallet::createChallenge($address);
        if (!$challenge) {
            $this->fail(Wallet::getError() ?: '生成签名挑战失败', self::CODE_SERVER_ERROR);
        }

        $this->success('挑战生成成功', $challenge);
    }

    /**
     * 钱包登录与注册 —— 校验签名
     * 流程：
     * 1. 验证以太坊 personal_sign 签名；
     * 2. 若钱包地址已存在用户：直接登录签发 Token；
     * 3. 若钱包地址尚未注册：自动注册用户，绑定推荐人（选填 invite_code），分配专属邀请码并签发 Token。
     */
    public function walletVerify()
    {
        $address    = (string)$this->request->post('address', '');
        $signature  = (string)$this->request->post('signature', '');
        $inviteCode = trim((string)$this->request->post('invite_code', ''));

        if (!$address || !Wallet::validateAddress($address)) {
            $this->fail('钱包地址格式错误', self::CODE_PARAM_INVALID);
        }
        if (!$signature) {
            $this->fail('签名内容不能为空', self::CODE_PARAM_MISSING);
        }

        $walletAddress = Wallet::formatAddress($address);

        // 验签（若成功会自动消费 Nonce 防重放）
        if (!Wallet::verifySignature($walletAddress, $signature)) {
            $this->fail(Wallet::getError() ?: '钱包签名验证失败', self::CODE_UNAUTHORIZED);
        }

        // 查询用户是否已注册
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

        $isNewUser = false;
        if ($user) {
            // 账号状态校验
            if ($user->status !== 'normal') {
                $this->fail('账号已被锁定，请联系客服', self::CODE_FORBIDDEN);
            }
            $userId = (int)$user->id;
            // 直接登录
            $this->auth->direct($userId);
        } else {
            // 自动注册逻辑
            $isNewUser = true;

            // 生成唯一的用户名：w_ + 钱包地址后8位
            $baseName = 'w_' . substr($walletAddress, -8);
            $username = $baseName;
            if (UserModel::getByUsername($username)) {
                $username = $baseName . '_' . mt_rand(100, 999);
            }

            // 默认昵称：前6位...后4位，如 0x1234...abcd
            $nickname = substr($walletAddress, 0, 6) . '...' . substr($walletAddress, -4);
            $visitorGroupId = (int)(Db::name('user_group')->where('rules', self::ROLE_VISITOR)->value('id') ?: 0);
            $randomPassword = bin2hex(random_bytes(10));

            $extend = [
                'nickname'       => $nickname,
                'group_id'       => $visitorGroupId,
                'wallet_address' => $walletAddress,
            ];
            if ($inviteCode !== '') {
                $extend['invite_code'] = $inviteCode;
            }

            $ret = $this->auth->register($username, $randomPassword, '', '', $extend);
            if (!$ret) {
                $this->fail((string)$this->auth->getError() ?: '钱包注册失败', self::CODE_BIZ_FAIL);
            }

            $userId = (int)$this->auth->id;

            // 写入身份关联凭证
            try {
                Db::name('app_identity')->insert([
                    'user_id'     => $userId,
                    'type'        => 'wallet',
                    'identifier'  => $walletAddress,
                    'verified_at' => time(),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            } catch (\Throwable $e) {
                // 忽略重复异常
            }

            $user = $this->auth->getUser();
        }

        $matrixAuth = (new MatrixService())->syncUserOnAuth($userId, '', (string)($user->nickname ?? ''));

        // 有推荐人时，注册成功后自动在 IM 里加为好友（失败不影响注册主流程）
        $inviterId = (int)($user->inviter_id ?? 0);
        if ($inviterId > 0) {
            (new MatrixService())->addDirectFriend((int)$user->id, (string)($matrixAuth['access_token'] ?? ''), $inviterId);
        }


        $this->success($isNewUser ? '钱包注册并登录成功' : '钱包登录成功', [
            'user_id'        => $userId,
            'username'       => (string)$user->username,
            'nickname'       => (string)$user->nickname,
            'wallet_address' => (string)$user->wallet_address,
            'invite_code'    => (string)$user->invite_code,
            'inviter_id'     => (int)$user->inviter_id,
            'is_new_user'    => $isNewUser,
            'role'           => $this->resolveRole($userId),
            'token'          => $this->auth->getToken(),
            'matrix'         => $matrixAuth,
        ]);
    }

    /**
     * TokenPocket 钱包 DeepLink 授权登入与注册
     * 路由：/api/v1/auth/wallet/tp-login
     *
     * 支持三种交互形态：
     * 1. action=init / 默认不传 signature：生成唤起 TP 钱包的 DeepLink / Universal Link 协议与挑战
     * 2. action=verify / 携带 signature：校验钱包签名，完成自动登录或注册，签发 Token
     * 3. action=check / GET 携带 ticket：供前端或移动端轮询授权完成状态
     */
    public function tpLogin()
    {
        $action = trim((string)$this->request->param('action', ''));
        $ticket = trim((string)$this->request->param('ticket', ''));
        $address = trim((string)$this->request->param('address', ''));
        $signature = trim((string)$this->request->param('signature', ''));
        $message = (string)$this->request->param('message', '');
        $inviteCode = trim((string)$this->request->param('invite_code', ''));

        // 兼容 TP 钱包原生 Webhook / 回调数据结构 (JSON Raw / POST)
        $rawPost = $this->request->post();
        if (isset($rawPost['actionId']) && empty($ticket)) {
            $ticket = (string)$rawPost['actionId'];
        }
        if (isset($rawPost['data']) && is_array($rawPost['data'])) {
            if (empty($address) && !empty($rawPost['data']['address'])) {
                $address = (string)$rawPost['data']['address'];
            }
            if (empty($signature) && !empty($rawPost['data']['signature'])) {
                $signature = (string)$rawPost['data']['signature'];
            }
            if (empty($message) && !empty($rawPost['data']['message'])) {
                $message = (string)$rawPost['data']['message'];
            }
        }

        // 自动判定 Action 动作类型
        if ($action === '' || $action === 'auto') {
            if ($signature !== '') {
                $action = 'verify';
            } elseif ($this->request->isGet() && $ticket !== '') {
                $action = 'check';
            } else {
                $action = 'init';
            }
        }

        $service = new TpWalletService();
        $responseData = null;
        $responseMsg = '';

        // 严格遵循 BadouCMS 规范：try {} 内禁止直接返回响应
        try {
            if ($action === 'check') {
                if ($ticket === '') {
                    throw new \InvalidArgumentException('请传入 ticket 票据');
                }
                $checkRes = $service->checkTicketStatus($ticket);
                if ($checkRes['status'] === 'success') {
                    $userId = (int)($checkRes['data']['user_id'] ?? 0);
                    $checkRes['data']['role'] = $this->resolveRole($userId);
                }
                $responseData = $checkRes;
                $responseMsg = $checkRes['message'] ?? '查询成功';
            } elseif ($action === 'verify') {
                if ($address === '') {
                    throw new \InvalidArgumentException('钱包地址不能为空');
                }
                if ($signature === '') {
                    throw new \InvalidArgumentException('签名内容不能为空');
                }
                $loginResult = $service->verifyAndLogin($address, $signature, $ticket ?: null, $message ?: null, $inviteCode);
                $userId = (int)$loginResult['user_id'];
                $loginResult['role'] = $this->resolveRole($userId);

                $responseData = $loginResult;
                $responseMsg = !empty($loginResult['is_new_user']) ? 'TP钱包注册并登录成功' : 'TP钱包登录成功';
            } else {
                // 初始化生成 DeepLink
                $callbackUrl = (string)$this->request->param('callback_url', '');
                $chainId = (int)$this->request->param('chain_id', 56);
                $dappName = (string)$this->request->param('dapp_name', 'ITIK');
                $dappIcon = (string)$this->request->param('dapp_icon', '');

                $session = $service->createAuthSession([
                    'address'      => $address,
                    'callback_url' => $callbackUrl,
                    'invite_code'  => $inviteCode,
                    'chain_id'     => $chainId,
                    'dapp_name'    => $dappName,
                    'dapp_icon'    => $dappIcon,
                ]);

                $responseData = $session;
                $responseMsg = 'TP钱包DeepLink授权协议生成成功';
            }
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage(), self::CODE_PARAM_INVALID);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        } catch (\Throwable $e) {
            $this->fail('系统处理失败: ' . $e->getMessage(), self::CODE_SERVER_ERROR);
        }

        // 成功响应位于完整的 try/catch 之后
        $this->success($responseMsg, $responseData);
    }
}


