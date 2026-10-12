<?php

// +----------------------------------------------------------------------
// | API v1 —— 内容 / 资讯
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;

/**
 * 内容接口
 *
 * 路由：
 *   GET  /api/v1/contents                 内容列表（公开，仅 status=normal）
 *   GET  /api/v1/contents/detail          内容详情
 *   GET  /api/v1/contents/mine            我的内容（含草稿/驳回）
 *   POST /api/v1/contents                 发布内容（按角色 + 订阅）
 *   POST /api/v1/contents/update          编辑（已发布内容修改后重新审核）
 *   POST /api/v1/contents/submit          提交审核
 *   POST /api/v1/contents/delete          删除自己的内容（软删除）
 */
class Content extends Base
{
    protected $noNeedLogin = ['index', 'detail'];
    protected $noNeedRight = '*';

    /** 内容类型白名单 */
    protected const TYPES = ['news', 'material', 'member_upload'];
    /** 可见性白名单 */
    protected const VISIBILITIES = ['public', 'member', 'relation'];

    /**
     * 内容列表（公开）
     */
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        [$orderby, $orderway] = $this->orderParams(['id', 'publishetime', 'published_at', 'views', 'sort_weight'], 'published_at');

        $query = Db::name('app_content')->where('status', 'normal')->where('visibility', 'public');

