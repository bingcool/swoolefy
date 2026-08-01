<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Script;

use PHPUintTest\TestCase;
use Swoolefy\Script\ApiDoc\ApiDocGenerator;
use Symfony\Component\Yaml\Yaml;

/**
 * ApiDocGenerator（cli `gen:apidoc`）OpenAPI 生成行为的集成向单测。
 *
 * 不 mock Generator 内部私有方法，而是在系统临时目录搭一套最小「假应用」：
 * Router + Controller + Request +（可选）api_router_module.json，再调用
 * {@see ApiDocGenerator::run()}，断言产出的 `openapi-{module}.yaml`。
 *
 * ## 覆盖范围（DX 约定）
 * | 区域 | 要点 |
 * |------|------|
 * | GET/HEAD/DELETE 入参 | BaseRequest 字段进 `parameters`（in=query），无 requestBody |
 * | POST/PUT 等 | BaseRequest 进 requestBody（application/json） |
 * | 校验失败 | 有 ValidationRule 时默认带 400 响应 |
 * | 鉴权中间件 | 路由挂 AuthenticateMiddleware 时带 401 |
 * | 限流中间件 | withRateLimiterMiddleware 时带 429 |
 * | ApiOperation 文案 | summary / description 去重（仅 description 时作 summary，不重复写 description） |
 * | 模块元数据 | api_router_module.json 的 title/description 优先 |
 * | 默认标题 | 无元数据时用 `{app} · {module}`；无注解时 summary 为 `METHOD /path` |
 *
 * ## 夹具约定
 * - `$fixtureRoot`：临时根；`DemoApp` 为应用目录（类名空间 DemoApp\...）
 * - `$app/Router/{Module}/`：模块名取自子目录名（如 Api → openapi-api.yaml）
 * - tearDown 递归删除临时目录，避免残留污染后续用例
 *
 * @see \Swoolefy\Script\ApiDoc\ApiDocGenerator
 */
final class ApiDocGeneratorTest extends TestCase
{
    /** 当前用例创建的临时目录；tearDown 时若非空则整树删除 */
    private string $fixtureRoot = '';

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== '' && is_dir($this->fixtureRoot)) {
            $this->removeDir($this->fixtureRoot);
        }
        parent::tearDown();
    }

    /**
     * 主路径：带注解的 GET + POST，校验 query/body、响应码与文案去重。
     *
     * 夹具结构：
     * - Router/Api/User.php：GET `/api/users/show`（Authenticate + RateLimiter）、POST `/api/users`
     * - ShowUserRequest：ApiProperty + ValidationRule(required|int) 的 userId
     * - UserController::show 仅 description；::create 同时有 summary + description
     * - api_router_module.json：模块 title/description 覆盖默认文案
     *
     * 断言要点：
     * 1. 输出文件 openapi-api.yaml，info 来自模块元数据
     * 2. GET：summary=「查询用户」、无 description、无 requestBody；userId 为 query 且 required；responses 含 400/401/429
     * 3. POST：summary/description 均保留；有 requestBody；无鉴权中间件故无 401
     */
    public function testGetBaseRequestBecomesQueryAndHasValidationResponse(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/swoolefy_apidoc_' . uniqid('', true);
        $app = $this->fixtureRoot . '/DemoApp';
        $router = $app . '/Router/Api';
        $out = $this->fixtureRoot . '/out';
        mkdir($router, 0755, true);
        mkdir($app . '/Controller', 0755, true);
        mkdir($app . '/Request', 0755, true);

        // 模块级 OpenAPI info（优先于全局配置与默认模板）
        file_put_contents($app . '/Router/api_router_module.json', json_encode([
            'Api' => [
                'title' => 'Fixture API',
                'description' => 'fixture module',
            ],
        ], JSON_UNESCAPED_UNICODE));

        // GET 会把该 Request 摊成 query parameters；ValidationRule 触发默认 400
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

        // Generator 用 Reflection，需类已加载
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
        // 仅有 description 时提升为 summary，且不再写重复 description
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
        // POST 路由未挂 AuthenticateMiddleware
        $this->assertArrayNotHasKey('401', $post['responses'] ?? []);
    }

    /**
     * 无 api_router_module.json、无 ApiOperation 时的默认文案。
     *
     * 夹具：Router/Bare/Ping.php 仅注册 GET /ping，Controller 无注解。
     *
     * 断言：
     * - title = `{应用目录名} · {模块名}` → `DemoApp · Bare`
     * - description = 固定默认句 `Generated by swoolefy gen:apidoc`
     * - operation summary = `GET /ping`（无法从注解解析时的回退）
     * - 输出文件名为模块名小写：openapi-bare.yaml
     */
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

    /**
     * 递归删除临时夹具目录（先删文件再删空目录）。
     *
     * 使用 @ 抑制偶发权限/竞态告警；目录不存在时直接返回。
     */
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
