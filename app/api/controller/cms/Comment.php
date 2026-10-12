<?php

// +----------------------------------------------------------------------
// | CMS API —— 评论（留言式，含嵌套回复）
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 评论接口
 *
 * 路由：
 *   GET  /api/cms/comments           评论列表（按内容/类型，树形可选）
 *   POST /api/cms/comments           发表评论（支持游客开关 + 审核配置）
 *   POST /api/cms/comments/delete    删除自己的评论（软删除）
 *   POST /api/cms/comments/subscribe 取消/订阅回复通知
 */
class Comment extends Base
{
    protected $noNeedLogin = ['index', 'post'];
    protected $noNeedRight = '*';

    /**
     * 评论列表
     */
    public function index()
    {
        $type = (string)$this->request->param('type', 'archives');
        $aid  = (int)$this->request->param('aid', 0);
        if ($aid <= 0) {
            $this->fail('缺少目标 ID（aid）', self::CODE_PARAM_MISSING);
        }

        [$page, $limit] = $this->cmsPageParams();
        $pid = (int)$this->request->param('pid', -1);

        $query = Db::name('cms_comment')
            ->where('type', $type)->where('aid', $aid)
            ->where('status', 'normal')->whereNull('deletetime');
        if ($pid >= 0) {
            $query->where('pid', $pid);
        }

        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)
            ->field($this->fieldString('cms_comment', ['id','user_id','type','aid','pid','content','comments','subscribe','createtime']))
            ->select()->toArray();

        $list = $this->decorate($rows, (int)$this->request->param('tree', 0) === 1);

        $this->success('', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * 发表评论
     */
    public function post()
    {
        $type    = (string)$this->request->post('type', 'archives');
        $aid     = (int)$this->request->post('aid', 0);
        $pid     = (int)$this->request->post('pid', 0);
        $content = trim((string)$this->request->post('content', ''));

        if ($aid <= 0) {
            $this->fail('缺少目标 ID（aid）', self::CODE_PARAM_MISSING);
        }
        if ($content === '') {
            $this->fail('评论内容不能为空', self::CODE_PARAM_MISSING);
        }
        if (mb_strlen($content) > 500) {
            $this->fail('评论内容过长（最多 500 字）', self::CODE_PARAM_INVALID);
        }
        if (!in_array($type, ['archives', 'page', 'special'], true)) {
            $this->fail('评论类型不合法', self::CODE_PARAM_INVALID);
        }

        // 内容存在性
        $tableMap = ['archives' => 'cms_archives', 'page' => 'cms_page', 'special' => 'cms_special'];
        if (!Db::name($tableMap[$type])->where('id', $aid)->find()) {
            $this->fail('评论对象不存在', self::CODE_NOT_FOUND);
        }

        $userId = ($this->auth && $this->auth->isLogin()) ? (int)$this->auth->id : 0;

        // 游客评论开关（表单配置 isguest；评论场景由 cms.comment_guest 控制，缺省允许）
        if ($userId === 0 && (int)$this->cmsConfig('comment_guest', 1) !== 1) {
            $this->fail('请登录后再评论', self::CODE_UNAUTHORIZED);
        }

        // 评论间隔限制（防刷）
        $interval = (int)$this->cmsConfig('comment_interval', 0);
        if ($interval > 0) {
            $last = Db::name('cms_comment')->where('user_id', $userId)->where('ip', $this->request->ip())
                ->order('id', 'desc')->find();
            if ($last && time() - (int)$last['createtime'] < $interval) {
                $this->fail("评论过于频繁，请 {$interval} 秒后再试", self::CODE_RATE_LIMITED);
            }
        }

        // 审核状态
        $needAudit = (int)$this->cmsConfig('comment_audit', 1) === 1;
        $status = $needAudit ? 'hidden' : 'normal';

        $id = (int)Db::name('cms_comment')->insertGetId([
            'user_id'    => $userId,
            'type'       => $type,
            'aid'        => $aid,
            'pid'        => $pid,
            'content'    => $content,
            'comments'   => 0,
            'ip'         => $this->request->ip(),
            'useragent'  => mb_substr((string)$this->request->header('user-agent', ''), 0, 255),
            'subscribe'  => 0,
            'createtime' => time(),
            'updatetime' => time(),
            'status'     => $status,
        ]);

        if ($status === 'normal') {
            Db::name($tableMap[$type])->where('id', $aid)->inc('comments')->update();
            if ($pid > 0) {
                Db::name('cms_comment')->where('id', $pid)->inc('comments')->update();
            }
        }

        $this->success($needAudit ? '评论已提交，等待审核' : '评论成功', [
            'id'     => $id,
            'status' => $status,
        ]);
    }

    /**
     * 删除自己的评论（软删除）
     */
    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        $userId = $this->requireLogin();
        if ($id <= 0) {
            $this->fail('缺少评论 ID', self::CODE_PARAM_MISSING);
        }
        $row = Db::name('cms_comment')->where('id', $id)->whereNull('deletetime')->find();
        if (!$row) {
            $this->fail('评论不存在', self::CODE_NOT_FOUND);
        }
        if ((int)$row['user_id'] !== $userId) {
            $this->fail('仅可删除自己的评论', self::CODE_FORBIDDEN);
        }

        Db::name('cms_comment')->where('id', $id)->update(['deletetime' => time(), 'updatetime' => time()]);
        $this->success('已删除');
    }

    /**
     * 订阅/取消订阅回复通知
     */
    public function subscribe()
    {
        $id = (int)$this->request->post('id', 0);
        $flag = (int)$this->request->post('subscribe', 1);
        $userId = $this->requireLogin();
        if ($id <= 0) {
            $this->fail('缺少评论 ID', self::CODE_PARAM_MISSING);
        }
        $row = Db::name('cms_comment')->where('id', $id)->find();
        if (!$row) {
            $this->fail('评论不存在', self::CODE_NOT_FOUND);
        }
        if ((int)$row['user_id'] !== $userId) {
            $this->fail('无权操作', self::CODE_FORBIDDEN);
        }
        Db::name('cms_comment')->where('id', $id)->update(['subscribe' => $flag ? 1 : 0, 'updatetime' => time()]);
        $this->success($flag ? '已订阅回复通知' : '已取消订阅');
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 补充评论人信息，可选组装为树
     */
    protected function decorate(array $rows, bool $tree = false): array
    {
        if (!$rows) {
            return [];
        }
        $uids = array_filter(array_unique(array_column($rows, 'user_id')));
        $users = $uids
            ? array_column(Db::name('user')->whereIn('id', $uids)->field('id,nickname,avatar')->select()->toArray(), null, 'id')
            : [];

        foreach ($rows as $k => $r) {
            $uid = (int)$r['user_id'];
            $rows[$k]['nickname'] = $users[$uid]['nickname'] ?? ($uid === 0 ? '游客' : '用户');
            $rows[$k]['avatar']   = $users[$uid]['avatar'] ?? '';
            $rows[$k]['children'] = [];
        }

        if (!$tree) {
            return $rows;
        }
        // 组装两级树
        $byId = array_column($rows, null, 'id');
        $result = [];
        foreach ($rows as $r) {
            $pid = (int)$r['pid'];
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['children'][] = $r;
            } else {
                $result[] = $r;
            }
        }
        return $result;
    }
}
