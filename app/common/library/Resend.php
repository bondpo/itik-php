<?php

namespace app\common\library;

use think\facade\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * Resend 邮件接口服务类
 * 官方接口文档: https://resend.com/docs/api-reference/emails/send-email
 */
class Resend
{
    /**
     * 发送邮件
     *
     * @param array $params 参数列表，支持:
     *   - from: 发件人（如 "ITIK <service@itiklink.com>"），未传则读取配置
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

        $apiUrl = config('resend.api_url', 'https://api.resend.com/emails');

        try {
            $client = new Client([
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'Resend-PHP/1.0',
                ],
            ]);

            $response = $client->post($apiUrl, [
                'json' => $payload,
            ]);

            $content = (string)$response->getBody();
            $result = json_decode($content, true) ?: [];

            if (empty($result['id'])) {
                throw new \RuntimeException('Resend 响应异常: ' . $content);
            }

            return $result;
        } catch (RequestException $e) {
            $errorMsg = $e->getMessage();
            if ($e->hasResponse()) {
                $body = (string)$e->getResponse()->getBody();
                $errJson = json_decode($body, true);
                if (isset($errJson['message'])) {
                    $errorMsg = $errJson['message'];
                }
            }
            Log::error('Resend 邮件发送失败: ' . $errorMsg);
            throw new \RuntimeException('Resend 邮件发送失败: ' . $errorMsg, 0, $e);
        } catch (TransferException $e) {
            Log::error('Resend 网络请求异常: ' . $e->getMessage());
            throw new \RuntimeException('Resend 网络请求异常: ' . $e->getMessage(), 0, $e);
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
