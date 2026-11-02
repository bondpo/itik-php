<?php

namespace app\common\library;

use Aws\S3\S3Client;
use RuntimeException;
use Throwable;

class SiloStorage
{
    protected array $config = [];

    protected S3Client $client;

    public function __construct()
    {
        $this->config = config('silo');

        if (empty($this->config['enabled'])) {
            throw new RuntimeException('SILO storage is not enabled');
        }

        if (
            empty($this->config['endpoint']) ||
            empty($this->config['bucket']) ||
            empty($this->config['access_key']) ||
            empty($this->config['secret_key'])
        ) {
            throw new RuntimeException('SILO configuration is incomplete');
        }

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $this->config['region'] ?: 'us-east-1',
            'endpoint' => $this->config['endpoint'],
            'use_path_style_endpoint' => true,
            'signature_version' => 'v4',
            'credentials' => [
                'key' => $this->config['access_key'],
                'secret' => $this->config['secret_key'],
            ],
            'http' => [
                'connect_timeout' => 10,
                'timeout' => 600,
            ],
        ]);
    }

    /**
     * 上传文件
     */
    public function upload(
        string $filePath,
        string $objectKey,
        string $contentType = 'application/octet-stream'
    ): array {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Upload source file does not exist');
        }

        $objectKey = ltrim($objectKey, '/');

        try {
            $result = $this->client->putObject([
                'Bucket' => $this->config['bucket'],
                'Key' => $objectKey,
                'SourceFile' => $filePath,
                'ContentType' => $contentType,
                'CacheControl' => $this->config['cache_control'] ?? 'public, max-age=31536000',
            ]);

            return [
                'key' => $objectKey,
                'etag' => isset($result['ETag'])
                    ? trim($result['ETag'], '"')
                    : '',
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                'SILO upload failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * 删除对象
     */
    public function delete(string $objectKey): bool
    {
        $objectKey = ltrim($objectKey, '/');

        try {
            $this->client->deleteObject([
                'Bucket' => $this->config['bucket'],
                'Key' => $objectKey,
            ]);

            return true;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'SILO delete failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * 获取对象公开 URL
     */
    public function getPublicUrl(string $objectKey): string
    {
        $baseUrl = rtrim($this->config['public_url'], '/');
        $bucket = trim($this->config['bucket'], '/');
        $objectKey = ltrim($objectKey, '/');

        // 如果 public_url 尚未包含 Bucket，则自动补上
        if (!preg_match('~/' . preg_quote($bucket, '~') . '$~', $baseUrl)) {
            $baseUrl .= '/' . $bucket;
        }

        return $baseUrl . '/' . $objectKey;
    }

    /**
     * 检查 Bucket 连接
     */
    public function checkConnection(): bool
    {
        try {
            $this->client->headBucket([
                'Bucket' => $this->config['bucket'],
            ]);

            return true;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'SILO connection failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
