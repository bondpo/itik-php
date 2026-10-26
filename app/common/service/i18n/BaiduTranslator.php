<?php

namespace app\common\service\i18n;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class BaiduTranslator
{
    private ClientInterface $client;
    private array $options;
    private float $lastRequest = 0;

    public function __construct(array $options, ?ClientInterface $client = null)
    {
        $this->options = $options;
        $this->client = $client ?: new Client();
    }

    public function translate(string $text, string $from, string $to): string
    {
        if ($text === '' || trim($text) === '') {
            return $text;
        }
        // 富文本仅翻译文本节点，标签、属性、链接和脚本保持原样。
        if (preg_match('/<[a-z][^>]*>/i', $text)) {
            $document = new \DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8"><html><body><div id="i18n-root">' . $text . '</div></body></html>', LIBXML_NONET);
                $xpath = new \DOMXPath($document);
                $root = $xpath->query('//div[@id="i18n-root"]')->item(0);
                if (!$root) {
                    throw new \RuntimeException('富文本解析失败');
                }
                $nodes = $xpath->query('.//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::code) and not(ancestor::pre)]', $root);
                foreach ($nodes as $node) {
                    if (trim($node->nodeValue) !== '') {
                        $node->nodeValue = $this->plainText($node->nodeValue, $from, $to);
                    }
                }
                $html = '';
                foreach ($root->childNodes as $node) {
                    $html .= $document->saveHTML($node);
                }
                return $html;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        return $this->plainText($text, $from, $to);
    }

    private function plainText(string $text, string $from, string $to): string
    {
        // 占位符单独保留，避免机器翻译破坏模板变量与 printf 格式。
        $parts = preg_split('/(\{\{[^{}]+\}\}|\{[a-zA-Z_][a-zA-Z0-9_.]*\}|%(?:\d+\$)?[-+0-9.]*[sdf])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1 || trim($part) === '') {
                continue;
            }
            preg_match('/^(\s*)(.*?)(\s*)$/su', $part, $whitespace);
            $part = $whitespace[2];
            $translated = '';
            while ($part !== '') {
                // 保守控制在 900 字节，兼容低配额账户并避免 UTF-8 截断。
                $chunk = mb_strcut($part, 0, 900, 'UTF-8');
                $part = substr($part, strlen($chunk));
                $translated .= $this->request($chunk, $from, $to);
            }
            $parts[$index] = $whitespace[1] . trim($translated) . $whitespace[3];
        }
        return implode('', $parts);
    }

    private function request(string $text, string $from, string $to): string
    {
        $wait = ((int)$this->options['interval_ms'] / 1000) - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int)($wait * 1000000));
        }
        $salt = bin2hex(random_bytes(16));
        $this->lastRequest = microtime(true);
        try {
            $response = $this->client->request('POST', 'https://fanyi-api.baidu.com/api/trans/vip/translate', [
                'timeout' => (int)$this->options['timeout'], 'connect_timeout' => 5,
                'allow_redirects' => false,
                'form_params' => [
                    'q' => $text, 'from' => $from, 'to' => $to,
                    'appid' => $this->options['appid'], 'salt' => $salt,
                    'sign' => md5($this->options['appid'] . $text . $salt . $this->options['secret']),
                ],
            ]);
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // HTTP 异常可能携带请求信息，不向日志或管理端暴露正文及凭据。
            throw new \RuntimeException('百度翻译请求失败，请检查网络及服务状态');
        }
        if (isset($result['error_code'])) {
            $code = preg_replace('/[^0-9]/', '', (string)$result['error_code']);
            throw new \RuntimeException('百度翻译错误码：' . $code);
        }
        if (empty($result['trans_result']) || !is_array($result['trans_result'])) {
            throw new \RuntimeException('百度翻译返回格式不正确');
        }
        $lines = [];
        foreach ($result['trans_result'] as $line) {
            if (!isset($line['dst']) || !is_string($line['dst'])) {
                throw new \RuntimeException('百度翻译返回译文不正确');
            }
            $lines[] = $line['dst'];
        }
        return implode("\n", $lines);
    }
}
