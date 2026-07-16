<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * ApplicationConfig 配置路径与 yaml 闸门回归。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | resolveConfigPath | 优先 CONFIG_PATH，否则 APP_PATH/Config |
 * | loadPhpConfig | 读 Config/*.php，不依赖 application.yaml |
 * | Test 应用 | workflow.php / neuron_ai.php / job.php 可加载 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Tests/ApplicationConfigTest.php
 * ```
 */

use Swoolefy\Support\ApplicationConfig;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/SwoolefyTestBootstrap.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

/** CONFIG_PATH 已由 bootstrap 定义为 APP_PATH/Config。 */
function testResolveConfigPathUsesConfigConstant(): void
{
    $path = ApplicationConfig::resolveConfigPath();
    assertTrue($path === rtrim((string) CONFIG_PATH, '/'), 'resolveConfigPath should use CONFIG_PATH');
    assertTrue(str_ends_with($path, '/Config'), 'config dir basename should be Config');
    pass('resolve config path uses CONFIG_PATH');
}

/** Test 应用存在 Config/workflow.php 时应加载到非空数组。 */
function testLoadPhpConfigFromTestApp(): void
{
    $workflow = ApplicationConfig::loadPhpConfig('workflow.php');
    assertTrue(isset($workflow['workflow']), 'workflow.php should load under Config/');
    assertTrue(
        array_key_exists('default_run_store', (array) ($workflow['workflow'] ?? [])),
        'workflow.default_run_store should be present',
    );

    $neuron = ApplicationConfig::loadPhpConfig('neuron_ai.php');
    assertTrue($neuron !== [] || is_file(CONFIG_PATH . '/neuron_ai.php') === false, 'neuron_ai load should not throw');
    if (is_file(CONFIG_PATH . '/neuron_ai.php')) {
        assertTrue($neuron !== [], 'neuron_ai.php should load when file exists');
    }

    $job = ApplicationConfig::loadPhpConfig('job.php');
    if (is_file(CONFIG_PATH . '/job.php')) {
        assertTrue($job !== [], 'job.php should load when file exists');
    }

    assertTrue(ApplicationConfig::loadPhpConfig('definitely_missing_xyz.php') === [], 'missing file returns []');
    pass('load php config from Test/Config');
}

/**
 * 子进程：无 application.yaml 时仍可加载 Config/*.php（证明已去掉 yaml 闸门）。
 */
function testLoadPhpConfigWithoutApplicationYaml(): void
{
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    $tmpRoot = sys_get_temp_dir() . '/swoolefy_cfg_' . bin2hex(random_bytes(4));
    $configDir = $tmpRoot . '/Config';
    assertTrue(@mkdir($configDir, 0777, true) || is_dir($configDir), 'temp Config dir');

    $php = <<<'PHP'
<?php
return ['demo' => ['enabled' => true, 'name' => 'no-yaml']];
PHP;
    file_put_contents($configDir . '/demo.php', $php);

    $script = <<<'PHP'
<?php
require $argv[1];
define('APP_PATH', $argv[2]);
// 故意不创建 application.yaml，也不定义 CONFIG_PATH → 回退 APP_PATH/Config
$loaded = Swoolefy\Support\ApplicationConfig::loadPhpConfig('demo.php');
if (($loaded['demo']['name'] ?? null) !== 'no-yaml') {
    fwrite(STDERR, 'expected demo config without application.yaml, got: ' . json_encode($loaded) . PHP_EOL);
    exit(1);
}
if (Swoolefy\Support\ApplicationConfig::hasApplicationYaml()) {
    fwrite(STDERR, 'application.yaml should be absent' . PHP_EOL);
    exit(1);
}
echo "ok\n";
PHP;

    $scriptFile = $tmpRoot . '/runner.php';
    file_put_contents($scriptFile, $script);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptFile)
        . ' ' . escapeshellarg($autoload)
        . ' ' . escapeshellarg($tmpRoot);
    exec($cmd, $output, $exitCode);

    // cleanup
    @unlink($configDir . '/demo.php');
    @unlink($scriptFile);
    @rmdir($configDir);
    @rmdir($tmpRoot);

    assertTrue($exitCode === 0, 'subprocess should load Config without application.yaml: ' . implode("\n", $output));
    pass('load php config without application.yaml');
}

/** 小写 config/ 不是规范路径（在大小写敏感 FS 上不应被误用）。 */
function testCanonicalPathIsCapitalConfig(): void
{
    $path = ApplicationConfig::resolveConfigPath();
    assertTrue(!str_ends_with($path, '/config'), 'must not resolve to lowercase config/');
    assertTrue(str_ends_with($path, '/Config'), 'canonical dir is Config');
    pass('canonical path is Config');
}

/**
 * 子进程：无 APP_PATH/CONFIG_PATH 时 loadPhpConfig 返回 []（不抛错）。
 */
function testLoadPhpConfigWithoutPathContextReturnsEmpty(): void
{
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    $script = <<<'PHP'
<?php
require $argv[1];
$loaded = Swoolefy\Support\ApplicationConfig::loadPhpConfig('workflow.php');
if ($loaded !== []) {
    fwrite(STDERR, 'expected [] without path context' . PHP_EOL);
    exit(1);
}
if (Swoolefy\Support\ApplicationConfig::hasConfigPathContext()) {
    fwrite(STDERR, 'hasConfigPathContext should be false' . PHP_EOL);
    exit(1);
}
echo "ok\n";
PHP;

    $tmp = sys_get_temp_dir() . '/swoolefy_cfg_nopath_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmp, $script);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($autoload);
    exec($cmd, $output, $exitCode);
    @unlink($tmp);

    assertTrue($exitCode === 0, 'subprocess without path context: ' . implode("\n", $output));
    pass('load php config without path context returns empty');
}

$tests = [
    'testResolveConfigPathUsesConfigConstant',
    'testLoadPhpConfigFromTestApp',
    'testLoadPhpConfigWithoutApplicationYaml',
    'testCanonicalPathIsCapitalConfig',
    'testLoadPhpConfigWithoutPathContextReturnsEmpty',
];

$passed = 0;
foreach ($tests as $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} ApplicationConfig tests passed.\n";
