<?php

// +----------------------------------------------------------------------
// | API v1 —— 套餐（公开）
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;

/**
 * 套餐接口
 *
 * 路由：
 *   GET /api/v1/roles/plans   套餐列表（三档年费订阅）
 */
class Plan extends Base
{
    protected $noNeedLogin = ['index'];
    protected $noNeedRight = '*';

    /**
     * 套餐列表
     * 价格为后台可配置，此处仅返回启用中的套餐。
     */
    public function index()
    {
        $list = Db::name('app_plan')
            ->where('status', 'normal')
            ->field('plan_code,role_code,name,price,currency,duration_days,feature_flags,weigh')
            ->order('weigh', 'desc')
            ->select();

        $list = $list->toArray();
        foreach ($list as $k => $item) {
            $flags = json_decode((string)$item['feature_flags'], true);
            $list[$k]['feature_flags'] = is_array($flags) ? $flags : [];
            $list[$k]['price']         = (float)$item['price'];
            $list[$k]['duration_days'] = (int)$item['duration_days'];
        }

        $this->success('', ['list' => $list]);
    }
}
