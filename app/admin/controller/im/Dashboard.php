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
    protected $noNeedRight = ['index', 'ping', 'check', 'botPing'];

    /**
     * 监控大盘主页
     */
    public function index()
    {
        $matrix = new MatrixService();
        $ping = $matrix->ping();
        $bot  = $matrix->getBotStatus();

        $stats = [
            'total_users'     => Db::name('app_identity')->where('type', 'matrix')->count(),
            'total_rooms'     => Db::name('im_room')->where('status', 'normal')->count(),
            'total_broadcast' => Db::name('im_broadcast')->count(),
            'ping'            => $ping,
            'server_name'     => config('matrix.server_name'),
            'base_url'        => config('matrix.base_url'),
            'configured'      => !empty(config('matrix.admin_token')),
            'bot'             => $bot,
        ];

        if ($this->request->isAjax()) {
            return json([
                'code' => 1,
                'msg'  => 'ok',
                'data' => $stats,
            ]);
        }

        $this->assign('stats', $stats);
        return $this->view->fetch();
    }

    /**
     * 系统机器人探测 (Bot Ping)
     */
    public function botPing()
    {
        $matrix = new MatrixService();
        $bot = $matrix->getBotStatus();
        return json([
            'code' => $bot['valid'] ? 1 : 0,
            'msg'  => $bot['valid'] ? '系统机器人在线正常' : ($bot['error'] ?? $bot['status']),
            'data' => $bot,
        ]);
    }

    /**
     * 快速心跳探测 (Ping)
     */
    public function ping()
    {
        $matrix = new MatrixService();
        $res = $matrix->ping();
        return json([
            'code' => $res['success'] ? 1 : 0,
            'msg'  => $res['success'] ? '服务运行正常' : ($res['error'] ?? '服务无法连通'),
            'data' => $res,
        ]);
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

        return json([
            'code' => $report['all_passed'] ? 1 : 0,
            'msg'  => $report['all_passed'] ? '全部测试用例通过！' : '部分测试用例未通过',
            'data' => $report,
        ]);
    }
}

