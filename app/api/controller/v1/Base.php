<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— API v1 基类
// +----------------------------------------------------------------------
// | 约定：/api/v1/*，统一响应 {code,msg,data,request_id,time}
// | 权限：五层判定 = 角色(RBAC) + 订阅状态 + 关系 + 内容审核 + 风控
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;
use app\common\controller\Api;

/**
 * API v1 基类
 *
 * 在 app\common\controller\Api 的基础上补充：
 * - request_id 透传
 * - 分段错误码
 * - 四角色（OWNER/LEADER/MEMBER/VISITOR）与订阅状态注入
 * - 订阅态门槛校验
 * - 统一分页参数
 */
class Base extends Api
{
    /* ---------------- 错误码分段 ----------------
     * 1xxxx 参数 / 2xxxx 鉴权与权限 / 3xxxx 业务 / 4xxxx 风控 / 5xxxx 系统
     */
    public const CODE_SUCCESS        = 1;
    public const CODE_PARAM_INVALID  = 10001;
    public const CODE_PARAM_MISSING  = 10002;

    public const CODE_UNAUTHORIZED   = 20001; // 未登录/令牌无效
    public const CODE_FORBIDDEN      = 20002; // 无权限
    public const CODE_ROLE_DENIED    = 20003; // 角色不符
    public const CODE_SUB_REQUIRED   = 20004; // 订阅态不满足

    public const CODE_BIZ_FAIL       = 30001; // 业务失败
    public const CODE_NOT_FOUND      = 30002; // 资源不存在
    public const CODE_STATE_INVALID  = 30003; // 状态不允许
    public const CODE_DUPLICATE      = 30004; // 重复操作（幂等命中）

    public const CODE_RISK_REJECT    = 40001; // 风控拒绝
    public const CODE_RATE_LIMITED   = 40002; // 频控限制

    public const CODE_SERVER_ERROR   = 50001;

    /** 角色代码 */
    public const ROLE_OWNER   = 'OWNER';  //项目方
    public const ROLE_LEADER  = 'LEADER'; //社区长
    public const ROLE_MEMBER  = 'MEMBER';  //会员
    public const ROLE_VISITOR = 'VISITOR';  //游客

    /** 订阅六态 */
    public const SUB_PENDING   = 'PENDING_PAYMENT';
    public const SUB_ACTIVE    = 'ACTIVE';
    public const SUB_GRACE     = 'GRACE';
    public const SUB_EXPIRED   = 'EXPIRED';
    public const SUB_SUSPENDED = 'SUSPENDED';
    public const SUB_CANCELLED = 'CANCELLED';

    /**
     * 请求 ID（贯穿日志与响应，便于排障与审计）
     * @var string
     */
    protected $requestId = '';

    /**
     * 当前用户角色代码
     * @var string
     */
    protected $role = self::ROLE_VISITOR;

    /**
     * 当前用户订阅（数组或 null）
     * @var array|null
     */
    protected $subscription = null;

    public function initialize()
    {
        // 先于父类初始化，确保鉴权失败等早期错误响应也带 request_id
        $this->requestId = (string)($this->request->header('X-Request-Id') ?: md5(uniqid('r', true)));

        parent::initialize();

        // 已登录时注入角色与订阅状态（游客阶段保持默认）
        if ($this->auth && $this->auth->isLogin()) {
            $this->role = $this->resolveRole((int)$this->auth->id);
            $this->subscription = $this->resolveSubscription((int)$this->auth->id);
        }
    }

    /**
     * 解析用户角色：bd_user_group.rules 存角色代码时为付费角色，否则为游客
     * @param int $userId
     * @return string
     */
    protected function resolveRole(int $userId): string
    {
        if ($userId <= 0) {
            return self::ROLE_VISITOR;
        }
        $group = Db::name('user')
            ->alias('u')
            ->join('user_group g', 'g.id = u.group_id')
            ->where('u.id', $userId)
            ->field('g.rules')
            ->find();
        $rules = trim((string)($group['rules'] ?? ''));
        $roles = [self::ROLE_OWNER, self::ROLE_LEADER, self::ROLE_MEMBER];
        return in_array($rules, $roles, true) ? $rules : self::ROLE_VISITOR;
    }

