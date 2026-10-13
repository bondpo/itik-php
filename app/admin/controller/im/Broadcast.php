<?php

declare(strict_types=1);

namespace app\admin\controller\im;

use app\common\controller\Backend;
use app\common\service\MatrixService;
use think\facade\Db;
use think\facade\Log;

/**
 * IM 官方小助手广播通知
 */
class Broadcast extends Backend
{
    protected $model = null;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = Db::name('im_broadcast');
    }

    /**
     * 广播发送历史列表
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();

            $total = $this->model->where($where)->count();
            $list = $this->model
                ->where($where)
                ->order($sort ?: 'id', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select()
                ->toArray();

            return $this->result('ok', $list, $total);
        }

        return $this->view->fetch();
    }

    /**
     * 执行广播发送任务
     */
    public function send()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post();
            $title      = trim((string) ($params['title'] ?? ''));
            $msgType    = (string) ($params['msg_type'] ?? 'text');
            $targetType = (string) ($params['target_type'] ?? 'all');
            $content    = trim((string) ($params['content'] ?? ''));
            $jumpUrl    = trim((string) ($params['jump_url'] ?? ''));

            if (empty($title) || empty($content)) {
                $this->error('标题和内容不能为空');
            }

            $matrix = new MatrixService();
            $botToken = (string) config('matrix.bot_token');
            if (empty($botToken)) {
                $botToken = (string) config('matrix.admin_token');
            }

            // 获取目标房间列表
            $targetRooms = [];
            if ($targetType === 'all') {
                $targetRooms = Db::name('im_room')->where('status', 'normal')->column('room_id');
            } elseif ($targetType === 'room') {
                $targetId = (string) ($params['target_id'] ?? '');
                if ($targetId) {
                    $targetRooms = explode(',', $targetId);
                }
            }

            $successCount = 0;
            foreach ($targetRooms as $roomId) {
                $roomId = trim($roomId);
                if (empty($roomId)) {
                    continue;
                }

                if ($msgType === 'card') {
                    $res = $matrix->sendCustomCard($botToken, $roomId, $title, [
                        'content'  => $content,
                        'jump_url' => $jumpUrl,
                    ], $title);
                } elseif ($msgType === 'html') {
                    $res = $matrix->sendHtmlMessage($botToken, $roomId, $content, $title);
                } else {
                    $res = $matrix->sendSystemNotice($roomId, "【{$title}】\n{$content}");
                }

                if (!empty($res['event_id']) || ($res['success'] ?? false)) {
                    $successCount++;
                }
            }

            // 记录到广播历史
            $this->model->insert([
                'title'       => $title,
                'msg_type'    => $msgType,
                'target_type' => $targetType,
                'target_id'   => implode(',', $targetRooms),
                'content'     => $content,
                'jump_url'    => $jumpUrl,
                'admin_id'    => (int) ($this->auth->id ?? 1),
                'sent_count'  => $successCount,
                'create_time' => time(),
            ]);

            $this->success("广播推送完成！已成功投递到 {$successCount} 个目标房间。");
        }

        return $this->view->fetch();
    }
}