        $type = (string)$this->request->param('type', '');
        if ($type !== '') {
            if (!in_array($type, self::TYPES, true)) {
                $this->fail('内容类型不合法', self::CODE_PARAM_INVALID);
            }
            $query->where('type', $type);
        }
        $projectId = (int)$this->request->param('project_id', 0);
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }
        foreach (['category_l1', 'category_l2'] as $f) {
            $v = (string)$this->request->param($f, '');
            if ($v !== '') {
                $query->where($f, $v);
            }
        }
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', "%{$keyword}%")->whereOr('summary', 'like', "%{$keyword}%");
            });
        }
        $tag = trim((string)$this->request->param('tag', ''));
        if ($tag !== '') {
            $query->whereLike('tags', "%{$tag}%");
        }

        $total = (clone $query)->count();
        $list = $query->order($orderby, $orderway)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->field('id,author_id,project_id,type,title,cover,summary,tags,category_l1,category_l2,views,published_at,create_time')
            ->select();

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 内容详情
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        if ($id <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }
        $content = Db::name('app_content')->where('id', $id)->find();
        if (!$content) {
            $this->fail('内容不存在', self::CODE_NOT_FOUND);
        }

        // 非公开内容仅作者与项目成员可见
        if ($content['status'] !== 'normal' || $content['visibility'] !== 'public') {
            $uid = $this->userId();
            $canView = false;
            if ($uid > 0) {
                if ((int)$content['author_id'] === $uid) {
                    $canView = true;
                } elseif ((int)$content['project_id'] > 0) {
                    $canView = (bool)Db::name('app_project_member')
                        ->where('project_id', (int)$content['project_id'])
                        ->where('user_id', $uid)
                        ->where('status', 'normal')
                        ->find();
                }
            }
            if (!$canView) {
                $this->fail('内容不存在或未公开', self::CODE_NOT_FOUND);
            }
        } else {
            Db::name('app_content')->where('id', $id)->inc('views')->update();
            $content['views'] = (int)$content['views'] + 1;
        }

        $content['author'] = Db::name('user')
            ->where('id', (int)$content['author_id'])
            ->field('id,nickname,avatar')
            ->find() ?: null;

        $this->success('', ['content' => $content]);
    }

    /**
     * 我的内容（含草稿与驳回）
     */
    public function mine()
    {
        [$page, $limit] = $this->pageParams();

        $query = Db::name('app_content')->where('author_id', $this->userId());
        $status = (string)$this->request->param('status', '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')
            ->page($page, $limit)
            ->field('id,project_id,type,title,cover,summary,status,visibility,version,views,published_at,create_time')
            ->select();

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 发布内容
     */
    public function save()
    {
        // 发布权限：项目方/社区长需有效订阅；会员可发布「参与/推荐」类内容
        if (in_array($this->role, [self::ROLE_OWNER, self::ROLE_LEADER], true)) {
            $this->requireSubscription(true, '订阅不可用，请先开通或续费');
        } elseif ($this->role === self::ROLE_VISITOR) {
            $this->fail('请先开通会员后再发布内容', self::CODE_ROLE_DENIED);
        }

        $data = $this->collectInput();
        if ($data['title'] === '') {
            $this->fail('请填写内容标题', self::CODE_PARAM_MISSING);
        }
        // 会员发布的内容类型固定为会员上传
        if ($this->role === self::ROLE_MEMBER && ($data['type'] ?? '') === '') {
            $data['type'] = 'member_upload';
        }

        // 若关联项目，必须是有效项目且当前用户有权限
        if (!empty($data['project_id'])) {
            $pid = (int)$data['project_id'];
            $project = Db::name('app_project')->where('id', $pid)->find();
            if (!$project) {
                $this->fail('关联项目不存在', self::CODE_NOT_FOUND);
            }
            $isOwner = (int)$project['owner_user_id'] === $this->userId();
            $isMember = (bool)Db::name('app_project_member')
                ->where('project_id', $pid)->where('user_id', $this->userId())->where('status', 'normal')->find();
            if (!$isOwner && !$isMember) {
                $this->fail('无权关联该项目', self::CODE_FORBIDDEN);
            }
        }

        $now = time();
        $isDraft = (bool)$this->request->post('is_draft', false);
        $data['author_id']    = $this->userId();
        $data['status']       = $isDraft ? 'draft' : 'pending';  // 支持草稿或立即审核
        $data['version']      = 1;
        $data['create_time']  = $now;
        $data['update_time']  = $now;

        $id = Db::name('app_content')->insertGetId($data);

        Db::name('app_content_review')->insert([
            'content_type' => 'content',
            'content_id'   => $id,
            'version'      => 1,
            'submitter_id' => $this->userId(),
            'result'       => 'pending',
            'submitted_at' => $now,
            'create_time'  => $now,
        ]);

        $this->success('内容已提交，等待审核', ['id' => $id, 'status' => 'pending']);
    }

    /**
     * 编辑内容
     */
    public function update()
    {
        $id = (int)$this->request->post('id', 0);
        $content = $this->findOwnContent($id);

        $data = $this->collectInput(false);
        if (!$data) {
            $this->fail('没有需要更新的字段', self::CODE_PARAM_MISSING);
        }

        $needReview = $content['status'] === 'normal';
        if ($needReview) {
            $data['status']  = 'pending';
            $data['version'] = (int)$content['version'] + 1;
        }
        $data['update_time'] = time();
        Db::name('app_content')->where('id', $id)->update($data);

        if ($needReview) {
            Db::name('app_content_review')->insert([
                'content_type' => 'content',
                'content_id'   => $id,
                'version'      => (int)$data['version'],
                'submitter_id' => $this->userId(),
                'result'       => 'pending',
                'submitted_at' => time(),
                'create_time'  => time(),
            ]);
        }

        $this->success($needReview ? '已保存，修改内容将重新审核' : '已保存', $data);
    }

    /**
     * 提交审核（草稿/驳回 → 待审核）
     */
    public function submit()
    {
        $id = (int)$this->request->post('id', 0);
        $content = $this->findOwnContent($id);

        if (!in_array($content['status'], ['draft', 'rejected'], true)) {
            $this->fail('当前状态不可提交审核', self::CODE_STATE_INVALID, ['status' => $content['status']]);
        }

        $version = (int)Db::name('app_content_review')
            ->where('content_type', 'content')->where('content_id', $id)->count() + 1;

        Db::name('app_content')->where('id', $id)->update([
            'status' => 'pending', 'version' => $version, 'update_time' => time(),
        ]);
        Db::name('app_content_review')->insert([
            'content_type' => 'content',
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
     * 删除内容（软删除，保留审核与审计痕迹）
     */
    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        $this->findOwnContent($id);

        Db::name('app_content')->where('id', $id)->update([
            'deletetime'  => time(),
            'update_time' => time(),
        ]);

        $this->success('已删除');
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 收集内容输入（白名单）
     */
    protected function collectInput(bool $isCreate = true): array
    {
        $fields = ['title', 'cover', 'summary', 'content', 'tags', 'category_l1', 'category_l2', 'type', 'visibility', 'project_id'];
        $data = [];
        foreach ($fields as $f) {
            $v = $this->request->post($f, null);
            if ($v === null) {
                continue;
            }
            if (in_array($f, ['type', 'visibility'], true)) {
                $v = (string)$v;
                if ($v !== '') {
                    $allowed = $f === 'type' ? self::TYPES : self::VISIBILITIES;
                    if (!in_array($v, $allowed, true)) {
                        $this->fail(($f === 'type' ? '内容类型' : '可见性') . '不合法', self::CODE_PARAM_INVALID);
                    }
                }
                if ($v === '') {
                    continue;
                }
            }
            $data[$f] = is_string($v) ? trim($v) : $v;
        }

        if (isset($data['title'])) {
            if ($data['title'] === '') {
                $this->fail('标题不能为空', self::CODE_PARAM_INVALID);
            }
            if (mb_strlen($data['title']) > 200) {
                $this->fail('标题过长（最多 200 字）', self::CODE_PARAM_INVALID);
            }
        }
        if (isset($data['summary']) && mb_strlen($data['summary']) > 500) {
            $this->fail('摘要过长（最多 500 字）', self::CODE_PARAM_INVALID);
        }
        return $data;
    }

    /**
     * 查找当前用户自己的内容
     */
    protected function findOwnContent(int $id): array
    {
        if ($id <= 0) {
            $this->fail('缺少内容 ID', self::CODE_PARAM_MISSING);
        }
        $content = Db::name('app_content')->where('id', $id)->whereNull('deletetime')->find();
        if (!$content) {
            $this->fail('内容不存在', self::CODE_NOT_FOUND);
        }
        if ((int)$content['author_id'] !== $this->userId()) {
            $this->fail('仅作者本人可操作该内容', self::CODE_FORBIDDEN);
        }
        return $content;
    }
}
