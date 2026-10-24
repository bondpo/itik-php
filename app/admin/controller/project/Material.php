<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\project;

use app\common\controller\Backend;
use think\facade\Db;

class Material extends Backend
{
    protected $model = null;
    protected $modelValidate = false;
    protected $noNeedRight = ['selectpage', 'detail'];

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\app\ProjectMaterial();
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            [$where, $sort, $order, $offset, $limit] = $this->buildparams();
            $list = $this->model
                ->with(['project'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $this->result('ok', $list->items(), $list->total());
        }
        $this->view->assign('reviewList', [
            'pending'  => '待审核',
            'normal'   => '已通过',
            'rejected' => '已驳回',
        ]);

        return $this->view->fetch();
    }

    /**
     * 查看资料详情 (弹窗页面)
     */
    public function detail()
    {
        $id = (int)($this->request->param('ids') ?: $this->request->param('id'));
        $row = $this->model
            ->with(['project'])
            ->where('id', $id)
            ->find();

        if (!$row) {
            $this->error('资料不存在');
        }

        // 上传人
        $uploader = null;
        if (!empty($row['uploader_id'])) {
            $uploader = Db::name('user')->where('id', $row['uploader_id'])->field('id,username,nickname,mobile,avatar,email')->find();
        }

        // 完整文件访问路径
        $fileUrl = '';
        if (!empty($row['storage_key'])) {
            $fileUrl = function_exists('cdnurl') ? cdnurl($row['storage_key'], true) : $row['storage_key'];
        }

        // 格式化文件大小
        $filesizeText = '';
        if (!empty($row['filesize'])) {
            $size = (int)$row['filesize'];
            if ($size >= 1048576) {
                $filesizeText = round($size / 1048576, 2) . ' MB';
            } elseif ($size >= 1024) {
                $filesizeText = round($size / 1024, 2) . ' KB';
            } else {
                $filesizeText = $size . ' B';
            }
        }

        $categoryMap = [
            'license' => '资质',
            'brand'   => '品牌',
            'project' => '项目',
            'quality' => '质量',
            'other'   => '其它',
        ];

        $typeMap = [
            'news'       => '资讯',
            'case'       => '案例',
            'activity'   => '活动',
            'video'      => '视频',
            'ppt'        => 'PPT',
            'doc'        => '文档',
            'whitepaper' => '白皮书',
            'license'    => '营业执照/资质',
            'address'    => '工商地址/实地',
            'image'      => '图片素材',
        ];

        $this->view->assign('row', $row);
        $this->view->assign('uploader', $uploader);
        $this->view->assign('fileUrl', $fileUrl);
        $this->view->assign('filesizeText', $filesizeText);
        $this->view->assign('categoryTitle', $categoryMap[$row['category']] ?? $row['category']);
        $this->view->assign('typeTitle', $typeMap[$row['type']] ?? $row['type']);

        return $this->view->fetch();
    }
}
