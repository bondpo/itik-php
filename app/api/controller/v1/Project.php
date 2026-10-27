<?php

// +----------------------------------------------------------------------
// | API v1 —— 项目
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use app\admin\model\app\Project as ProjectModel;
use app\admin\model\app\ProjectCategory;
use app\admin\model\app\ProjectTimeline;
use think\facade\Db;

/**
 * 项目接口
 *
 * 路由：
 *   GET  /api/v1/projects/categories         项目赛道分类列表（支持树形层级与项目数统计）
 *   GET  /api/v1/projects                    项目列表（公开，支持 category_id 筛选，关联分类）
 *   GET  /api/v1/projects/detail             项目详情（关联分类与时间轴）
 *   POST /api/v1/projects                    创建项目（OWNER + 有效订阅，关联 category_id）
 *   POST /api/v1/projects/update             编辑项目（所属项目方/员工子权限）
 *   POST /api/v1/projects/submit             提交审核
 *   GET  /api/v1/projects/materials          项目资料列表
 *   POST /api/v1/projects/materials          上传项目资料（进入审核流）
 *   GET  /api/v1/projects/members            项目团队/社区长列表
 *   POST /api/v1/projects/members/bind       绑定社区长（001 号推荐官）
 *   GET  /api/v1/projects/stats              项目数据看板
 *   GET  /api/v1/projects/timelines          项目发展历程与里程碑时间轴
 *   POST /api/v1/projects/timelines          项目方发布里程碑节点
 *   POST /api/v1/projects/timelines/update   项目方编辑里程碑节点
 *   POST /api/v1/projects/timelines/delete   项目方删除里程碑节点
 */
class Project extends Base
{
    protected $noNeedLogin = ['index', 'detail', 'categories', 'timelines'];
    protected $noNeedRight = '*';

    /** 允许的资料分类与类型（白名单） */
    protected const MATERIAL_CATEGORIES = ['license', 'brand', 'project'];
    protected const MATERIAL_TYPES = ['license', 'address', 'business_link', 'video', 'ppt', 'doc', 'whitepaper', 'image', 'other'];

    /**
     * 项目分类列表（公开）
     * 支持树形层级 (tree=1, 默认) 或平铺列表 (tree=0)
     */
    public function categories()
    {
        $tree = (int)$this->request->param('tree', 1);
        $status = trim((string)$this->request->param('status', 'normal'));

        if ($tree === 1) {
            $list = ProjectCategory::getTree($status === 'normal');
        } else {
            $query = ProjectCategory::order('weigh', 'desc')->order('id', 'asc');
            if ($status !== '') {
                $query->where('status', $status);
            }
            $list = $query->select()->toArray();
        }

        // 统计各分类下的公开项目数
        $projectCountMap = Db::name('app_project')
            ->where('status', 'normal')
            ->where('category_id', '>', 0)
            ->group('category_id')
            ->column('count(*)', 'category_id');

        $this->attachProjectCounts($list, $projectCountMap);
        $list = $this->translations()->categories($list);

        $this->success('', [
            'list' => $list,
            'total' => count($list),
        ]);
    }

    /**
     * 递归填充分类下的项目数
     */
    protected function attachProjectCounts(array &$categories, array $countMap): int
    {
        $sum = 0;
        foreach ($categories as &$cat) {
            $catId = (int)$cat['id'];
            $directCount = (int)($countMap[$catId] ?? 0);
            $childrenCount = 0;
            if (!empty($cat['children']) && is_array($cat['children'])) {
                $childrenCount = $this->attachProjectCounts($cat['children'], $countMap);
            }
            $cat['project_count'] = $directCount + $childrenCount;
            $sum += $cat['project_count'];
        }
        unset($cat);
        return $sum;
    }

    /**
     * 项目列表（公开）
     * 仅返回审核通过且未下架的项目；支持按 category_id、关键词、地区等过滤，关联分类输出。
     */
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        [$orderby, $orderway] = $this->orderParams(['id', 'sort_weight', 'views', 'follows', 'create_time', 'publish_time'], 'sort_weight');

