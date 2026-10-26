<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__) . '/vendor/autoload.php';

use app\common\model\i18n\Resource;
use app\common\model\i18n\Setting;
use app\common\model\i18n\Translation;
use app\common\service\i18n\BaiduTranslator;
use app\common\service\i18n\TranslationService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use think\facade\Db;

// SQLite 对不存在的表返回空字段，测试需模拟 MySQL 的缺表异常。
class I18nStrictSqlite extends \think\db\connector\Sqlite
{
    public function getFields(string $tableName): array
    {
        $fields = parent::getFields($tableName);
        if (!$fields) {
            throw new RuntimeException('读取了不存在的表结构：' . $tableName);
        }
        return $fields;
    }
}

$app = new think\App();
$app->initialize();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, (string)$e . PHP_EOL); exit(1); });
$app->config->set(['default' => 'i18n_test', 'connections' => ['i18n_test' => [
    'type' => '\\I18nStrictSqlite', 'builder' => '\\think\\db\\builder\\Sqlite', 'database' => ':memory:', 'prefix' => 'test_', 'debug' => false,
]]], 'database');

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "通过：{$message}\n";
}

Db::execute('CREATE TABLE test_app_i18n_setting (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
Db::execute("CREATE TABLE test_app_translation (
    id INTEGER PRIMARY KEY AUTOINCREMENT, resource_type TEXT, resource_id INTEGER, field TEXT,
    locale TEXT, source_locale TEXT, source_hash TEXT, source_text TEXT, translated_text TEXT,
    origin TEXT DEFAULT 'machine', status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0,
    error TEXT DEFAULT '', available_at INTEGER DEFAULT 0, create_time INTEGER, update_time INTEGER,
    UNIQUE (resource_type,resource_id,field,locale))");
Db::execute("CREATE TABLE test_app_plan (id INTEGER PRIMARY KEY, name TEXT, status TEXT)");
Db::name('app_plan')->insert(['id' => 1, 'name' => '会员年度套餐', 'status' => 'normal']);
verify(Resource::current('plan', 1)['name'] === '会员年度套餐', '资源模型使用当前配置前缀读取原文');
$options = Setting::defaults();
$options['enabled'] = 1;
$options['appid'] = 'test-appid';
$options['secret'] = 'test-secret';
$options['interval_ms'] = 0;
Setting::saveOptions($options);
Setting::saveOptions($options + []);
verify(Setting::options()['secret'] === 'test-secret', '保存并读取独立服务配置');
$options['secret'] = '';
Setting::saveOptions($options);
verify(Setting::options()['secret'] === 'test-secret', '空密钥保留现有值');
try {
    Setting::saveOptions(array_replace($options, ['target_locales' => ['not-supported']]));
    throw new RuntimeException('校验未执行');
} catch (InvalidArgumentException $e) {
    verify(true, '拒绝未配置的目标语种');
}

$source = Resource::current('plan', 1);
Translation::enqueue('plan', $source, 'name', 'en', 'zh-cn');
Translation::enqueue('plan', $source, 'name', 'en', 'zh-cn');
verify(Translation::count() === 1, '重复扫描不会重复创建任务');
$task = Translation::ready(10)[0];
verify(Translation::claim($task) && !Translation::claim($task), '同一任务只能领取一次');
Translation::finish($task, ['status' => 'published', 'translated_text' => 'Annual membership']);
$service = new TranslationService(Setting::options());
verify($service->rows('plan', [$source], 'en')[0]['name'] === 'Annual membership', '已发布译文替换展示字段');
verify($service->rows('plan', [$source], 'ja')[0]['name'] === $source['name'], '未启用语种回退原文');
Translation::revise((int)$task['id'], 'Membership plan', 'published', hash('sha256', $source['name']));
Translation::finish($task, ['status' => 'published', 'translated_text' => 'Unexpected']);
verify(Translation::find($task['id'])['translated_text'] === 'Membership plan', '迟到的机器结果不会覆盖人工译文');
Db::name('app_plan')->where('id', 1)->update(['name' => '新套餐']);
$newSource = Resource::current('plan', 1);
verify($service->rows('plan', [$newSource], 'en')[0]['name'] === '新套餐', '原文变更后即时回退原文');
Translation::enqueue('plan', $newSource, 'name', 'en', 'zh-cn');
verify(Translation::find($task['id'])['status'] === 'stale', '原文更新标记人工译文过期');
try {
    Translation::retry((int)$task['id']);
    throw new RuntimeException('锁定未执行');
} catch (RuntimeException $e) {
    verify(str_contains($e->getMessage(), '人工锁定'), '自动重试不能覆盖人工锁定');
}
try {
    Translation::revise((int)$task['id'], 'Old', 'published', hash('sha256', $source['name']));
    throw new RuntimeException('版本检查未执行');
} catch (RuntimeException $e) {
    verify(str_contains($e->getMessage(), '原文已发生变化'), '旧页面提交不会发布过期译文');
}
Translation::revise((int)$task['id'], 'New plan', 'published', hash('sha256', $newSource['name']));
verify($service->rows('plan', [$newSource], 'en')[0]['name'] === 'New plan', '过期人工译文可重新修订发布');
Translation::where('id', $task['id'])->update(['origin' => 'machine']);
Translation::enqueue('plan', ['id' => 1, 'name' => '第三版'], 'name', 'en', 'zh-cn');
verify(Translation::find($task['id'])['status'] === 'pending', '原文更新重新排入机器任务');

$history = [];
$mock = new MockHandler([
    new Response(200, [], '{"trans_result":[{"dst":"Hello "}]}'),
    new Response(200, [], '{"trans_result":[{"dst":" world"}]}'),
    new Response(200, [], '{"trans_result":[{"dst":"Text <safe>"}]}'),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$options = Setting::options();
$options['interval_ms'] = 0;
$translator = new BaiduTranslator($options, new Client(['handler' => $stack]));
verify($translator->translate('你好 {{name}} 世界', 'zh', 'en') === 'Hello {{name}} world', '翻译保留模板占位符');
parse_str((string)$history[0]['request']->getBody(), $sent);
verify($sent['sign'] === md5($options['appid'] . $sent['q'] . $sent['salt'] . $options['secret']), '百度签名使用未编码的原文');
verify((string)$history[0]['request']->getUri() === 'https://fanyi-api.baidu.com/api/trans/vip/translate', '凭据只通过固定 HTTPS 地址的 POST 正文发送');
$html = $translator->translate('<p><a href="https://example.com">你好</a><code>变量</code></p>', 'zh', 'en');
verify(str_contains($html, 'href="https://example.com"') && str_contains($html, 'Text &lt;safe&gt;') && str_contains($html, '<code>变量</code>'), '富文本保留链接和代码，翻译结果不会注入 HTML');

$requests = [];
$stack = HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(200, [], '{"trans_result":[{"dst":"part"}]}'))));
$stack->push(Middleware::history($requests));
$translator = new BaiduTranslator($options, new Client(['handler' => $stack]));
$translator->translate(str_repeat('中', 1001), 'zh', 'en');
foreach ($requests as $request) {
    parse_str((string)$request['request']->getBody(), $payload);
    verify(strlen($payload['q']) <= 900 && mb_check_encoding($payload['q'], 'UTF-8'), '长正文按字节分段且不会截断中文');
}
$translator = new BaiduTranslator($options, new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(200, [], '{"error_code":"54001","error_msg":"sensitive detail"}'),
]))]));
try {
    $translator->translate('测试', 'zh', 'en');
    throw new RuntimeException('错误未抛出');
} catch (RuntimeException $e) {
    verify($e->getMessage() === '百度翻译错误码：54001', '百度错误仅返回错误码，不泄漏敏感数据');
}

