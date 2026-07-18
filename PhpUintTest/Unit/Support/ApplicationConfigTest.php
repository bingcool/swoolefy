<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Support;

use Swoolefy\Support\ApplicationConfig;
use PhpUintTest\TestCase;

/**
 * ApplicationConfig 配置路径与 yaml 闸门回归。
 */
final class ApplicationConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/src/Support/Tests/SwoolefyTestBootstrap.php';
    }

    public function testResolveConfigPathUsesConfigConstant(): void
    {
        $path = ApplicationConfig::resolveConfigPath();
        $this->assertSame(rtrim((string) CONFIG_PATH, '/'), $path);
        $this->assertTrue(str_ends_with($path, '/Config'));
    }

    public function testLoadPhpConfigFromTestApp(): void
    {
        $workflow = ApplicationConfig::loadPhpConfig('workflow.php');
        $this->assertArrayHasKey('workflow', $workflow);
        $this->assertArrayHasKey('default_run_store', (array) ($workflow['workflow'] ?? []));

        $neuron = ApplicationConfig::loadPhpConfig('neuron_ai.php');
        $this->assertTrue($neuron !== [] || is_file(CONFIG_PATH . '/neuron_ai.php') === false);
        if (is_file(CONFIG_PATH . '/neuron_ai.php')) {
            $this->assertNotSame([], $neuron);
        }

        $job = ApplicationConfig::loadPhpConfig('job.php');
        if (is_file(CONFIG_PATH . '/job.php')) {
            $this->assertNotSame([], $job);
        }

        $this->assertSame([], ApplicationConfig::loadPhpConfig('definitely_missing_xyz.php'));
    }

    public function testLoadPhpConfigWithoutApplicationYaml(): void
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmpRoot = sys_get_temp_dir() . '/swoolefy_cfg_' . bin2hex(random_bytes(4));
        $configDir = $tmpRoot . '/Config';
        $this->assertTrue(@mkdir($configDir, 0777, true) || is_dir($configDir));

        $php = <<<'PHP'
<?php
return ['demo' => ['enabled' => true, 'name' => 'no-yaml']];
PHP;
        file_put_contents($configDir . '/demo.php', $php);

        $script = <<<'PHP'
<?php
require $argv[1];
define('APP_PATH', $argv[2]);
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

        @unlink($configDir . '/demo.php');
        @unlink($scriptFile);
        @rmdir($configDir);
        @rmdir($tmpRoot);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testCanonicalPathIsCapitalConfig(): void
    {
        $path = ApplicationConfig::resolveConfigPath();
        $this->assertFalse(str_ends_with($path, '/config'));
        $this->assertTrue(str_ends_with($path, '/Config'));
    }

    public function testPickStringEnvFirstPreservesBoolFalse(): void
    {
        $picked = ApplicationConfig::pickStringEnvFirst(
            ['auth_enabled' => false],
            'auth_enabled',
            'SWOOLEFY_TEST_HITL_AUTH_ENABLED_UNSET_' . bin2hex(random_bytes(4)),
            '1',
        );
        $this->assertSame('0', $picked);
        $this->assertFalse(filter_var($picked, FILTER_VALIDATE_BOOLEAN));
    }

    public function testLoadPhpConfigWithoutPathContextReturnsEmpty(): void
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

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
