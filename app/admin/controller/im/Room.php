<?php

declare(strict_types=1);

namespace app\admin\controller\im;

use app\common\controller\Backend;
use app\common\service\MatrixService;
use think\facade\Db;
use think\facade\Log;

/**
 * IM 房间与群聊管理
 */
class Room extends Backend
{
    protected $model = null;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = Db::name('im_room');
    }

    /**
     * 房间索引列表
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();

            $total = $this->model
                ->where($where)
                ->count();

            $list = $this->model
                ->where($where)
                ->order($sort ?: 'id', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select()
                ->toArray();

            if (!empty($list)) {
                $creatorIds = array_unique(array_filter(array_column($list, 'creator_id')));
                $users = [];
                if (!empty($creatorIds)) {
                    $users = Db::name('user')->whereIn('id', $creatorIds)->column('username,nickname', 'id');
                }
                foreach ($list as &$item) {
                    $cId = $item['creator_id'];
                    $item['creator_name'] = isset($users[$cId])
                        ? ($users[$cId]['nickname'] ?: $users[$cId]['username'])
                        : ($cId > 0 ? "UID:{$cId}" : '系统');
                }
            }

            return $this->result('ok', $list, $total);
        }

        return $this->view->fetch();
    }

    /**
     * 获取指定房间成员（抽屉接口）
     */
    public function members()
    {
        $roomId = (string) $this->request->get('room_id', '');
        if (empty($roomId)) {
            $this->error('缺少房间 ID');
        }

        $matrix = new MatrixService();
        $adminToken = (string) config('matrix.admin_token');
        if (empty($adminToken)) {
            $this->error('尚未配置 Matrix Admin Token');
        }

        $res = $matrix->getRoomMembers($adminToken, $roomId);
        if (!$res['success']) {
            $this->error($res['error'] ?? '拉取成员失败');
        }

        $this->success('', ['members' => $res['members']]);
    }

    /**
     * 全员禁言切换
     */
    public function toggleMute()
    {
        $roomId = (string) $this->request->post('room_id', '');
        $isMute = (bool) $this->request->post('is_mute', false);

        if (empty($roomId)) {
            $this->error('缺少房间 ID');
        }

        $matrix = new MatrixService();
        $adminToken = (string) config('matrix.admin_token');
        
        // events_default: 50 代表房管才能发言（全员禁言），0 代表所有人均可发言
        $res = $matrix->setPowerLevels($adminToken, $roomId, [], $isMute ? 50 : 0);
        if (!$res['success']) {
            $this->error($res['error'] ?? '禁言设置失败');
        }

        $this->success($isMute ? '已开启全员禁言' : '已解除全员禁言');
    }

    /**
     * 【反向对账】从 Matrix 同步全量房间到本地
     */
    public function sync()
    {
        $matrix = new MatrixService();
        $res = $matrix->getAllRoomsAdmin(200);

        if (!$res['success']) {
            $this->error($res['error'] ?? '从 Matrix 同步失败');
        }

        $rooms = $res['rooms'] ?? [];
        $synced = 0;
        $now = time();

        Db::startTrans();
        try {
            foreach ($rooms as $r) {
                $roomId = $r['room_id'] ?? '';
                if (!$roomId) {
                    continue;
                }

                $name = $r['name'] ?? ($r['canonical_alias'] ?? '未命名房间');
                $memberCount = (int) ($r['joined_members'] ?? 0);
                $isPublic = ($r['public'] ?? false) ? 1 : 0;

                $exist = $this->model->where('room_id', $roomId)->find();
                if ($exist) {
                    $this->model->where('id', $exist['id'])->update([
                        'name'         => $name,
                        'member_count' => $memberCount,
                        'is_public'    => $isPublic,
                        'update_time'  => $now,
                    ]);
                } else {
                    $this->model->insert([
                        'room_id'      => $roomId,
                        'type'         => $isPublic ? 'public' : 'group',
                        'name'         => $name,
                        'creator_id'   => 0,
                        'biz_type'     => 'general',
                        'biz_id'       => 0,
                        'member_count' => $memberCount,
                        'is_public'    => $isPublic,
                        'status'       => 'normal',
                        'create_time'  => $now,
                        'update_time'  => $now,
                    ]);
                }
                $synced++;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('[IM Sync] Exception: ' . $e->getMessage());
            $this->error('同步入库异常: ' . $e->getMessage());
        }

        $this->success("成功同步对账 {$synced} 个房间数据！");
    }

    /**
     * 强制解散并物理删除房间
     */
    public function delete($ids = null)
    {
        $roomId = (string) $this->request->post('room_id', '');
        if (!$roomId) {
            $this->error('缺少房间 ID');
        }

        $matrix = new MatrixService();
        $res = $matrix->deleteRoomAdmin($roomId, true);

        if (!$res['success']) {
            $this->error($res['error'] ?? '解散房间失败');
        }

        $this->model->where('room_id', $roomId)->update([
            'status'      => 'dissolved',
            'update_time' => time(),
        ]);

        $this->success('房间已彻底解散并物理清除！');
    }
}
