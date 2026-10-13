<?php

declare(strict_types=1);

namespace app\admin\controller\im;

use app\common\controller\Backend;
use app\common\service\MatrixService;
use think\facade\Db;

/**
 * IM 监控大盘与服务状态
 */
class Dashboard extends Backend
{
    protected $noNeedRight = ['index', 'ping', 'check'];

    /**
     * 监控大盘主页
     */
    public function index()
    {
        $matrix = new MatrixService();
        $ping = $matrix->ping();

        $stats = [
            'total_users'     => Db::name('app_identity')->where('type', 'matrix')->count(),
            'total_rooms'     => Db::name('im_room')->where('status', 'normal')->count(),
            'total_broadcast' => Db::name('im_broadcast')->count(),
            'ping'            => $ping,
            'server_name'     => config('matrix.server_name'),
            'base_url'        => config('matrix.base_url'),
            'configured'      => !empty(config('matrix.admin_token')),
        ];

        if ($this->request->isAjax()) {
            $this->success('', $stats);
        }

        $this->assign('stats', $stats);
        return $this->view->fetch();
    }

    /**
     * 快速心跳探测 (Ping)
     */
    public function ping()
    {
        $matrix = new MatrixService();
        $res = $matrix->ping();
        if ($res['success']) {
            $this->success('服务运行正常', $res);
        }
        $this->error($res['error'] ?? '服务无法连通');
    }

    /**
     * 运行端到端自动化集成测试套件
     */
    public function check()
    {
        $userA = (string) $this->request->post('userA', 'adm_test_a');
        $userB = (string) $this->request->post('userB', 'adm_test_b');

        $matrix = new MatrixService();
        $report = $matrix->runTestSuite($userA, $userB);

        if ($report['all_passed']) {
            $this->success('全部测试用例通过！', $report);
        }
        $this->error('部分测试用例未通过', '', $report);
    }
}
