<?php

namespace app\api\controller\v1;

/**
 * App 升级检查（公开接口）
 * GET /api/v1/upgrade/version?platform=android|ios
 */
class Upgrade extends Base
{
    protected $noNeedLogin = ['version'];
    protected $noNeedRight = '*';

    public function version()
    {
        $platform = strtolower((string)$this->request->get('platform', 'android'));
        if ($platform !== 'android' && $platform !== 'ios') {
            $platform = 'android';
        }

        $all = config('app_release');
        if (!is_array($all)) {
            $all = [];
        }
        $row = $all[$platform] ?? [];
        if (!is_array($row) || $row === []) {
            $this->success('ok', [
                'has_update'   => false,
                'platform'     => $platform,
                'version_name' => '',
                'version_code' => 0,
                'force'        => false,
                'title'        => '',
                'content'      => '',
                'pkg_type'     => 'apk',
                'url'          => '',
            ]);
        }

        $code = (int)($row['version_code'] ?? 0);
        $name = (string)($row['version_name'] ?? '');
        $url  = trim((string)($row['url'] ?? ''));
        $type = strtolower((string)($row['pkg_type'] ?? 'apk'));
        if (!in_array($type, ['apk', 'wgt', 'store'], true)) {
            $type = 'apk';
        }

        $this->success('ok', [
            'has_update'   => $code > 0 && ($url !== '' || $type === 'store'),
            'platform'     => $platform,
            'version_name' => $name,
            'version_code' => $code,
            'force'        => !empty($row['force']),
            'title'        => (string)($row['title'] ?? '发现新版本'),
            'content'      => (string)($row['content'] ?? ''),
            'pkg_type'     => $type,
            'url'          => $url,
        ]);
    }
}
