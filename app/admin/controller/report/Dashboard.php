<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台数据看板
// +----------------------------------------------------------------------

namespace app\admin\controller\report;

use app\common\controller\Backend;
use app\common\service\app\ReportService;
use think\facade\Db;

class Dashboard extends Backend
{
    protected $noNeedRight = ['data', 'trend'];

    public function index()
    {
        $service = new ReportService();
        $this->view->assign('overview', $service->overview());
        return $this->view->fetch();
    }

    /**
     * 概览数据（Ajax 刷新）
     */
    public function data()
    {
        $service = new ReportService();
        $this->success('', null, $service->overview());
    }

    /**
     * 趋势 + 分布数据（供图表）
     */
    public function trend()
    {
        $days = (int)$this->request->param('days', 14);
        $service = new ReportService();
        $this->success('', null, [
            'trend'      => $service->trend($days),
            'role'       => $service->roleDistribution(),
            'category'   => $service->projectByCategory(),
            'plan'       => $service->subscriptionByPlan(),
        ]);
    }
}