    /**
     * 取用户当前订阅（优先返回最近一条未终结记录）
     * @param int $userId
     * @return array|null
     */
    protected function resolveSubscription(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        // 优先级：生效中（ACTIVE/GRACE）> 暂停 > 待支付。
        // 注意：不能简单按 id 倒序取最新——新建的「待支付」订单会抢占真正生效中的订阅。
        $effective = Db::name('app_subscription')
            ->where('user_id', $userId)
            ->whereIn('status', [self::SUB_ACTIVE, self::SUB_GRACE])
            ->order('end_at', 'desc')
            ->find();
        if ($effective) {
            return $effective;
        }

        return Db::name('app_subscription')
            ->where('user_id', $userId)
            ->whereIn('status', [self::SUB_SUSPENDED, self::SUB_PENDING])
            ->order('id', 'desc')
            ->find() ?: null;
    }

    /**
     * 当前订阅状态（无订阅视为 EXPIRED）
     * @return string
     */
    protected function subscriptionStatus(): string
    {
        return (string)($this->subscription['status'] ?? self::SUB_EXPIRED);
    }

    /**
     * 是否处于可用（已付费且未失效）
     * @param bool $allowGrace 是否把宽限期视为可用
     * @return bool
     */
    protected function subscriptionActive(bool $allowGrace = true): bool
    {
        $status = $this->subscriptionStatus();
        if ($status === self::SUB_ACTIVE) {
            return true;
        }
        return $allowGrace && $status === self::SUB_GRACE;
    }

    /**
     * 要求指定角色（可多选），否则拒绝
     * @param array|string $roles
     */
    protected function requireRole($roles): void
    {
        $roles = (array)$roles;
        if (!in_array($this->role, $roles, true)) {
            $this->fail('当前角色无权访问', self::CODE_ROLE_DENIED, ['role' => $this->role]);
        }
    }

    /**
     * 要求有效订阅
     * @param bool   $allowGrace
     * @param string $msg
     */
    protected function requireSubscription(bool $allowGrace = true, string $msg = '订阅不可用，请先开通或续费'): void
    {
        if (!$this->subscriptionActive($allowGrace)) {
            $this->fail($msg, self::CODE_SUB_REQUIRED, [
                'status' => $this->subscriptionStatus(),
                'end_at' => (int)($this->subscription['end_at'] ?? 0),
            ]);
        }
    }

    /**
     * 统一分页参数
     * @return array{0:int,1:int}
     */
    protected function pageParams(int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $page  = max(1, (int)$this->request->param('page', 1));
        $limit = (int)$this->request->param('limit', $defaultLimit);
        $limit = $limit > 0 ? min($limit, $maxLimit) : $defaultLimit;
        return [$page, $limit];
    }

    /**
     * 统一排序参数（防注入：仅允许白名单字段）
     * @param array  $allow     允许排序的字段
     * @param string $default   默认字段
     * @return array{0:string,1:string}
     */
    protected function orderParams(array $allow, string $default = 'id'): array
    {
        $orderby  = (string)$this->request->param('orderby', $default);
        $orderway = strtolower((string)$this->request->param('orderway', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($orderby, $allow, true)) {
            $orderby = $default;
        }
        return [$orderby, $orderway];
    }

    /**
     * 分页结果统一结构
     */
    protected function pageResult($list, int $total, int $page, int $limit): array
    {
        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /**
     * 取当前登录用户 ID
     * @return int
     */
    protected function userId(): int
    {
        return (int)($this->auth->id ?? 0);
    }

    /**
     * 业务失败响应（统一带 request_id）
     * @param string $msg
     * @param int    $code
     * @param mixed  $data
     */
    protected function fail(string $msg, int $code = self::CODE_BIZ_FAIL, $data = null): void
    {
        $this->result($msg, $data, $code);
    }

    protected ?\app\common\service\i18n\TranslationService $translationService = null;

    protected function translations(): \app\common\service\i18n\TranslationService
    {
        return $this->translationService ??= new \app\common\service\i18n\TranslationService();
    }

    /**
     * 覆盖基类 result，追加 request_id 并统一 code/msg/data 结构
     */
    public function result(string $msg, mixed $data = null, int $code = 0, ?string $type = null, array $header = [], array $options = [])
    {
        $result = [
            'code'       => $code,
            'msg'        => $this->translations()->message($msg),
            'data'       => $data,
            'request_id' => $this->requestId,
            'time'       => $this->request->server('REQUEST_TIME'),
        ];
        $header['X-Request-Id'] = $this->requestId;

        $type = $type ?: $this->responseType;
        $statusCode = $header['statusCode'] ?? 200;

        $response = \think\Response::create($result, $type, $statusCode)->header($header)->options($options);
        throw new \think\exception\HttpResponseException($response);
    }
}