        $query = ProjectModel::where('status', 'normal');

        // 关联分类筛选（若为父分类，级联查询其子分类）
        $categoryId = (int)$this->request->param('category_id', 0);
        if ($categoryId > 0) {
            $subCategoryIds = ProjectCategory::where('pid', $categoryId)->column('id');
            $allCategoryIds = array_merge([$categoryId], $subCategoryIds);
            $query->whereIn('category_id', $allCategoryIds);
        }

        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $this->translations()->search($query, 'project', ['name', 'subtitle'], $keyword);
        }
        $region = (string)$this->request->param('region', '');
        if ($region !== '') {
            $query->where('region', $region);
        }
        $certified = $this->request->param('certified', '');
        if ($certified !== '') {
            $query->where('certified', (int)$certified);
        }

        $total = (clone $query)->count();
        $list = $query->with(['category' => function ($q) {
                $q->field('id,pid,name,slug,icon');
            }])
            ->order($orderby, $orderway)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->field('id,name,subtitle,cover,category_id,region,tags,certified,views,follows,sort_weight,create_time')
            ->select()
            ->toArray();

        // 列表仅暴露公开字段（不含 owner_user_id 等敏感信息）
        $this->success('', $this->pageResult($this->translations()->projects($list), $total, $page, $limit));
    }

    /**
     * 项目详情
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        if ($id <= 0) {
            $this->fail('缺少项目 ID', self::CODE_PARAM_MISSING);
        }
        $projectModel = ProjectModel::with(['category' => function ($q) {
            $q->field('id,pid,name,slug,icon,description');
        }])->where('id', $id)->find();

        if (!$projectModel) {
            $this->fail('项目不存在', self::CODE_NOT_FOUND);
        }
        $project = $projectModel->toArray();

        // 非公开项目仅作者/团队成员/管理员可见
        if ($project['status'] !== 'normal') {
            $uid = $this->userId();
            $isMember = $uid > 0 && (
                (int)$project['owner_user_id'] === $uid
                || Db::name('app_project_member')->where('project_id', $id)->where('user_id', $uid)->where('status', 'normal')->find()
            );
            if (!$isMember) {
                $this->fail('项目不存在或未公开', self::CODE_NOT_FOUND);
            }
        }

        // 浏览量自增
        Db::name('app_project')->where('id', $id)->inc('views')->update();

        $project['views'] = (int)$project['views'] + 1;
        $project['owner'] = Db::name('user')
            ->where('id', (int)$project['owner_user_id'])
            ->field('id,nickname,avatar')
            ->find() ?: null;
        $project['leaders'] = Db::name('app_project_member')
            ->where('project_id', $id)
            ->where('role', 'leader')
            ->where('status', 'normal')
            ->field('id,user_id,serial_no,title')
            ->order('serial_no', 'asc')
            ->select();
        $project['timelines'] = Db::name('app_project_timeline')
            ->where('project_id', $id)
            ->where('status', 'normal')
            ->order('event_time', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $project = $this->translations()->projects([$project])[0];
        $project['timelines'] = $this->translations()->rows('timeline', $project['timelines']);
        $this->success('', [
            'project'           => $project,
            'is_certified'      => (int)$project['certified'] === 1,
            'verify_status'     => $project['verify_status'],
        ]);
    }

    /**
     * 创建项目（项目方专属）
     */
    public function save()
    {
//        $this->requireRole(self::ROLE_OWNER); //后端不判断权限
//        $this->requireSubscription(true, '项目方订阅不可用，请先开通或续费');

        $data = $this->collectProjectInput(true);
        if ($data['name'] === '') {
            $this->fail('请填写项目名称', self::CODE_PARAM_MISSING);
        }

        $data['owner_user_id'] = $this->userId();
        $data['status']        = 'pending';   // 创建即进入待审核
        $data['verify_status'] = 'none';
        $data['certified']     = 0;
        $data['create_time']   = time();
        $data['update_time']   = time();

        $id = Db::name('app_project')->insertGetId($data);

        // 项目方自身写入成员表，便于统一权限判定
        Db::name('app_project_member')->insert([
            'project_id'  => $id,
            'user_id'     => $this->userId(),
            'role'        => 'owner',
            'perms'       => '',
            'status'      => 'normal',
            'start_at'    => time(),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        Db::name('app_content_review')->insert([
            'content_type' => 'project',
            'content_id'   => $id,
            'version'      => 1,
            'submitter_id' => $this->userId(),
            'result'       => 'pending',
            'submitted_at' => time(),
            'create_time'  => time(),
        ]);

        $this->success('项目已创建，等待审核', ['id' => $id, 'status' => 'pending']);
    }

    /**
     * 编辑项目
     */
    public function update()
    {
        $id = (int)$this->request->post('id', 0);
        $project = $this->findOwnedProject($id);

        $data = $this->collectProjectInput(false);
        if (!$data) {
            $this->fail('没有需要更新的字段', self::CODE_PARAM_MISSING);
        }

        // 已通过审核的项目修改后需重新审核
        if ($project['status'] === 'normal') {
            $data['status'] = 'pending';
        }
        $data['update_time'] = time();
        Db::name('app_project')->where('id', $id)->update($data);

        $this->success($project['status'] === 'normal' ? '已保存，修改内容将重新审核' : '已保存', $data);
    }

    /**
     * 提交审核
     */
    public function submit()
    {
        $id = (int)$this->request->post('id', 0);
        $project = $this->findOwnedProject($id);

        if (!in_array($project['status'], ['draft', 'rejected'], true)) {
            $this->fail('当前状态不可提交审核', self::CODE_STATE_INVALID, ['status' => $project['status']]);
        }

        $version = (int)Db::name('app_content_review')
            ->where('content_type', 'project')->where('content_id', $id)->count() + 1;

        Db::name('app_project')->where('id', $id)->update(['status' => 'pending', 'update_time' => time()]);
        Db::name('app_content_review')->insert([
            'content_type' => 'project',
            'content_id'   => $id,
            'version'      => $version,
            'submitter_id' => $this->userId(),
            'result'       => 'pending',
            'submitted_at' => time(),
            'create_time'  => time(),
        ]);

        $this->success('已提交审核', ['version' => $version]);
    }

    /**
     * 项目资料列表
     */
    public function materials()
    {
        $projectId = (int)$this->request->param('project_id', 0);
        $project = $this->findOwnedProject($projectId, false);

        $list = Db::name('app_project_material')
            ->where('project_id', $projectId)
            ->field('id,category,type,title,storage_key,attachment_id,ext,filesize,version,source,copyright_owner,review_status,review_reason,is_public,weigh,create_time')
            ->order('id', 'desc')
            ->select();

        $this->success('', ['list' => $this->translations()->rows('material', $list), 'project' => $this->translations()->row('project', ['id' => $project['id'], 'name' => $project['name']])]);
    }

    /**
     * 新增项目资料（进入审核流）
     */
    public function addMaterial()
    {
        $projectId = (int)$this->request->post('project_id', 0);
        $project = $this->findOwnedProject($projectId);

        $category = (string)$this->request->post('category', 'project');
        $type     = (string)$this->request->post('type', '');
        $title    = trim((string)$this->request->post('title', ''));
        $path     = (string)$this->request->post('storage_key', '');
        $source   = (string)$this->request->post('source', '');
        $copyright = (string)$this->request->post('copyright_owner', '');

        if (!in_array($category, self::MATERIAL_CATEGORIES, true)) {
            $this->fail('资料分类不合法', self::CODE_PARAM_INVALID);
        }
        if (!in_array($type, self::MATERIAL_TYPES, true)) {
            $this->fail('资料类型不合法', self::CODE_PARAM_INVALID);
        }
        if ($path === '') {
            $this->fail('请先上传文件（storage_key 必填）', self::CODE_PARAM_MISSING);
        }

        // 社区长上传内容需标记来源与版权责任（需求 §3.3）
        if ($this->role === self::ROLE_LEADER && ($source === '' || $copyright === '')) {
            $this->fail('社区长上传资料需填写来源与版权责任方', self::CODE_PARAM_MISSING);
        }

        $version = (int)Db::name('app_project_material')
            ->where('project_id', $projectId)->where('type', $type)->count() + 1;

        $id = Db::name('app_project_material')->insertGetId([
            'project_id'      => $projectId,
            'uploader_id'     => $this->userId(),
            'category'        => $category,
            'type'            => $type,
            'title'           => $title,
            'storage_key'     => $path,
            'ext'             => pathinfo($path, PATHINFO_EXTENSION),
            'version'         => $version,
            'source'          => $source,
            'copyright_owner' => $copyright,
            'review_status'   => 'pending',
            'is_public'       => 1,
            'create_time'     => time(),
            'update_time'     => time(),
        ]);

        $this->success('资料已上传，等待审核', ['id' => $id, 'version' => $version]);
    }

    /**
     * 项目团队 / 社区长列表
     */
    public function members()
    {
        $projectId = (int)$this->request->param('project_id', 0);
        $this->findOwnedProject($projectId, false);

        $list = Db::name('app_project_member')
            ->alias('m')
            ->join('user u', 'u.id = m.user_id')
            ->where('m.project_id', $projectId)
            ->field('m.id,m.user_id,m.role,m.serial_no,m.title,m.status,m.start_at,m.end_at,u.nickname,u.avatar')
            ->order('m.role', 'asc')
            ->order('m.serial_no', 'asc')
            ->select();

        $this->success('', ['list' => $list]);
    }

    /**
     * 绑定社区长（001 号推荐官体系）
     * 编号可在项目内自增生成，也可由后台指定，此处默认按已有最大编号顺延。
     */
    public function bindLeader()
    {
        $projectId = (int)$this->request->post('project_id', 0);
        $userId    = (int)$this->request->post('user_id', 0);
        $serialNo  = trim((string)$this->request->post('serial_no', ''));
        $title     = trim((string)$this->request->post('title', ''));
        $this->findOwnedProject($projectId);

        if ($userId <= 0) {
            $this->fail('请选择要绑定的用户', self::CODE_PARAM_MISSING);
        }
        if (!Db::name('user')->where('id', $userId)->find()) {
            $this->fail('用户不存在', self::CODE_NOT_FOUND);
        }

        $exists = Db::name('app_project_member')->where('project_id', $projectId)->where('user_id', $userId)->find();
        if ($exists && $exists['role'] === 'leader') {
            $this->fail('该用户已是本项目的社区长', self::CODE_DUPLICATE);
        }

        if ($serialNo === '') {
            $max = (int)Db::name('app_project_member')->where('project_id', $projectId)->where('role', 'leader')->max('serial_no');
            $serialNo = sprintf('%03d', $max + 1);
        } elseif (!preg_match('/^[0-9]{1,6}$/', $serialNo)) {
            $this->fail('推荐官编号需为数字', self::CODE_PARAM_INVALID);
        }
        if (Db::name('app_project_member')->where('project_id', $projectId)->where('serial_no', $serialNo)->find()) {
            $this->fail('该编号已被占用', self::CODE_DUPLICATE);
        }

        $now = time();
        if ($exists) {
            Db::name('app_project_member')->where('id', $exists['id'])->update([
                'role' => 'leader', 'serial_no' => $serialNo, 'title' => $title,
                'status' => 'pending', 'update_time' => $now,
            ]);
            $mid = (int)$exists['id'];
        } else {
            $mid = (int)Db::name('app_project_member')->insertGetId([
                'project_id' => $projectId, 'user_id' => $userId, 'role' => 'leader',
                'serial_no' => $serialNo, 'title' => $title, 'status' => 'pending',
                'start_at' => $now, 'create_time' => $now, 'update_time' => $now,
            ]);
        }

        $this->success('已提交绑定申请，等待审核', ['id' => $mid, 'serial_no' => $serialNo, 'status' => 'pending']);
    }

    /**
     * 项目数据看板
     */
    public function stats()
    {
        $projectId = (int)$this->request->param('project_id', 0);
        $project = $this->findOwnedProject($projectId, false);

        $this->success('', [
            'project'    => ['id' => $project['id'], 'name' => $project['name'], 'status' => $project['status'], 'certified' => (int)$project['certified']],
            'views'      => (int)$project['views'],
            'follows'    => (int)$project['follows'],
            'favorites'  => (int)Db::name('app_favorite')->where('target_type', 'project')->where('target_id', $projectId)->count(),
            'materials'  => (int)Db::name('app_project_material')->where('project_id', $projectId)->count(),
            'pending_materials' => (int)Db::name('app_project_material')->where('project_id', $projectId)->where('review_status', 'pending')->count(),
            'leaders'    => (int)Db::name('app_project_member')->where('project_id', $projectId)->where('role', 'leader')->count(),
            'contents'   => (int)Db::name('app_content')->where('project_id', $projectId)->count(),
            'timelines'  => (int)Db::name('app_project_timeline')->where('project_id', $projectId)->count(),
        ]);
    }

    /**
     * 查询项目时间轴里程碑列表（公开）
     */
    public function timelines()
    {
        $projectId = (int)$this->request->param('project_id', 0);
        if ($projectId <= 0) {
            $this->fail('缺少项目 ID', self::CODE_PARAM_MISSING);
        }

        $list = Db::name('app_project_timeline')
            ->where('project_id', $projectId)
            ->where('status', 'normal')
            ->order('event_time', 'desc')
            ->order('weigh', 'desc')
            ->order('id', 'desc')
            ->select();

        $items = [];
        foreach ($list as $item) {
            $item['event_date'] = $item['event_time'] > 0 ? date('Y-m-d', $item['event_time']) : '';
            $items[] = $item;
        }

        $this->success('', [
            'project_id' => $projectId,
            'list'       => $this->translations()->rows('timeline', $items),
            'total'      => count($items),
        ]);
    }

    /**
     * 项目方发布发展时间轴里程碑
     */
    public function addTimeline()
    {
        $projectId = (int)$this->request->post('project_id', 0);
        $project = $this->findOwnedProject($projectId, false);

        $title = trim((string)$this->request->post('title', ''));
        if ($title === '') {
            $this->fail('请填写里程碑事件标题', self::CODE_PARAM_MISSING);
        }

        $eventTimeStr = $this->request->post('event_time', '');
        if ($eventTimeStr !== '' && !is_numeric($eventTimeStr)) {
            $eventTime = strtotime($eventTimeStr) ?: time();
        } elseif (is_numeric($eventTimeStr) && (int)$eventTimeStr > 0) {
            $eventTime = (int)$eventTimeStr;
        } else {
            $eventTime = time();
        }

        $tag = trim((string)$this->request->post('tag', '里程碑'));
        $summary = trim((string)$this->request->post('summary', ''));
        $content = (string)$this->request->post('content', '');
        $linkUrl = trim((string)$this->request->post('link_url', ''));
        $weigh = (int)$this->request->post('weigh', 0);

        $now = time();
        $id = Db::name('app_project_timeline')->insertGetId([
            'project_id'  => $projectId,
            'user_id'     => $this->userId(),
            'title'       => $title,
            'tag'         => $tag ?: '里程碑',
            'event_time'  => $eventTime,
            'summary'     => $summary,
            'content'     => $content,
            'link_url'    => $linkUrl,
            'weigh'       => $weigh,
            'status'      => 'normal',
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $this->success('里程碑节点发布成功', [
            'id'         => $id,
            'project_id' => $projectId,
            'title'      => $title,
            'event_date' => date('Y-m-d', $eventTime),
        ]);
    }

    /**
     * 项目方编辑时间轴节点
     */
    public function updateTimeline()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->fail('缺少时间轴 ID', self::CODE_PARAM_MISSING);
        }

        $timeline = Db::name('app_project_timeline')->where('id', $id)->find();
        if (!$timeline) {
            $this->fail('时间轴记录不存在', self::CODE_NOT_FOUND);
        }

        // 权限校验
        $this->findOwnedProject((int)$timeline['project_id'], false);

        $data = [];
        foreach (['title', 'tag', 'summary', 'content', 'link_url'] as $field) {
            $val = $this->request->post($field, null);
            if ($val !== null) {
                $data[$field] = trim((string)$val);
            }
        }
        $eventTimeStr = $this->request->post('event_time', null);
        if ($eventTimeStr !== null) {
            $data['event_time'] = !is_numeric($eventTimeStr) ? (strtotime($eventTimeStr) ?: time()) : (int)$eventTimeStr;
        }
        $weigh = $this->request->post('weigh', null);
        if ($weigh !== null) {
            $data['weigh'] = (int)$weigh;
        }

        if (empty($data)) {
            $this->fail('没有需要更新的字段', self::CODE_PARAM_MISSING);
        }
        $data['update_time'] = time();

        Db::name('app_project_timeline')->where('id', $id)->update($data);
        $this->success('时间轴节点更新成功', ['id' => $id]);
    }

    /**
     * 项目方删除时间轴节点
     */
    public function deleteTimeline()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->fail('缺少时间轴 ID', self::CODE_PARAM_MISSING);
        }

        $timeline = Db::name('app_project_timeline')->where('id', $id)->find();
        if (!$timeline) {
            $this->fail('时间轴记录不存在', self::CODE_NOT_FOUND);
        }

        // 权限校验
        $this->findOwnedProject((int)$timeline['project_id'], false);

        Db::name('app_project_timeline')->where('id', $id)->delete();
        $this->success('时间轴节点已删除');
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 收集项目输入（白名单）
     */
    protected function collectProjectInput(bool $isCreate): array
    {
        $fields = [
            'name', 'subtitle', 'cover',
            'region', 'tags', 'summary', 'content', 'seo_title', 'seo_keywords', 'seo_description',
        ];
        $data = [];
        foreach ($fields as $f) {
            $v = $isCreate ? $this->request->post($f, null) : $this->request->post($f, null);
            if ($v !== null) {
                $data[$f] = $isCreate ? (string)$v : (string)$v;
            }
        }
        $categoryId = $this->request->post('category_id', null);
        if ($categoryId !== null) {
            $catId = (int)$categoryId;
            if ($catId > 0) {
                $category = ProjectCategory::where('id', $catId)->where('status', 'normal')->find();
                if (!$category) {
                    $this->fail('所选项目分类不存在或已禁用', self::CODE_PARAM_INVALID);
                }
                $data['category_id'] = $catId;
            } else {
                $data['category_id'] = 0;
            }
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if (mb_strlen($data['name']) > 150) {
                $this->fail('项目名称过长（最多 150 字）', self::CODE_PARAM_INVALID);
            }
        }
        return $data;
    }

    /**
     * 查找属于当前用户的项目（含团队成员校验）
     * @param int  $id
     * @param bool $mustOwned 是否必须为项目方本人（否则团队成员亦可）
     * @return array
     */
    protected function findOwnedProject(int $id, bool $mustOwned = true): array
    {
        if ($id <= 0) {
            $this->fail('缺少项目 ID', self::CODE_PARAM_MISSING);
        }
        $project = Db::name('app_project')->where('id', $id)->find();
        if (!$project) {
            $this->fail('项目不存在', self::CODE_NOT_FOUND);
        }
        $uid = $this->userId();
        if ($uid <= 0) {
            $this->fail('请先登录', self::CODE_UNAUTHORIZED);
        }

        $isOwner = (int)$project['owner_user_id'] === $uid;
        if ($mustOwned && !$isOwner) {
            $this->fail('仅项目方可执行该操作', self::CODE_FORBIDDEN);
        }
        if (!$isOwner) {
            $member = Db::name('app_project_member')
                ->where('project_id', $id)->where('user_id', $uid)->where('status', 'normal')->find();
            if (!$member) {
                $this->fail('你不是该项目成员', self::CODE_FORBIDDEN);
            }
        }
        return $project;
    }
}
