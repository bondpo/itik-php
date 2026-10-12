<?php

namespace badou;

use Throwable;
use think\facade\Lang;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use app\common\library\Resend;

/**
 * 邮件类
 * 支持 Resend API 与 SMTP 两种发送方式，默认自动初始化配置
 */
class Email extends PHPMailer
{
    /**
     * 是否已在管理后台或环境变量中配置好邮件服务
     * @var bool
     */
    public bool $configured = false;

    /**
     * 邮件发送驱动（resend 或 smtp）
     * @var string
     */
    public string $driver = 'resend';

    /**
     * 原始未编码的主题
     * @var string
     */
    protected string $rawSubject = '';

    /**
     * 默认配置
     * @var array
     */
    public array $options = [
        'charset' => 'utf-8',
        'debug'   => true,
        'lang'    => 'zh_cn',
    ];

    /**
     * 构造函数
     * @param array $options
     * @throws Throwable
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);

        parent::__construct($this->options['debug']);
        $langSet = Lang::getLangSet();
        if ($langSet == 'zh-cn' || !$langSet) {
            $langSet = 'zh_cn';
        }
        $this->options['lang'] = $this->options['lang'] ?: $langSet;

        $langPath = root_path() . 'vendor' . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR;
        if (is_dir($langPath)) {
            $this->setLanguage($this->options['lang'], $langPath);
        }
        $this->CharSet = $this->options['charset'];

        $sysMailConfig = (array)get_sys_config('', 'mail');

        // 判断发送驱动，系统配置优先，未指定则根据 Resend 密钥自动判断
        $this->driver = $sysMailConfig['mail_type'] ?? (config('resend.api_key') ? 'resend' : 'smtp');

        if ($this->driver === 'resend') {
            $apiKey = $sysMailConfig['resend_key'] ?? config('resend.api_key');
            if ($apiKey) {
                $this->configured = true;
                $fromEmail = $sysMailConfig['resend_from'] ?? config('resend.from_email', 'service@itiklink.com');
                $fromName  = $sysMailConfig['resend_from_name'] ?? config('resend.from_name', 'ITIK');
                $this->setFrom($fromEmail, $fromName);
            } else {
                $this->configured = false;
                throw new PHPMailerException('Resend 邮件服务未配置 API Key');
            }
        } else {
            // SMTP 驱动
            $requiredKeys = ['smtp_server', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_sender_mail'];
            $this->configured = true;
            foreach ($requiredKeys as $key) {
                if (empty($sysMailConfig[$key])) {
                    $this->configured = false;
                    break;
                }
            }
            if (!$this->configured) {
                throw new PHPMailerException('SMTP 邮箱配置不完整，请在管理后台配置好邮件服务');
            }

            $this->Host       = $sysMailConfig['smtp_server'];
            $this->SMTPAuth   = true;
            $this->Username   = $sysMailConfig['smtp_user'];
            $this->Password   = $sysMailConfig['smtp_pass'];
            $this->SMTPSecure = ($sysMailConfig['smtp_verification'] ?? '') == 'SSL' ? self::ENCRYPTION_SMTPS : self::ENCRYPTION_STARTTLS;
            $this->Port       = $sysMailConfig['smtp_port'];

            $this->setFrom($sysMailConfig['smtp_sender_mail'], $sysMailConfig['smtp_user']);
        }
    }

    /**
     * 链式添加收件人
     * @param string $address
     * @param string $name
     * @return $this
     */
    public function to(string $address, string $name = ''): static
    {
        $this->addAddress($address, $name);
        return $this;
    }

    /**
     * 链式设置邮件主题
     * @param string $subject
     * @return $this
     */
    public function subject(string $subject): static
    {
        $this->setSubject($subject);
        return $this;
    }

    /**
     * 链式设置发件人
     * @param string $address
     * @param string $name
     * @return $this
     */
    public function from(string $address, string $name = ''): static
    {
        $this->setFrom($address, $name);
        return $this;
    }

    /**
     * 设置邮件正文
     * @param string  $body   邮件内容
     * @param boolean $ishtml 是否HTML格式
     * @return $this
     */
    public function message(string $body, bool $ishtml = true): static
    {
        $this->isHTML($ishtml);
        $this->Body = $body;
        return $this;
    }

    /**
     * 设置邮件主题
     * @param string $subject
     * @return void
     */
    public function setSubject($subject): void
    {
        $this->rawSubject = (string)$subject;
        $this->Subject = "=?utf-8?B?" . base64_encode($subject) . "?=";
    }

    /**
     * 发送邮件
     * @return bool
     * @throws PHPMailerException
     */
    public function send(): bool
    {
        if ($this->driver === 'resend') {
            $toAddresses = [];
            foreach ($this->to as $recipient) {
                if (!empty($recipient[0])) {
                    $toAddresses[] = $recipient[0];
                }
            }
            if (empty($toAddresses)) {
                $this->ErrorInfo = '收件人地址不能为空';
                if ($this->exceptions) {
                    throw new PHPMailerException($this->ErrorInfo);
                }
                return false;
            }

            $subject = $this->rawSubject ?: $this->Subject;
            if (preg_match('/^=\?utf-8\?B\?(.+)\?=$/i', $subject, $matches)) {
                $subject = base64_decode($matches[1]);
            }

            $from = '';
            if ($this->From) {
                $from = $this->FromName ? "{$this->FromName} <{$this->From}>" : $this->From;
            }

            try {
                Resend::send([
                    'from'    => $from,
                    'to'      => $toAddresses,
                    'subject' => $subject,
                    'html'    => $this->Body,
                    'text'    => $this->AltBody,
                ]);
                return true;
            } catch (Throwable $e) {
                $this->ErrorInfo = $e->getMessage();
                if ($this->exceptions) {
                    throw new PHPMailerException($this->ErrorInfo, 0, $e);
                }
                return false;
            }
        }

        return parent::send();
    }

    /**
     * 获取最后的错误信息
     * @return string
     */
    public function getError(): string
    {
        return $this->ErrorInfo;
    }
}
