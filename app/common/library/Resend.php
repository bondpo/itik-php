<?php

namespace app\common\library;

use think\facade\Log;
use Resend as ResendClient;
use Throwable;

/**
 * Resend 邮件服务类
 * 基于官方 SDK resend/resend-php 实现
 * 文档: https://resend.com/docs/send-with-php
 */
class Resend
{
    /**
     * 发送邮件
     *
     * @param array $params 参数列表，支持:
     *   - from: 发件人（如 "ITIK <service@itiklink.com>"），未传则读取系统配置
     *   - to: 收件人（邮箱字符串或邮箱数组）
     *   - subject: 邮件主题
     *   - html: HTML邮件内容
     *   - text: 纯文本邮件内容（可选）
     *   - cc: 抄送（可选）
     *   - bcc: 密送（可选）
     *   - reply_to: 回复地址（可选）
     *   - api_key: 自定义 API Key（可选，默认读取系统配置）
     * @return array{id: string}
     * @throws \RuntimeException
     */
    public static function send(array $params): array
    {
        $apiKey = $params['api_key'] ?? (get_sys_config('resend_key') ?: config('resend.api_key'));
        if (!$apiKey) {
            throw new \RuntimeException('Resend API Key 未配置，请在系统配置或 .env 中设置');
        }

        // 处理发件人
        $from = $params['from'] ?? '';
        if (!$from) {
            $fromEmail = get_sys_config('resend_from') ?: config('resend.from_email', 'service@itiklink.com');
            $fromName  = get_sys_config('resend_from_name') ?: config('resend.from_name', 'ITIK');
            $from = $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail;
        }

        // 处理收件人，兼容字符串与数组
        $to = $params['to'] ?? [];
        if (is_string($to)) {
            $to = preg_split('/[\s,;]+/', trim($to), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (empty($to)) {
            throw new \RuntimeException('收件人邮箱不能为空');
        }

        $subject = (string)($params['subject'] ?? '无主题');
        $html    = (string)($params['html'] ?? '');
        $text    = (string)($params['text'] ?? '');

        if ($html === '' && $text === '') {
            $html = '<p></p>';
        }

        $payload = [
            'from'    => $from,
            'to'      => array_values($to),
            'subject' => $subject,
        ];

        if ($html !== '') {
            $payload['html'] = $html;
        }
        if ($text !== '') {
            $payload['text'] = $text;
        }
        if (!empty($params['cc'])) {
            $payload['cc'] = is_array($params['cc']) ? array_values($params['cc']) : [$params['cc']];
        }
        if (!empty($params['bcc'])) {
            $payload['bcc'] = is_array($params['bcc']) ? array_values($params['bcc']) : [$params['bcc']];
        }
        if (!empty($params['reply_to'])) {
            $payload['reply_to'] = $params['reply_to'];
        }

        try {
            // 调用官方 SDK
            $resend = ResendClient::client($apiKey);
            $result = $resend->emails->send($payload);

            $emailId = $result->id ?? ($result['id'] ?? '');
            if (!$emailId) {
                throw new \RuntimeException('Resend 返回缺少邮件 ID');
            }

            return [
                'id' => (string)$emailId,
            ];
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            Log::error('Resend 邮件发送失败: ' . $errorMsg);
            throw new \RuntimeException('Resend 邮件发送失败: ' . $errorMsg, 0, $e);
        }
    }

    /**
     * 快捷发送单封邮件
     *
     * @param string|array $to 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $html HTML 内容
     * @param array $options 额外参数
     * @return array{id: string}
     */
    public static function sendEmail($to, string $subject, string $html, array $options = []): array
    {
        $params = array_merge($options, [
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
        ]);

        return self::send($params);
    }
}
