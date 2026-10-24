<?php

// +----------------------------------------------------------------------
// | BADOUCMS [ 八斗网站系统 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2024-2030 http://doc.ldcode.com.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: lande <939134342@qq.com>
// +----------------------------------------------------------------------

namespace app\common\model;

use think\Model;
use think\facade\Db;

/**
 * @method static mixed getByUsername($str) 通过用户名查询用户
 * @method static mixed getByNickname($str) 通过昵称查询用户
 * @method static mixed getByMobile($str) 通过手机查询用户
 * @method static mixed getByEmail($str) 通过邮箱查询用户
 * @method static mixed getByInviteCode($str) 通过邀请码查询用户
 * Class User
 * @package app\common\model
 * Author: Wusn <958342972@qq.com>
 * DateTime: 2025/6/22 20:15
 */
class User extends Model
{
    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int'
    ];
    // 追加属性
    protected $append = [
        'url'
    ];

    public static function onBeforeInsert($user): void
    {
        if (empty($user->invite_code)) {
            $user->invite_code = self::generateInviteCode();
        }
    }

    /**
     * 用户修改昵称或头像后，自动同步到 IM (Matrix)
     */
    public static function onAfterUpdate($user): void
    {
        $changed = $user->getChangedData();

        $hasNickname = isset($changed['nickname']);
        $hasAvatar   = isset($changed['avatar']);

        if (!$hasNickname && !$hasAvatar) {
            return;
        }

        $nickname  = $hasNickname ? (string)$changed['nickname'] : null;
        $avatarUrl = null;

        if ($hasAvatar) {
            $avatar = (string)$changed['avatar'];
            if ($avatar !== '') {
                try {
                    $avatarUrl = function_exists('cdnurl') ? cdnurl($avatar, true) : $avatar;
                } catch (\Throwable) {
                    $avatarUrl = $avatar;
                }
            } else {
                $avatarUrl = '';
            }
        }

        try {
            (new \app\common\service\MatrixService())->updateProfile((int)$user->id, $nickname, $avatarUrl);
        } catch (\Throwable) {
            // IM 未配置或同步失败均不影响主流程
        }
    }

    /**
     * 生成唯一邀请码
     * @param int $length 邀请码长度
     * @return string
     */
    public static function generateInviteCode(int $length = 8): string
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (self::where('invite_code', $code)->find());
        return $code;
    }

    /**
     * 推荐人关联
     */
    public function inviter()
    {
        return $this->belongsTo(self::class, 'inviter_id', 'id');
    }

    /**
     * 通过钱包地址查询用户（自动转小写匹配）
     * @param string $walletAddress 钱包地址
     * @return static|null
     */
    public static function getByWalletAddress(string $walletAddress)
    {
        $walletAddress = strtolower(trim($walletAddress));
        if ($walletAddress === '') {
            return null;
        }
        return self::where('wallet_address', $walletAddress)->find();
    }

    public function getUrlAttr($value, $data)
    {
        return "/u/" . $data['id'];
    }

    /**
     * 获取头像
     * @param string $value
     * @param array  $data
     * @return string
     */
    public function getAvatarAttr($value, $data)
    {
        if (!$value) {
            //如果不需要启用首字母头像，请使用
            //$value = '/assets/img/avatar.png';
            $value = letter_avatar($data['nickname']);
        }
        return $value;
    }

    /**
     * 获取会员的组别
     */
    public function getGroupAttr($value, $data)
    {
        return UserGroup::find($data['group_id']);
    }

    /**
     * 获取验证字段数组值
     * @param string $value
     * @param array  $data
     * @return  object
     */
    public function getVerificationAttr($value, $data)
    {
        $value = array_filter((array)json_decode($value, true));
        $value = array_merge(['email' => 0, 'mobile' => 0], $value);
        return (object)$value;
    }

    /**
     * 设置验证字段
     * @param mixed $value
     * @return string
     */
    public function setVerificationAttr($value)
    {
        $value = is_object($value) || is_array($value) ? json_encode($value) : $value;
        return $value;
    }

    /**
     * 变更会员余额
     * @param int    $money   余额
     * @param int    $user_id 会员ID
     * @param string $memo    备注
     */
    public static function money($money, $user_id, $memo)
    {
        Db::startTrans();
        try {
            $user = self::lock(true)->find($user_id);
            if ($user && $money != 0) {
                $before = $user->money;
                //$after = $user->money + $money;
                $after = function_exists('bcadd') ? bcadd($user->money, $money, 2) : $user->money + $money;
                //更新会员信息
                $user->save(['money' => $after]);
                //写入日志
                MoneyLog::create(['user_id' => $user_id, 'money' => $money, 'before' => $before, 'after' => $after, 'memo' => $memo]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
        }
    }

    /**
     * 变更会员积分
     * @param int    $score   积分
     * @param int    $user_id 会员ID
     * @param string $memo    备注
     */
    public static function score($score, $user_id, $memo)
    {
        Db::startTrans();
        try {
            $user = self::lock(true)->find($user_id);
            if ($user && $score != 0) {
                $before = $user->score;
                $after = $user->score + $score;
                $level = self::nextlevel($after);
                //更新会员信息
                $user->save(['score' => $after, 'level' => $level]);
                //写入日志
                ScoreLog::create(['user_id' => $user_id, 'score' => $score, 'before' => $before, 'after' => $after, 'memo' => $memo]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
        }
    }

    /**
     * 根据积分获取等级
     * @param int $score 积分
     * @return int
     */
    public static function nextlevel($score = 0)
    {
        $lv = array(1 => 0, 2 => 30, 3 => 100, 4 => 500, 5 => 1000, 6 => 2000, 7 => 3000, 8 => 5000, 9 => 8000, 10 => 10000);
        $level = 1;
        foreach ($lv as $key => $value) {
            if ($score >= $value) {
                $level = $key;
            }
        }
        return $level;
    }
}
