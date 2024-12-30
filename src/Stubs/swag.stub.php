<?php
/**
 * 自动生成swagger接口文档入口
 */
include __DIR__.'/vendor/autoload.php';

if (!isset($_SERVER['argv'][1])) {
    exit("【Error】missing source dir\n");
}

// 启动目录
defined('START_DIR_ROOT') or define('START_DIR_ROOT', __DIR__);
// 应用父目录
defined('ROOT_PATH') or define('ROOT_PATH', __DIR__);
// 应用目录(此处获取的$_SERVER['argv'][1])
defined('APP_PATH') or define('APP_PATH', __DIR__.'/'.ucfirst($_SERVER['argv'][1]));

registerNamespace(APP_PATH);


// 执行 php swag.php Test 即可生成接口文档
$items = explode(DIRECTORY_SEPARATOR, $_SERVER['argv'][1]);
$appName = $items[0];
$argv[2] = $_SERVER['argv'][2] = '-o';
$argv[3] = $_SERVER['argv'][3] = "swaggerui/openapi-{$appName}.yaml";
$argc = count($argv);

$path = __DIR__.'/'.$appName;
if (!is_dir($path)) {
    $appName = ucfirst($appName);
    $path = __DIR__.'/'.$appName;
    if (!is_dir($path)) {
        exit("【Error】 找不到对应的项目目录".PHP_EOL);
    }
}

$argv[1] = $_SERVER['argv'][1] = $appName.'/Module';

if (!class_exists('OpenApi\Attributes\Server')) {
    exit("Missing zircote/swagger-php, please composer install it!\n");
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/'.$appName);
$envArr = $dotenv->load();

// 不同环境定义不同网站host
defined('WEB_SITE_HOST') or define('WEB_SITE_HOST', $envArr['WEB_SITE_HOST'] ?? 'http://bing.swoolefy.com');

echo "文档开始生成......".PHP_EOL;
include __DIR__."/vendor/bin/openapi";