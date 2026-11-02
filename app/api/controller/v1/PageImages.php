<?php

// +----------------------------------------------------------------------
// | API v1 —— 页面图片（公开）
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;

/**
 * 套餐接口
 *
 * 路由：
 *   GET /api/v1/page/images
 */
class PageImages extends Base
{
    protected $noNeedLogin = ['index'];
    protected $noNeedRight = '*';

    /**
     * 套餐列表
     * 价格为后台可配置，此处仅返回启用中的套餐。
     */
    public function index()
    {
        $page = $this->request->post('page', '');
        $list = Db::name('page_images')
            ->where('page', $page)
            ->where('lang', $this->app->lang->getLangSet())
            ->where('status', 'normal')
            ->order('weigh', 'desc')
            ->field('url')
            ->select();
        $this->success('', ['list' => $list]);
    }
}
