<?php

// +----------------------------------------------------------------------
// | API v1 —— 推流投放
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;
use app\common\service\app\PromotionService;

/**
 * 推流接口
 *
 * 路由：
 *   GET  /api/v1/promotions/packages           推流套餐（公开）
 *   GET  /api/v1/promotions/active             当前生效投放（供前台展示位）
 *   GET  /api/v1/promotions/campaigns          我的投放列表
 *   POST /api/v1/promotions/campaigns          创建投放（OWNER + 订阅 + 素材已审核）
 *   GET  /api/v1/promotions/campaigns/stats    投放归因数据
 *   POST /api/v1/promotions/campaigns/cancel   取消投放
 *   POST /api/v1/promotions/track              归因上报（曝光/点击/咨询/关注）
 */
class Promotion extends Base
{
    protected $noNeedLogin = ['packages', 'active', 'track'];
    protected $noNeedRight = '*';

    /** @var PromotionService */
    protected $service;

    public function initialize()
    {
        parent::initialize();
        $this->service = new PromotionService();
    }

    /**
     * 推流套餐（公开）
     */
    public function packages()
    {
        $list = Db::name('app_promotion_package')
            ->where('status', 'normal')
            ->field('id,name,position,price,currency,duration_days,exposure_cap,feature_flags')
            ->order('id', 'asc')
            ->select();
        foreach ($list as $k => $item) {
            $flags = json_decode((string)$item['feature_flags'], true);
            $list[$k]['feature_flags'] = is_array($flags) ? $flags : [];
            $list[$k]['price'] = (float)$item['price'];
            $list[$k]['duration_days'] = (int)$item['duration_days'];
            $list[$k]['exposure_cap'] = (int)$item['exposure_cap'];
        }
        $this->success('', ['list' => $list]);
    }

    /**
     * 当前生效投放（公开，供首页/频道页展示位）
     */
    public function active()
    {
        $position = (string)$this->request->param('position', '');
        $limit = min(20, max(1, (int)$this->request->param('limit', 10)));
        $this->success('', ['list' => $this->service->activeByPosition($position, $limit)]);
    }

    /**
     * 我的投放列表
     */
    public function campaigns()
    {
        $this->requireRole(self::ROLE_OWNER, '仅项目方可管理投放');
        [$page, $limit] = $this->pageParams();

        $query = Db::name('app_promotion_campaign')->where('user_id', $this->userId());
        $status = (string)$this->request->param('status', '');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $projectId = (int)$this->request->param('project_id', 0);
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)
            ->field('id,project_id,package_id,position,material_id,budget,currency,start_at,end_at,status,review_status,review_reason,exposure_used,create_time')
            ->select();

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 创建投放
     */
    public function createCampaign()
    {
        $this->requireRole(self::ROLE_OWNER, '仅项目方可创建投放');
        $this->requireSubscription(true, '订阅不可用，请先开通或续费');

        $projectId  = (int)$this->request->post('project_id', 0);
        $packageId  = (int)$this->request->post('package_id', 0);
        $materialId = (int)$this->request->post('material_id', 0);
        $startAt    = (string)$this->request->post('start_at', '');
        $endAt      = (string)$this->request->post('end_at', '');

        if ($projectId <= 0 || $packageId <= 0) {
            $this->fail('缺少项目或套餐参数', self::CODE_PARAM_MISSING);
        }

        try {
            $campaign = $this->service->createCampaign($this->userId(), $projectId, $packageId, $materialId, $startAt, $endAt);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $this->success('投放计划已提交，等待审核', ['campaign' => $campaign]);
    }

    /**
     * 投放归因数据
     */
    public function stats()
    {
        $id = (int)$this->request->param('id', 0);
        if ($id <= 0) {
            $this->fail('缺少投放 ID', self::CODE_PARAM_MISSING);
        }
        $campaign = Db::name('app_promotion_campaign')->where('id', $id)->find();
        if (!$campaign) {
            $this->fail('投放计划不存在', self::CODE_NOT_FOUND);
        }
        if ((int)$campaign['user_id'] !== $this->userId() && $this->role !== self::ROLE_OWNER) {
            $this->fail('无权查看该投放数据', self::CODE_FORBIDDEN);
        }

        $this->success('', ['stats' => $this->service->statsOf($id)]);
    }

    /**
     * 取消投放
     */
    public function cancelCampaign()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->fail('缺少投放 ID', self::CODE_PARAM_MISSING);
        }
        $campaign = Db::name('app_promotion_campaign')->where('id', $id)->find();
        if (!$campaign) {
            $this->fail('投放计划不存在', self::CODE_NOT_FOUND);
        }
        if ((int)$campaign['user_id'] !== $this->userId()) {
            $this->fail('无权操作该投放', self::CODE_FORBIDDEN);
        }
        if (!in_array($campaign['status'], [PromotionService::ST_PENDING, PromotionService::ST_NORMAL], true)) {
            $this->fail('当前状态不可取消', self::CODE_STATE_INVALID, ['status' => $campaign['status']]);
        }

        Db::name('app_promotion_campaign')->where('id', $id)->update([
            'status' => PromotionService::ST_CANCELED, 'update_time' => time(),
        ]);

        $this->success('已取消投放');
    }

    /**
     * 归因上报（曝光/点击/咨询/关注）
     * 说明：由前台展示位与落地页调用，属于公开统计接口，但仅对「投放中」计划生效。
     */
    public function track()
    {
        $id     = (int)$this->request->post('campaign_id', 0);
        $metric = (string)$this->request->post('metric', 'exposure');
        $count  = (int)$this->request->post('count', 1);

        if ($id <= 0) {
            $this->fail('缺少 campaign_id', self::CODE_PARAM_MISSING);
        }

        try {
            $stats = $this->service->track($id, $metric, $count);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $this->success('', ['stats' => $stats]);
    }
}
