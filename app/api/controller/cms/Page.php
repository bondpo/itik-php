<?php

// +----------------------------------------------------------------------
// | CMS API —— 单页
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 单页接口
 *
 * 路由：
 *   GET /api/cms/pages           单页列表
 *   GET /api/cms/pages/detail    单页详情（id 或 diyname）
 */
class Page extends Base
{
    protected $noNeedLogin = ['index', 'detail'];
    protected $noNeedRight = '*';

    /**
     * 单页列表
     */
    public function index()
    {
        $list = Db::name('cms_page')
            ->where('status', 'normal')
            ->field($this->fieldString('cms_page', ['id','category_id','type','title','seotitle','keywords','description','flag','image','icon','views','likes','comments','diyname','weigh','createtime']))
            ->order('weigh', 'desc')->order('id', 'asc')
            ->limit(100)->select()->toArray();

        $this->success('', ['list' => $list]);
    }

    /**
     * 单页详情
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $diyname = trim((string)$this->request->param('diyname', ''));
        if ($id <= 0 && $diyname === '') {
            $this->fail('缺少单页标识（id 或 diyname）', self::CODE_PARAM_MISSING);
        }

        $query = Db::name('cms_page')->where('status', 'normal');
        $page = $id > 0 ? $query->where('id', $id)->find() : $query->where('diyname', $diyname)->find();
        if (!$page) {
            $this->fail('单页不存在', self::CODE_NOT_FOUND);
        }

        $this->success('', ['page' => $this->pick($page, $this->onlyExisting('cms_page', [
            'id','category_id','type','title','seotitle','keywords','description','flag','image','icon','content',
            'views','likes','comments','diyname','weigh','createtime','updatetime',
        ]))]);
    }
}