// SQLite 注册 MySQL 哈希函数，实际执行带版本约束的译文搜索。
Db::connect()->getPdo()->sqliteCreateFunction('SHA2', fn($value, $bits) => hash('sha256', $value), 2);
Translation::revise((int)$task['id'], 'New plan', 'published', hash('sha256', $newSource['name']));
$query = Db::name('app_plan')->where('status', 'normal');
Translation::applySearch($query, 'plan', ['name'], 'New plan', 'en');
verify($query->count() === 1, '关键词可检索已发布的当前语言译文');
Db::name('app_plan')->where('id', 1)->update(['name' => '最终套餐']);
$query = Db::name('app_plan')->where('status', 'normal');
Translation::applySearch($query, 'plan', ['name'], 'New plan', 'en');
verify($query->count() === 0, '搜索不会命中过期译文');
$query = Db::name('app_plan')->where('status', 'normal');
Translation::applySearch($query, 'plan', ['name'], '最终', 'en');
verify($query->count() === 1, '多语言搜索保留原文匹配');

Db::execute("CREATE TABLE test_app_i18n_message (id INTEGER PRIMARY KEY AUTOINCREMENT, text TEXT, source_hash TEXT UNIQUE, status TEXT)");
$app->config->set(['resources' => [
    'plan' => config('i18n.resources.plan'),
    'api_message' => config('i18n.resources.api_message'),
]], 'i18n');
Translation::where('id', $task['id'])->update(['origin' => 'machine']);
$service = new TranslationService($options);
verify($service->scan() > 1, '扫描现有内容与 API 提示原文目录');
$count = Translation::count();
$service->scan();
verify(Translation::count() === $count, '重复全量扫描不会产生重复任务');
$translator = new BaiduTranslator($options, new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(200, [], '{"trans_result":[{"dst":"Final plan"}]}'),
]))]));
$result = $service->work(1, $translator);
verify($result['success'] === 1 && Translation::find($task['id'])['status'] === 'review', 'worker 将机器译文默认存为待审核');
$failedTask = Translation::ready(1, ['en'])[0];
$translator = new BaiduTranslator($options, new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(200, [], '{"error_code":"54003"}'),
]))]));
$result = $service->work(1, $translator);
$failed = Translation::find($failedTask['id']);
verify($result['failed'] === 1 && $failed['status'] === 'failed' && (int)$failed['attempts'] === 1 && $failed['available_at'] > time(), '失败任务延迟重试并记录尝试次数');
Translation::where('id', $failed['id'])->update(['attempts' => 3, 'available_at' => 0]);
verify(!in_array($failed['id'], array_column(Translation::ready(1000, ['en']), 'id')), '达到重试上限的任务不再自动领取');
Translation::retry((int)$failed['id']);
verify((int)Translation::find($failed['id'])['attempts'] === 0, '后台重试重置机器任务尝试次数');

