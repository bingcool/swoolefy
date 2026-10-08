<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PHPUintTest\TestCase;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Library\Jwt\Configuration;
use Swoolefy\Library\Jwt\Signer\Hmac\Sha256;
use Swoolefy\Library\Jwt\Signer\Key\InMemory;
use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\Auth\JwtAuthGuard;
use Swoolefy\Support\Auth\RoleResolverInterface;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Plugin\Builtin\PermissionPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\WorkflowBootstrap;

/**
 * 凭证与角色分开：验票不加载角色，roles() 只信 auth.role_resolver。
 */
final class AuthIdentityRoleSourceTest extends TestCase
{
    private const SECRET = 'test-auth-jwt-secret-change-me';

    protected function tearDown(): void
    {
        if (Application::issetApp()) {
            FrameworkContext::clearUser();
        }
        parent::tearDown();
    }

    public function testRolesStayUnloadedUntilFirstReadAndIgnoreTokenRoles(): void
    {
        $resolver = new MutableRoleResolver(['operator']);
        $this->withApp($resolver, function () use ($resolver): void {
            $guard = new JwtAuthGuard($this->jwtConfig());
            $user = $guard->authenticate(['token' => $this->tokenWithAdminRole()]);
            $this->assertInstanceOf(AuthUser::class, $user);
            FrameworkContext::setUser($user);

            $this->assertSame(0, $resolver->calls);
            $this->assertFalse($user->rolesResolved);
            $this->assertSame(['operator'], $user->roles());
            $this->assertFalse($user->hasRole('admin'));
            $this->assertTrue($user->hasRole('operator'));
            $this->assertSame(1, $resolver->calls);
        });
    }

    public function testNextRequestReadsResolverAgain(): void
    {
        $resolver = new MutableRoleResolver(['operator']);
        $this->withApp($resolver, function () use ($resolver): void {
            $guard = new JwtAuthGuard($this->jwtConfig());
            $token = $this->tokenWithAdminRole();
            $first = $guard->authenticate(['token' => $token]);
            FrameworkContext::setUser($first);
            $this->assertSame(['operator'], FrameworkContext::user()?->roles());

            FrameworkContext::clearUser();
            $resolver->roles = ['guest'];
            $second = $guard->authenticate(['token' => $token]);
            FrameworkContext::setUser($second);
            $this->assertSame(['guest'], $second->roles());
            $this->assertSame(2, $resolver->calls);

            $engine = new WorkflowEngine(
                plugins: new PluginManager([new PermissionPlugin(['admin'])]),
                scheduler: new DagScheduler(new \Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator()),
                runStore: new InMemoryRunStore(),
                events: new StreamWorkflowEventDispatcher(),
            );
            $compiled = WorkflowBootstrap::compiler()->compile(
                WorkflowDefinition::create('admin-only')
                    ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
            );
            try {
                $engine->start($compiled, ['role' => 'admin']);
                $this->fail('revoked admin must be denied');
            } catch (WorkflowPermissionException) {
                $this->assertSame(2, $resolver->calls);
            }
        });
    }

    public function testMissingResolverAllowsAuthOnlyAndFailsOnFirstRoleRead(): void
    {
        $this->withApp(null, function (): void {
            $guard = new JwtAuthGuard($this->jwtConfig());
            $user = $guard->authenticate(['token' => $this->tokenWithAdminRole()]);
            $this->assertInstanceOf(AuthUser::class, $user);
            FrameworkContext::setUser($user);
            $this->assertSame('7', FrameworkContext::user()?->userId);

            try {
                $user->roles();
                $this->fail('expected missing resolver');
            } catch (AuthException $e) {
                $this->assertSame(500, $e->getCode());
            }

            $open = new WorkflowEngine(
                plugins: new PluginManager([new PermissionPlugin([])]),
                scheduler: new DagScheduler(new \Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator()),
                runStore: new InMemoryRunStore(),
                events: new StreamWorkflowEventDispatcher(),
            );
            $compiled = WorkflowBootstrap::compiler()->compile(
                WorkflowDefinition::create('open-role')
                    ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
            );
            $runId = $open->start($compiled, ['role' => 'admin']);
            $this->assertNotSame('', $runId);

            $closed = new WorkflowEngine(
                plugins: new PluginManager([new PermissionPlugin(['admin'])]),
                scheduler: new DagScheduler(new \Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator()),
                runStore: new InMemoryRunStore(),
                events: new StreamWorkflowEventDispatcher(),
            );
            try {
                $closed->start($compiled, ['role' => 'admin']);
                $this->fail('restricted workflow must not trust token admin');
            } catch (AuthException $e) {
                $this->assertSame(500, $e->getCode());
            }
        });
    }

    /**
     * @return array{secret: string, algo: string, id_claim: string, roles_claim: string}
     */
    private function jwtConfig(): array
    {
        return [
            'secret' => self::SECRET,
            'algo' => 'HS256',
            'id_claim' => 'uid',
            'roles_claim' => 'roles',
        ];
    }

    private function tokenWithAdminRole(): string
    {
        $configuration = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));

        return $configuration->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->relatedTo('7')
            ->withClaim('uid', '7')
            ->withClaim('roles', ['admin'])
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    private function withApp(?RoleResolverInterface $resolver, callable $fn): void
    {
        $owned = false;
        if (!Application::issetApp()) {
            if (!defined('APP_PATH')) {
                BaseServer::setAppConf(['components' => []]);
            }
            Application::setApp(new App());
            $owned = true;
        }
        if ($resolver !== null) {
            Application::getApp()->creatObject('auth.role_resolver', static fn () => $resolver);
        }
        try {
            $fn();
        } finally {
            FrameworkContext::clearUser();
            if ($owned) {
                Application::removeApp();
            }
        }
    }
}

final class MutableRoleResolver implements RoleResolverInterface
{
    public int $calls = 0;

    /** @param list<string> $roles */
    public function __construct(public array $roles)
    {
    }

    public function currentRoles(string $userId): array
    {
        unset($userId);
        $this->calls++;

        return $this->roles;
    }
}
