<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;
use app\common\library\FrontendAuth;

$app = new think\App();
$app->initialize();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, (string)$e . PHP_EOL); exit(1); });
$app->config->set(['default' => 'password_test', 'connections' => ['password_test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'test_', 'debug' => false,
]]], 'database');

function checkLogin(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "通过：{$message}\n";
}

// 只替换令牌签发边界，保留真实数据库查询、密码校验和锁定逻辑。
class PasswordTestAuth extends FrontendAuth
{
    public int $signedUserId = 0;
    public function direct($user_id)
    {
        $this->signedUserId = (int)$user_id;
        return true;
    }
}
Db::execute('CREATE TABLE test_user (id INTEGER PRIMARY KEY, email TEXT, password TEXT, status TEXT, loginfailure INTEGER, loginfailuretime INTEGER, update_time INTEGER)');
$auth = new PasswordTestAuth();
Db::name('user')->insert(['id' => 1, 'email' => 'member@example.com', 'password' => $auth->getEncryptPassword(' Password123 '), 'status' => 'normal', 'loginfailure' => 0, 'loginfailuretime' => 0]);
checkLogin(!$auth->login('absent@example.com', 'Password123'), '未注册邮箱不能登录');
checkLogin(!$auth->login('member@example.com', 'Password123'), '密码首尾空格不被误删');
checkLogin((int)Db::name('user')->where('id', 1)->value('loginfailure') === 1, '密码错误记录失败次数');
checkLogin($auth->signedUserId === 0, '认证失败不进入令牌签发');
checkLogin($auth->login('member@example.com', ' Password123 ') && $auth->signedUserId === 1, '正确邮箱密码进入现有令牌签发流程');
Db::name('user')->where('id', 1)->update(['status' => 'hidden']);
checkLogin(!(new PasswordTestAuth())->login('member@example.com', ' Password123 '), '禁用账号不能登录');
Db::name('user')->where('id', 1)->update(['status' => 'normal', 'loginfailure' => 10, 'loginfailuretime' => time()]);
checkLogin(!(new PasswordTestAuth())->login('member@example.com', ' Password123 '), '达到失败上限后正确密码也不能登录');
Db::name('user')->where('id', 1)->update(['loginfailuretime' => time() - 86401]);
checkLogin((new PasswordTestAuth())->login('member@example.com', ' Password123 '), '超过锁定期限后可重新校验密码');

// 控制器失败分支直接捕获响应，确保不会触发 IM 或业务数据库访问。
class PasswordTestResponse extends RuntimeException
{
    public function __construct(public int $responseCode) { parent::__construct('测试响应'); }
}
class PasswordTestController extends \app\api\controller\v1\Auth
{
    public function __construct($request, $auth)
    {
        $this->request = $request;
        $this->auth = $auth;
    }
    protected function fail(string $msg, int $code = self::CODE_BIZ_FAIL, $data = null): void
    {
        throw new PasswordTestResponse($code);
    }
}
foreach ([
    [['email' => 'bad', 'password' => 'anything'], 10001],
    [['email' => 'member@example.com'], 10002],
    [['email' => 'absent@example.com', 'password' => 'anything'], 20001],
    [['email' => 'member@example.com', 'password' => 'wrong'], 20001],
] as [$params, $expected]) {
    $request = new think\Request();
    $request->withPost($params);
    try {
        (new PasswordTestController($request, new PasswordTestAuth()))->passwordLogin();
        throw new RuntimeException('未拦截无效登录');
    } catch (PasswordTestResponse $e) {
        checkLogin($e->responseCode === $expected, '接口返回预期错误码：' . $expected);
    }
}
echo "邮箱密码登录测试全部通过。\n";
