<?php

// +----------------------------------------------------------------------
// | API v1 —— 用户与角色
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use app\common\service\MatrixService;
use think\facade\Db;

/**
 * 用户接口
 *
 * 路由：
 *   GET  /api/v1/users/me           当前用户档案（含角色与订阅状态）
 *   PUT  /api/v1/users/me           更新资料
 *   GET  /api/v1/users/me/identities 已绑定身份
 *   GET  /api/v1/users/me/subscription 当前订阅
 *   GET  /api/v1/roles/plans        套餐列表（公开）
 */
class User extends Base
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /**
     * 当前用户档案
     */
    public function me()
    {
        $userId = $this->userId();
        $user = Db::name('user')
            ->where('id', $userId)
            ->field('id,username,nickname,email,mobile,invite_code,inviter_id,avatar,gender,birthday,bio,money,score,level,group_id,logintime')
            ->find();

        if (!$user) {
            $this->fail('用户不存在', self::CODE_NOT_FOUND);
        }

        $this->success('', [
            'user'         => [
                'id'          => (int)$user['id'],
                'username'    => $user['username'],
                'nickname'    => $user['nickname'],
                'email'       => $user['email'],
                'mobile'      => $user['mobile'],
                'invite_code' => (string)($user['invite_code'] ?? ''),
                'inviter_id'  => (int)($user['inviter_id'] ?? 0),
                'avatar'      => $user['avatar'],
                'gender'      => (int)$user['gender'],
                'bio'         => $user['bio'],
                'money'       => $user['money'],
                'score'       => (int)$user['score'],
                'level'       => (int)$user['level'],
            ],
            'role'         => $this->role,
            'subscription' => $this->subscription ? [
                'status'       => $this->subscription['status'],
                'plan_code'    => $this->subscription['plan_code'],
                'role_code'    => $this->subscription['role_code'],
                'start_at'     => (int)$this->subscription['start_at'],
                'end_at'       => (int)$this->subscription['end_at'],
                'grace_end_at' => (int)$this->subscription['grace_end_at'],
                'active'       => $this->subscriptionActive(),
            ] : null,
        ]);
    }

    /**
     * 更新资料（仅允许白名单字段）
     */
    public function update()
    {
        $userId = $this->userId();
        $allow = ['nickname', 'avatar', 'gender', 'birthday', 'bio'];
        $data = [];
        foreach ($allow as $field) {
            $val = $this->request->put($field, null);
            if ($val !== null) {
                $data[$field] = $val;
            }
        }
        if (!$data) {
            $this->fail('没有需要更新的字段', self::CODE_PARAM_MISSING);
        }
        if (isset($data['nickname'])) {
            $nickname = trim((string)$data['nickname']);
            if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 50) {
                $this->fail('昵称长度需在 2-50 字符之间', self::CODE_PARAM_INVALID);
            }
            $dup = Db::name('user')->where('nickname', $nickname)->where('id', '<>', $userId)->find();
            if ($dup) {
                $this->fail('该昵称已被占用', self::CODE_DUPLICATE);
            }
            $data['nickname'] = $nickname;
        }
        if (isset($data['bio']) && mb_strlen((string)$data['bio']) > 100) {
            $this->fail('简介不能超过 100 字符', self::CODE_PARAM_INVALID);
        }

        $data['update_time'] = time();
        Db::name('user')->where('id', $userId)->update($data);

        $this->success('更新成功', $data);
    }

    /**
     * 已绑定身份（邮箱/钱包，钱包后续阶段接入）
     */
    public function identities()
    {
        $list = Db::name('app_identity')
            ->where('user_id', $this->userId())
            ->field('id,type,identifier,verified_at,create_time')
            ->order('id', 'asc')
            ->select();

        // 脱敏展示：邮箱隐藏 @ 前部分字符
        $list = $list->toArray();
        foreach ($list as $k => $item) {
            if ($item['type'] === 'email') {
                $parts = explode('@', (string)$item['identifier']);
                $name  = $parts[0] ?? '';
                $list[$k]['identifier_masked'] = (mb_strlen($name) > 2
                        ? mb_substr($name, 0, 2) . str_repeat('*', max(1, mb_strlen($name) - 2))
                        : $name) . '@' . ($parts[1] ?? '');
            } else {
                $list[$k]['identifier_masked'] = mb_substr((string)$item['identifier'], 0, 6) . '...';
            }
        }

        $this->success('', ['list' => $list]);
    }

    /**
     * 当前订阅
     */
    public function subscription()
    {
        $list = Db::name('app_subscription')
            ->where('user_id', $this->userId())
            ->field('id,plan_code,role_code,status,start_at,end_at,grace_end_at,create_time')
            ->order('id', 'desc')
            ->limit(20)
            ->select();

        $this->success('', [
            'current'   => $this->subscription,
            'status'    => $this->subscriptionStatus(),
            'active'    => $this->subscriptionActive(),
            'history'   => $list,
        ]);
    }

    public function findFri(){
        $username = $this->request->post('username');
        $user = \app\common\model\User::where('username', $username)->find();
        $params = [];
        if(!$user){
            $this->error('未找到好友');
        }else{
            $params[$user->id]['id'] = $user->id;
            $params[$user->id]['username'] = $user->username;
            $params[$user->id]['nickname'] = $user->nickname;
            $params[$user->id]['imUser'] = (new MatrixService())->buildUserId($user->id);
        }
        $this->success(__('Successful'), $params);
    }

    public function friends(){
        $ids = $this->request->post('ids');
        if (!$ids) {
            $this->error(__('Invalid parameters'));
        }
        $params = [];
        foreach ($ids as $id) {
            $user = \app\common\model\User::where('id', $id)->find();
            $params[$id]['id'] = $id;
            $params[$id]['username'] = $user->username;
            $params[$user->id]['nickname'] = $user->nickname;
            $params[$id]['imUser'] = (new MatrixService())->buildUserId($id);
        }
        if (!empty($params)) {
            $this->success(__('Successful'), $params);
        } else {
            $this->error('获取异常');
        }
    }
}
