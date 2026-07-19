<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Script;

use PhpUintTest\TestCase;
use Swoolefy\Script\ApiDoc\ApiDocGenerator;
use Symfony\Component\Yaml\Yaml;

/**
 * gen:apidoc OpenAPI DX：GET→query、默认 400、鉴权 401、文案不重复。
 */
final class ApiDocGeneratorTest extends TestCase
{
    private string $fixtureRoot = '';

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== '' && is_dir($this->fixtureRoot)) {
            $this->removeDir($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function testGetBaseRequestBecomesQueryAndHasValidationResponse(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/swoolefy_apidoc_' . uniqid('', true);
        $app = $this->fixtureRoot . '/DemoApp';
        $router = $app . '/Router/Api';
        $out = $this->fixtureRoot . '/out';
        mkdir($router, 0755, true);
        mkdir($app . '/Controller', 0755, true);
        mkdir($app . '/Request', 0755, true);

        file_put_contents($app . '/Router/api_router_module.json', json_encode([
            'Api' => [
                'title' => 'Fixture API',
                'description' => 'fixture module',
            ],
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents($app . '/Request/ShowUserRequest.php', <<<'PHP'
<?php
namespace DemoApp\Request;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;
class ShowUserRequest extends BaseRequest
{
    #[ApiProperty(description: '用户 ID')]
    #[ValidationRule(rule: 'required|int')]
    protected int $userId = 0;
}
PHP);

        file_put_contents($app . '/Controller/UserController.php', <<<'PHP'
<?php
namespace DemoApp\Controller;
use Swoolefy\Annotation\ApiOperation;
use DemoApp\Request\ShowUserRequest;
class UserController
{
    #[ApiOperation(description: '查询用户')]
    public function show(ShowUserRequest $request): array
    {
        return [];
    }

    #[ApiOperation(summary: '创建用户', description: '需管理员')]
    public function create(ShowUserRequest $request): array
    {
        return [];
    }
}
PHP);

        file_put_contents($router . '/User.php', <<<'PHP'
<?php
namespace DemoApp\Router;
use Swoolefy\Http\Middleware\AuthenticateMiddleware;
use Swoolefy\Http\Middleware\ApiRateLimiterMiddleware;
use Swoolefy\Http\Route;
use DemoApp\Controller\UserController;

Route::group([
    'prefix' => 'api',
    'middleware' => [],
], function () {
    Route::get('/users/show', [
        'beforeHandle' => AuthenticateMiddleware::class,
        'dispatch_route' => [UserController::class, 'show'],
    ])->withRateLimiterMiddleware(ApiRateLimiterMiddleware::class, 10, 60);

    Route::post('/users', [
        'dispatch_route' => [UserController::class, 'create'],
    ]);
});
PHP);

        require_once $app . '/Request/ShowUserRequest.php';
        require_once $app . '/Controller/UserController.php';

        (new ApiDocGenerator($this->fixtureRoot, $app . '/Router', $out))->run();

        $yamlFile = $out . '/openapi-api.yaml';
        $this->assertFileExists($yamlFile);
        $doc = Yaml::parseFile($yamlFile);
        $this->assertIsArray($doc);
        $this->assertSame('Fixture API', $doc['info']['title'] ?? null);
        $this->assertSame('fixture module', $doc['info']['description'] ?? null);

        $get = $doc['paths']['/api/users/show']['get'] ?? null;
        $this->assertIsArray($get);
        $this->assertSame('查询用户', $get['summary'] ?? null);
        $this->assertArrayNotHasKey('description', $get);
        $this->assertArrayNotHasKey('requestBody', $get);
        $this->assertIsArray($get['parameters'] ?? null);
        $this->assertSame('userId', $get['parameters'][0]['name'] ?? null);
        $this->assertSame('query', $get['parameters'][0]['in'] ?? null);
        $this->assertTrue($get['parameters'][0]['required'] ?? false);
        $this->assertArrayHasKey('400', $get['responses'] ?? []);
        $this->assertArrayHasKey('401', $get['responses'] ?? []);
        $this->assertArrayHasKey('429', $get['responses'] ?? []);

        $post = $doc['paths']['/api/users']['post'] ?? null;
        $this->assertIsArray($post);
        $this->assertSame('创建用户', $post['summary'] ?? null);
        $this->assertSame('需管理员', $post['description'] ?? null);
        $this->assertArrayHasKey('requestBody', $post);
        $this->assertArrayNotHasKey('401', $post['responses'] ?? []);
    }

    public function testDefaultTitleUsesAppModuleTemplate(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/swoolefy_apidoc_' . uniqid('', true);
        $app = $this->fixtureRoot . '/DemoApp';
        $router = $app . '/Router/Bare';
        $out = $this->fixtureRoot . '/out';
        mkdir($router, 0755, true);
        mkdir($app . '/Controller', 0755, true);

        file_put_contents($app . '/Controller/PingController.php', <<<'PHP'
<?php
namespace DemoApp\Controller;
class PingController
{
    public function ping(): array
    {
        return [];
    }
}
PHP);

        file_put_contents($router . '/Ping.php', <<<'PHP'
<?php
namespace DemoApp\Router;
use Swoolefy\Http\Route;
use DemoApp\Controller\PingController;
Route::get('/ping', [
    'dispatch_route' => [PingController::class, 'ping'],
]);
PHP);

        require_once $app . '/Controller/PingController.php';
        (new ApiDocGenerator($this->fixtureRoot, $app . '/Router', $out))->run();

        $doc = Yaml::parseFile($out . '/openapi-bare.yaml');
        $this->assertSame('DemoApp · Bare', $doc['info']['title'] ?? null);
        $this->assertSame('Generated by swoolefy gen:apidoc', $doc['info']['description'] ?? null);
        $this->assertSame('GET /ping', $doc['paths']['/ping']['get']['summary'] ?? null);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
