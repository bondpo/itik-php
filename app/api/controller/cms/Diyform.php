<?php

// +----------------------------------------------------------------------
// | CMS API —— 自定义表单
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 自定义表单接口
 *
 * 路由：
 *   GET  /api/cms/diyform          表单定义（字段/校验规则/是否需要登录与验证码）
 *   GET  /api/cms/diyform/data     表单提交数据列表
 *   POST /api/cms/diyform/post     提交表单（支持游客开关与验证码）
 */
class Diyform extends Base
{
    protected $noNeedLogin = ['index', 'data', 'post'];
    protected $noNeedRight = '*';

    /**
     * 表单定义（前端据此渲染表单）
     */
    public function index()
    {
        $diyname = trim((string)$this->request->param('diyname', ''));
        $id = (int)$this->request->param('id', 0);
        if ($diyname === '' && $id <= 0) {
            $this->fail('缺少表单标识（id 或 diyname）', self::CODE_PARAM_MISSING);
        }

        $query = Db::name('cms_diyform')->where('status', 'normal');
        $form = $id > 0 ? $query->where('id', $id)->find() : $query->where('diyname', $diyname)->find();
        if (!$form) {
            $this->fail('表单不存在', self::CODE_NOT_FOUND);
        }

        // 字段定义（含校验规则，供前端即时校验）
        $fields = Db::name('cms_fields')
            ->where('source', 'diyform')->where('source_id', (int)$form['id'])
            ->where('status', 'normal')->order('weigh', 'desc')
            ->field($this->fieldString('cms_fields', ['id','name','title','type','content','defaultvalue','rule','msg','ok','tip','decimals','length','minimum','maximum','isrequired','isextend','weigh']))
            ->select()->toArray();

        $this->success('', [
            'form' => $this->pick($form, $this->onlyExisting('cms_diyform', [
                'id','name','title','seotitle','posttitle','keywords','description','diyname','table',
                'isguest','needlogin','isedit','iscaptcha','successtips','redirecturl','usermode','statusmode','setting',
            ])),
            'fields'      => $fields,
            'need_login'  => (int)$form['needlogin'] === 1,
            'need_captcha' => (int)$form['iscaptcha'] === 1,
            'allow_guest' => (int)$form['isguest'] === 1,
            'success_tips' => (string)($form['successtips'] ?? '提交成功'),
            'redirect_url' => (string)($form['redirecturl'] ?? ''),
        ]);
    }

    /**
     * 表单数据列表（仅登录用户可取自己的数据）
     */
    public function data()
    {
        $diyname = trim((string)$this->request->param('diyname', ''));
        $id = (int)$this->request->param('id', 0);
        $userId = $this->requireLogin();

        $query = Db::name('cms_diyform')->where('status', 'normal');
        $form = $id > 0 ? $query->where('id', $id)->find() : $query->where('diyname', $diyname)->find();
        if (!$form) {
            $this->fail('表单不存在', self::CODE_NOT_FOUND);
        }
        if (empty($form['table'])) {
            $this->fail('表单数据表未配置', self::CODE_BIZ_FAIL);
        }

        [$page, $limit] = $this->cmsPageParams();
        $table = $form['table'];
        $query = Db::name($table)->where('status', 'normal');
        // usermode=1 表示仅本人可见
        if ((int)$form['usermode'] === 1) {
            $query->where('user_id', $userId);
        }

        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        $this->success('', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * 提交表单
     */
    public function post()
    {
        $diyname = trim((string)$this->request->post('diyname', ''));
        $id = (int)$this->request->post('id', 0);
        if ($diyname === '' && $id <= 0) {
            $this->fail('缺少表单标识（id 或 diyname）', self::CODE_PARAM_MISSING);
        }

        $query = Db::name('cms_diyform')->where('status', 'normal');
        $form = $id > 0 ? $query->where('id', $id)->find() : $query->where('diyname', $diyname)->find();
        if (!$form) {
            $this->fail('表单不存在', self::CODE_NOT_FOUND);
        }
        if (empty($form['table'])) {
            $this->fail('表单数据表未配置', self::CODE_BIZ_FAIL);
        }

        $userId = ($this->auth && $this->auth->isLogin()) ? (int)$this->auth->id : 0;
        if ((int)$form['needlogin'] === 1 && $userId === 0) {
            $this->fail('请登录后提交', self::CODE_UNAUTHORIZED);
        }
        if ($userId === 0 && (int)$form['isguest'] !== 1) {
            $this->fail('该表单不允许游客提交', self::CODE_ROLE_DENIED);
        }

        // 字段白名单校验（仅接受表单已定义的字段）
        $fieldDefs = Db::name('cms_fields')
            ->where('source', 'diyform')->where('source_id', (int)$form['id'])
            ->where('status', 'normal')->select()->toArray();
        $allow = array_column($fieldDefs, 'name');
        $rules = [];
        foreach ($fieldDefs as $fd) {
            if (!empty($fd['rule'])) {
                $rules[$fd['name']] = ['rule' => $fd['rule'], 'msg' => $fd['msg'] ?: "{$fd['title']}格式不正确"];
            }
        }

        $row = $this->request->post('row', []);
        if (!is_array($row) || !$row) {
            $this->fail('提交数据为空', self::CODE_PARAM_MISSING);
        }
        $data = [];
        foreach ($allow as $name) {
            if (array_key_exists($name, $row)) {
                $data[$name] = is_array($row[$name]) ? implode(',', $row[$name]) : trim((string)$row[$name]);
            }
        }
        if (!$data) {
            $this->fail('没有可提交的字段', self::CODE_PARAM_INVALID);
        }
        // 服务端二次校验（规则来自后台配置）
        foreach ($rules as $field => $r) {
            if (isset($data[$field]) && $data[$field] !== '' && !preg_match('/' . str_replace('/', '\/', $r['rule']) . '/', (string)$data[$field])) {
                $this->fail($r['msg'] ?: "字段 {$field} 格式不正确", self::CODE_PARAM_INVALID);
            }
        }

        $data['user_id']    = $userId;
        $data['createtime'] = time();
        $data['updatetime'] = time();
        // status 字段按表结构自适应
        $tableFields = $this->tableFields($form['table']);
        if (in_array('status', $tableFields, true) && !isset($data['status'])) {
            $data['status'] = 'normal';
        }

        $newId = (int)Db::name($form['table'])->insertGetId($data);

        $this->success((string)($form['successtips'] ?? '提交成功'), [
            'id'           => $newId,
            'redirect_url' => (string)($form['redirecturl'] ?? ''),
        ]);
    }
}
