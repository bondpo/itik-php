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

namespace app\common\library;

use badou\Email;
use badou\Random;
use think\facade\Event;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * 邮箱验证码类
 */
class Ems
{
    /**
     * 验证码有效时长
     * @var int
     */
    protected static $expire = 120;

    /**
     * 最大允许检测的次数
     * @var int
     */
    protected static $maxCheckNums = 10;


    /**
     * 邮件发送异常的消息回执
     * @var string
     */
    protected static $errorMessage = "";

    private static function setErrorMessage($message)
    {
        self::$errorMessage = $message;
    }
    public static function getLastErrorMessage()
    {
        return self::$errorMessage;
    }
    /**
     * 获取最后一次邮箱发送的数据
     *
     * @param int    $email 邮箱
     * @param string $event 事件
     * @return  Ems|null
     */
    public static function get($email, $event = 'default')
    {
        $ems = \app\common\model\Ems::where(['email' => $email, 'event' => $event])
            ->order('id', 'DESC')
            ->find();
        if ($ems) {
            $ems = $ems->toArray();
        }
        Event::trigger('ems_get', $ems);
        return $ems ?: null;
    }

    /**
     * 发送验证码
     *
     * @param int    $email 邮箱
     * @param int    $code  验证码,为空时将自动生成4位数字
     * @param string $event 事件
     * @return  boolean
     */
    public static function send($email, $code = null, $event = 'default')
    {
        $code = is_null($code) ? Random::build('numeric', config('captcha.length')) : $code;
        $time = time();
        $ip = request()->ip();
        $ems = \app\common\model\Ems::create(['event' => $event, 'email' => $email, 'code' => $code, 'ip' => $ip, 'create_time' => $time]);
//        if (!Event::hasListener('ems_send')) {
//            //采用框架默认的邮件推送
//            Event::listen('ems_send', function ($params) {
//                $result = true;
//                try {
//                    $mail = new Email();
//                    $mail->addAddress($params->email);
//                    $mail->setSubject('请查收你的验证码！');
//                    $mail->message("你的验证码是：" . $params->code . "，" . ceil(self::$expire / 60) . "分钟内有效。")
//                        ->send();
//                } catch (\Throwable $e) {
//                    $result = false;
//                    self::setErrorMessage($e->getMessage());
//                }
//                return $result;
//            });
//        }

        if (!Event::hasListener('ems_send')) {
            Event::listen('ems_send', function ($params) {
                $result = true;
                try {
                    $mail = new Email();
                    $mail->addAddress($params->email);
                    $mail->setSubject('itiklink验证码！');
                    $expire = ceil(self::$expire / 60);
                    $code = htmlspecialchars((string) $params->code, ENT_QUOTES, 'UTF-8');
                    $appName = 'itiklink';
                    $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱验证码</title>
</head>
<body style="margin:0;padding:0;background-color:#1e1e1e;font-family:Arial,'Microsoft YaHei',sans-serif;color:#eaecef;">

<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background-color:#1e1e1e;padding:35px 15px;">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background-color:#1e1e1e;">

                <tr>
                    <td style="padding:10px 0 30px;font-size:26px;font-weight:bold;color:#f0b90b;">
                        {$appName}
                    </td>
                </tr>

                <tr>
                    <td style="font-size:26px;font-weight:bold;line-height:1.6;padding-bottom:20px;color:#ffffff;">
                        您的邮箱验证码
                    </td>
                </tr>

                <tr>
                    <td style="font-size:16px;line-height:1.9;color:#eaecef;padding-bottom:20px;">
                        您好！<br>
                        我们收到了您的验证码请求。请使用以下验证码完成验证。
                    </td>
                </tr>

                <tr>
                    <td align="center"
                        style="padding:25px 15px;background-color:#2b2b2b;border-radius:8px;">
                        <div style="font-size:14px;color:#b7bdc6;padding-bottom:12px;">
                            您的验证码
                        </div>
                        <div style="font-size:36px;font-weight:bold;letter-spacing:8px;color:#f0b90b;">
                            {$code}
                        </div>
                        <div style="font-size:14px;color:#b7bdc6;padding-top:15px;">
                            请在 {$expire} 分钟内完成验证
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top:30px;font-size:16px;line-height:1.9;color:#eaecef;">
                        <strong style="color:#ffffff;">安全提示：</strong>
                        请勿向任何人透露您的验证码，包括自称客服或工作人员的人员。
                        如果这不是您本人的操作，请忽略此邮件，并检查您的账户安全。
                    </td>
                </tr>

                <tr>
                    <td style="padding-top:25px;font-size:14px;line-height:1.9;color:#b7bdc6;">
                        <strong style="color:#ffffff;">请注意：</strong>
                        我们不会通过电话、邮件或即时通讯工具向您索取验证码或账户密码。
                        请谨慎识别钓鱼邮件，确保您访问的是正确的官方网站。
                    </td>
                </tr>

                <tr>
                    <td style="padding-top:35px;border-top:1px solid #353535;margin-top:25px;
                               font-size:13px;line-height:1.8;color:#848e9c;text-align:center;">
                        此邮件由系统自动发送，请勿直接回复。<br>
                        &copy; 2026 {$appName}，版权所有
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
HTML;
                    $mail->message($html)->send();
                } catch (\Throwable $e) {
                    $result = false;
                    self::setErrorMessage($e->getMessage());
                }
                return $result;
            });
        }

        $result = Event::trigger('ems_send', $ems, true);
        if (!$result) {
            $ems->delete();
            return false;
        }
        return true;
    }

    /**
     * 发送通知
     *
     * @param mixed  $email    邮箱,多个以,分隔
     * @param string $msg      消息内容
     * @param string $template 消息模板
     * @return  boolean
     */
    public static function notice($email, $msg = '', $template = null)
    {
        $params = [
            'email'    => $email,
            'msg'      => $msg,
            'template' => $template
        ];
        if (!Event::hasListener('ems_notice')) {
            Event::listen('ems_notice', function ($params) {
                $result = true;
                try {
                    $subject = '你收到一封新的邮件！';
                    $content = $params['msg'];
                    $mail = new Email();
                    $mail->addAddress($params['email']);
                    $mail->setSubject($subject);
                    $result = $mail
                        ->message($content)
                        ->send();
                } catch (\Throwable $e) {
                    $result = false;
                    self::setErrorMessage($e->getMessage());
                }
                return $result;
            });
        }
        $result = Event::trigger('ems_notice', $params);
        return (bool)$result;
    }

    /**
     * 校验验证码
     *
     * @param int    $email 邮箱
     * @param int    $code  验证码
     * @param string $event 事件
     * @return  boolean
     */
    public static function check($email, $code, $event = 'default')
    {
        $time = time() - self::$expire;
        $ems = \app\common\model\Ems::where(['email' => $email, 'event' => $event])
            ->order('id', 'DESC')
            ->find();
        if ($ems) {
            if ($ems['create_time'] > $time && $ems['times'] <= self::$maxCheckNums) {
                $correct = $code == $ems['code'];
                if (!$correct) {
                    $ems->times = $ems->times + 1;
                    $ems->save();
                    return false;
                } else {
                    Event::trigger('ems_check', $ems);
                    return true;
                }
            } else {
                // 过期则清空该邮箱验证码
                self::flush($email, $event);
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * 清空指定邮箱验证码
     *
     * @param int    $email 邮箱
     * @param string $event 事件
     * @return  boolean
     */
    public static function flush($email, $event = 'default')
    {
        \app\common\model\Ems::where(['email' => $email, 'event' => $event])
            ->delete();
        Event::trigger('ems_flush');
        return true;
    }
}
