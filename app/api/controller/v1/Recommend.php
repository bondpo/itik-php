<?php

// +----------------------------------------------------------------------
// | API v1 —— 推荐与配额
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use app\common\service\app\RecommendService;

/**
 * 推荐接口
 *
 * 路由：
 *   GET  /api/v1/recommendations            我的推荐列表（按角色配额）
 *   POST /api/v1/recommendations/generate   手动触发一次推荐生成（受配额限制）
 *   POST /api/v1/recommendations/view       标记已查看
 *   POST /api/v1/recommendations/respond    标记已响应
 *   POST /api/v1/recommendations/feedback   反馈（相关/不相关）
 *   GET  /api/v1/recommendations/quota      剩余配额
 */
class Recommend extends Base
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /** @var RecommendService */
    protected $service;

    public function initialize()
    {
        parent::initialize();
        $this->service = new RecommendService();
    }

    /**
     * 我的推荐列表
     */
    public function index()
    {
        $this->requireRole([self::ROLE_OWNER, self::ROLE_LEADER], '仅项目方与社区长可使用推荐功能');
        $this->requireSubscription(true, '订阅不可用，请先开通或续费');

        [$page, $limit] = $this->pageParams();
        $channel = (string)$this->request->param('channel', '');
        if ($channel === '') {
            $channel = $this->role === self::ROLE_LEADER
                ? RecommendService::CHANNEL_LEADER_MEMBER
                : RecommendService::CHANNEL_OWNER_MATCH;
        }
        if (!in_array($channel, [RecommendService::CHANNEL_OWNER_MATCH, RecommendService::CHANNEL_LEADER_MEMBER], true)) {
            $this->fail('推荐渠道不合法', self::CODE_PARAM_INVALID);
        }

        $ret = $this->service->listFor($this->userId(), $channel, $page, $limit);
        $ret['quota'] = [
            'channel' => $channel,
            'limit'   => $this->service->quotaLimit($channel),
            'used'    => $this->service->quotaUsed($this->userId(), $channel),
            'remain'  => $this->service->quotaRemain($this->userId(), $channel),
        ];

        $this->success('', $ret);
    }

    /**
     * 生成推荐（受配额限制；社区长每日上限默认 5）
     */
    public function generate()
    {
        $this->requireRole([self::ROLE_OWNER, self::ROLE_LEADER], '仅项目方与社区长可使用推荐功能');
        $this->requireSubscription(true, '订阅不可用，请先开通或续费');

        $channel = (string)$this->request->post('channel', '');
        if ($channel === '') {
            $channel = $this->role === self::ROLE_LEADER
                ? RecommendService::CHANNEL_LEADER_MEMBER
                : RecommendService::CHANNEL_OWNER_MATCH;
        }
        if (!in_array($channel, [RecommendService::CHANNEL_OWNER_MATCH, RecommendService::CHANNEL_LEADER_MEMBER], true)) {
            $this->fail('推荐渠道不合法', self::CODE_PARAM_INVALID);
        }
        $want = max(0, (int)$this->request->post('want', 0));

        $ret = $this->service->generate($this->userId(), $channel, $want);
        if ($ret['generated'] === 0 && !empty($ret['message'])) {
            $this->fail($ret['message'], self::CODE_RATE_LIMITED, $ret);
        }

        $this->success($this->translations()->formatMessage('已生成 {count} 条推荐', ['count' => $ret['generated']]), $ret);
    }

    /**
     * 标记已查看
     */
    public function markView()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->fail('缺少推荐 ID', self::CODE_PARAM_MISSING);
        }
        if (!$this->service->mark($this->userId(), $id, 'viewed')) {
            $this->fail('推荐记录不存在', self::CODE_NOT_FOUND);
        }
        $this->success('已标记');
    }

    /**
     * 标记已响应
     */
    public function markRespond()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->fail('缺少推荐 ID', self::CODE_PARAM_MISSING);
        }
        if (!$this->service->mark($this->userId(), $id, 'responded')) {
            $this->fail('推荐记录不存在', self::CODE_NOT_FOUND);
        }
        $this->success('已标记');
    }

    /**
     * 反馈
     */
    public function feedback()
    {
        $id = (int)$this->request->post('id', 0);
        $fb = (string)$this->request->post('feedback', '');
        if ($id <= 0) {
            $this->fail('缺少推荐 ID', self::CODE_PARAM_MISSING);
        }
        if (!in_array($fb, ['relevant', 'irrelevant'], true)) {
            $this->fail('反馈值需为 relevant 或 irrelevant', self::CODE_PARAM_INVALID);
        }
        if (!$this->service->feedback($this->userId(), $id, $fb)) {
            $this->fail('推荐记录不存在', self::CODE_NOT_FOUND);
        }
        $this->success('感谢反馈');
    }

    /**
     * 剩余配额
     */
    public function quota()
    {
        $this->requireRole([self::ROLE_OWNER, self::ROLE_LEADER], '仅项目方与社区长可查看配额');

        $data = [];
        foreach ([RecommendService::CHANNEL_OWNER_MATCH, RecommendService::CHANNEL_LEADER_MEMBER] as $ch) {
            $data[$ch] = [
                'limit'  => $this->service->quotaLimit($ch),
                'used'   => $this->service->quotaUsed($this->userId(), $ch),
                'remain' => $this->service->quotaRemain($this->userId(), $ch),
            ];
        }
        $this->success('', ['role' => $this->role, 'quota' => $data]);
    }
}
