<?php
/**
 * 自动生成swagger接口文档入口
 */

include 'vendor/autoload.php';

defined('ROOT_PATH') or define('ROOT_PATH', __DIR__);
// 启动目录
defined('START_DIR_ROOT') or define('START_DIR_ROOT', __DIR__);

if (!isset($_SERVER['argv'][1])) {
    exit("【Error】missing source dir\n");
}
registerNamespace($_SERVER['argv'][1]);

// 执行 php swag.php Test 即可生成接口文档
$items = explode('/', $_SERVER['argv'][1]);
$appName = $items[0];
$argv[2] = $_SERVER['argv'][2] = '-o';
$argv[3] = $_SERVER['argv'][3] = "swaggerui/openapi-{$appName}.yaml";
$argc = count($argv);

$path = __DIR__.'/'.$appName;
if (!is_dir($path)) {
    $appName = ucfirst($appName);
    $path = __DIR__.'/'.$appName;
    if (!is_dir($path)) {
        exit("【Error】 找不到对应的项目目录\n");
    }
}

$argv[1] = $_SERVER['argv'][1] = $appName.'/Module';

if (!class_exists('OpenApi\Attributes\Server')) {
    exit("Missing zircote/swagger-php, please install it!\n");
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/'.$appName);
$envArr = $dotenv->load();

// 不同环境定义不同网站host
defined('WEB_SITE_HOST') or define('WEB_SITE_HOST', $envArr['WEB_SITE_HOST'] ?? 'http://bing.swoolefy.com');

echo "文档开始生成......".PHP_EOL;
include "vendor/bin/openapi";