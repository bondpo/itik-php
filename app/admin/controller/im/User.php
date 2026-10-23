<?php

declare(strict_types=1);

namespace app\admin\controller\im;

use app\common\controller\Backend;
use app\common\service\MatrixService;
use think\facade\Db;

/**
 * IM 用户与设备会话管理
 */
class User extends Backend
{
    /**
     * Matrix 绑定用户列表
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();

            $query = Db::name('app_identity')
                ->alias('i')
                ->join('user u', 'u.id = i.user_id')
                ->where('i.type', 'matrix')
                ->field('i.id, i.user_id, i.identifier as matrix_user_id, i.create_time, u.username, u.nickname, u.email, u.mobile, u.status as user_status');

            $total = (clone $query)->where($where)->count();
            $list = $query->where($where)
                ->order('i.id', 'desc')
                ->limit($offset, $limit)
                ->select()
                ->toArray();

            return $this->result('ok', $list, $total);
        }

        return $this->view->fetch();
    }

    /**
     * 查询指定用户在线设备
     */
    public function devices()
    {
        $userId = (int) $this->request->get('user_id', 0);
        if ($userId <= 0) {
            $this->error('缺少用户ID');
        }

        $matrix = new MatrixService();
        $res = $matrix->getUserDevicesAdmin($userId);

        if (!$res['success']) {
            $this->error($res['error'] ?? '查询设备失败');
        }

        return json([
            'code' => 1,
            'msg'  => 'ok',
            'data' => [
                'devices' => $res['devices'],
            ],
        ]);
    }

    /**
     * 单个设备强制下线
     */
    public function revokeDevice()
    {
        $userId   = (int) $this->request->post('user_id', 0);
        $deviceId = (string) $this->request->post('device_id', '');

        if ($userId <= 0 || empty($deviceId)) {
            $this->error('参数错误');
        }

        $matrix = new MatrixService();
        $ok = $matrix->deleteDeviceAdmin($userId, $deviceId);

        if (!$ok) {
            $this->error('设备下线失败');
        }

        $this->success('设备已成功强制下线！');
    }

    /**
     * 一键下线该用户的所有在线设备
     */
    public function revokeAllDevices()
    {
        $userId = (int) $this->request->post('user_id', 0);
        if ($userId <= 0) {
            $this->error('缺少用户ID');
        }

        $matrix = new MatrixService();
        $res = $matrix->revokeAllDevicesAdmin($userId);

        if (!$res['success']) {
            $this->error($res['error'] ?? '一键下线失败');
        }

        $count = $res['count'] ?? 0;
        $this->success("已成功清退全部 {$count} 个在线设备！");
    }

    /**
     * 冻结 / 解除冻结 Matrix 账号
     */
    public function toggleStatus()
    {
        $userId = (int) $this->request->post('user_id', 0);
        $status = (string) $this->request->post('status', 'deactivate');

        if ($userId <= 0) {
            $this->error('缺少用户ID');
        }

        $matrix = new MatrixService();
        if ($status === 'deactivate') {
            $res = $matrix->deactivateUserAdmin($userId, false);
            // 同时清退其所有设备
            $matrix->revokeAllDevicesAdmin($userId);
            $msg = '账号已冻结，且已踢出所有登录设备！';
        } else {
            $res = $matrix->reactivateUserAdmin($userId);
            $msg = '账号已恢复正常使用！';
        }

        if (!$res['success']) {
            $this->error($res['error'] ?? '操作失败');
        }

        $this->success($msg);
    }

    /**
     * 强制重置密码
     */
    public function resetPassword()
    {
        $userId = (int) $this->request->post('user_id', 0);
        $newPwd = (string) $this->request->post('new_password', '');

        if ($userId <= 0 || strlen($newPwd) < 6) {
            $this->error('密码至少需要 6 个字符');
        }

        $matrix = new MatrixService();
        $res = $matrix->resetPasswordAdmin($userId, $newPwd, true);

        if (!$res['success']) {
            $this->error($res['error'] ?? '密码重置失败');
        }

        $this->success('密码重置成功，已下线旧设备！');
    }
}
