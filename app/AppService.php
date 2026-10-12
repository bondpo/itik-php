<?php

declare(strict_types=1);

namespace app;

use think\Service;
use think\facade\Event;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        $cmsHelper = root_path() . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'cms.php';
        if (is_file($cmsHelper)) {
            include_once $cmsHelper;
        }
        bind('think\Paginator', 'app\common\library\cms\Bootstrap');
    }

    public function boot()
    {
        // 服务启动
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
        ini_set('display_errors', '0');
    }
}
