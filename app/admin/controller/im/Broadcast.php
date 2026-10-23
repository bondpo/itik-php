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
            $targetId   = trim((string) ($params['target_id'] ?? ''));

            if (empty($title) || empty($content)) {
                $this->error('标题和内容不能为空');
            }

            $matrix = new MatrixService();
            $botToken = (string) config('matrix.bot_token');
            if (empty($botToken)) {
                $botToken = (string) config('matrix.admin_token');
            }

            $successCount = 0;
            $failCount = 0;
            $lastError = '';
            $targetSummary = '';

            if ($targetType === 'all') {
                // 方案 1：向全员用户发起官方小助手 1v1 私信通知
                $identities = Db::name('app_identity')
                    ->where('type', 'matrix')
                    ->select()
                    ->toArray();

                $targetSummary = '全站已激活用户 (共 ' . count($identities) . ' 人)';

                foreach ($identities as $user) {
                    $res = $matrix->sendBotDirectNotice(
                        $user['identifier'],
                        $msgType,
                        $title,
                        $content,
                        $jumpUrl,
                        (int) $user['user_id']
                    );

                    if (!empty($res['event_id']) || ($res['success'] ?? false)) {
                        $successCount++;
                    } else {
                        $failCount++;
                        $lastError = $res['error'] ?? '未知错误';
                    }
                }
            } elseif ($targetType === 'user') {
                // 指定用户 1v1 私聊
                $rawIds = explode(',', $targetId);
                $targetSummary = $targetId;

                foreach ($rawIds as $idItem) {
                    $idItem = trim($idItem);
                    if (empty($idItem)) {
                        continue;
                    }

                    $res = $matrix->sendBotDirectNotice(
                        $idItem,
                        $msgType,
                        $title,
                        $content,
                        $jumpUrl
                    );

                    if (!empty($res['event_id']) || ($res['success'] ?? false)) {
                        $successCount++;
                    } else {
                        $failCount++;
                        $lastError = $res['error'] ?? '无法送达该用户';
                    }
                }
            } elseif ($targetType === 'room') {
                // 指定群聊房间
                $targetRooms = explode(',', $targetId);
                $targetSummary = $targetId;

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
                    } else {
                        $failCount++;
                        $lastError = $res['error'] ?? '群发失败 (Bot 可能未在此群内)';
                    }
                }
            }

            // 记录到广播历史
            $this->model->insert([
                'title'       => $title,
                'msg_type'    => $msgType,
                'target_type' => $targetType,
                'target_id'   => $targetSummary,
                'content'     => $content,
                'jump_url'    => $jumpUrl,
                'admin_id'    => (int) ($this->auth->id ?? 1),
                'sent_count'  => $successCount,
                'create_time' => time(),
            ]);

            if ($successCount > 0) {
                $this->success("广播推送完成！已成功投递到 {$successCount} 个目标" . ($failCount > 0 ? "，{$failCount} 个失败" : '。'));
            } else {
                $this->error("广播投递失败 (成功 0，失败 {$failCount})：" . ($lastError ?: '暂无有效目标'));
            }
        }

        return $this->view->fetch();
    }
}
