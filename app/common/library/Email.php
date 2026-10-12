<?php

namespace app\common\library;

use badou\Email as BadouEmail;

/**
 * 邮件服务类
 * 继承并别名 badou\Email，统一支持 Resend API 与 SMTP
 */
class Email extends BadouEmail
{
}