$message = \app\common\model\i18n\Message::source('已保存');
Translation::enqueue('api_message', $message, 'text', 'en', 'zh-cn');
$messageTask = Translation::where('resource_type', 'api_message')->where('resource_id', $message['id'])->where('locale', 'en')->find();
Translation::revise((int)$messageTask['id'], 'Saved', 'published', hash('sha256', '已保存'));
$app->lang->setLangSet('en');
verify($service->message('已保存') === 'Saved', 'API 提示使用数据库中已发布译文');
verify($service->message('未登记的新提示') === '未登记的新提示', '未登记提示回退原文且不调用翻译 API');

$categoryDefinition = (require dirname(__DIR__) . '/config/i18n.php')['resources']['category'];
$app->config->set(['resources' => config('i18n.resources') + ['category' => $categoryDefinition]], 'i18n');
Translation::enqueue('category', ['id' => 11, 'name' => '子分类'], 'name', 'en', 'zh-cn');
Translation::where('resource_type', 'category')->where('resource_id', 11)->update(['status' => 'published', 'translated_text' => 'Child']);
$tree = $service->categories([['id' => 10, 'name' => '父分类', 'children' => [['id' => 11, 'name' => '子分类', 'children' => []]]]]);
verify($tree[0]['children'][0]['name'] === 'Child' && $tree[0]['name'] === '父分类', '分类树批量翻译并保留层级和原文回退');

$app->request->withSession($app->session);
$auth = new class { public function check($rule) { return true; } };
$cachePath = sys_get_temp_dir() . '/itik-i18n-template-' . bin2hex(random_bytes(8)) . '/';
$viewRows = [
    'setting/index' => Setting::defaults() + ['has_secret' => true],
    'translation/index' => [],
    'translation/edit' => ['id' => 1, 'source_hash' => str_repeat('a', 64), 'locale' => 'en', 'source_text' => '<原文>',
        'translated_text' => 'Text <test>', 'status' => 'review', 'origin' => 'machine'],
];
try {
    foreach ($viewRows as $name => $row) {
        $template = new think\Template(['cache_path' => $cachePath, 'tpl_cache' => false, 'default_filter' => '']);
        $source = preg_replace('/^\{layout[^\n]+\}\s*/', '', file_get_contents(root_path() . 'app/admin/view/i18n/' . $name . '.html'));
        ob_start();
        try {
            $template->display($source, ['row' => $row, 'languages' => config('i18n.languages'), 'auth' => $auth]);
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        verify(strlen($html) > 100, '后台模板可编译渲染：' . $name);
        if ($name === 'translation/edit') {
            verify(str_contains($html, '&lt;原文&gt;') && str_contains($html, 'Text &lt;test&gt;'), '后台编辑页安全回显原文与译文');
        }
    }
} finally {
    foreach (glob($cachePath . '*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($cachePath)) { rmdir($cachePath); }
}
echo "国际化测试全部通过。\n";
