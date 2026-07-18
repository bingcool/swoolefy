<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket;

use Swoole\Http\Request;
use PhpUintTest\TestCase;
use PhpUintTest\Websocket\Support\WebsocketAppConstants;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisException;
use Swoolefy\Websocket\Cluster\ExternalPushPublisher;
use Swoolefy\Websocket\Group\CallableGroupJoinAuthorizer;
use Swoolefy\Websocket\Group\GroupJoinAuthorizerFactory;
use Swoolefy\Websocket\WebsocketAuthenticator;

/**
 * WebSocket 安全相关单元测试（鉴权 / 加组 / server_id / pushToUser）。
 */
final class WebsocketSecurityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        WebsocketAppConstants::ensure();
    }

    protected function tearDown(): void
    {
        GroupJoinAuthorizerFactory::reset();
        ClusterConfig::setWebsocketOverride(null);
        ClusterNodeIdentity::reset();
        parent::tearDown();
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $headers */
    private function makeRequest(array $query = [], array $headers = []): Request
    {
        $request = new Request();
        $request->get = $query;
        $request->header = $headers;

        return $request;
    }

    public function testAuthRequiresUserIdWhenEnabled(): void
    {
        $config = [
            'auth' => [
                'enable' => true,
                'require_user_id' => true,
                'tokens' => ['dev-token'],
            ],
        ];

        $ok = WebsocketAuthenticator::authenticate(
            $this->makeRequest(['token' => 'dev-token']),
            $config
        );
        $this->assertFalse($ok['ok']);

        $ok = WebsocketAuthenticator::authenticate(
            $this->makeRequest(['token' => 'dev-token', 'uid' => 'user-1']),
            $config
        );
        $this->assertTrue($ok['ok']);
        $this->assertSame('user-1', $ok['user_id']);
    }

    public function testAuthCallbackReturnsUserId(): void
    {
        $config = [
            'auth' => [
                'enable' => true,
                'callback' => static function (Request $request, string $token) {
                    if ($token !== 'jwt-ok') {
                        return false;
                    }

                    return ['user_id' => 'from-callback'];
                },
            ],
        ];

        $result = WebsocketAuthenticator::authenticate(
            $this->makeRequest(['token' => 'jwt-ok']),
            $config
        );
        $this->assertTrue($result['ok']);
        $this->assertSame('from-callback', $result['user_id']);
    }

    public function testGroupJoinAuthorizer(): void
    {
        GroupJoinAuthorizerFactory::setOverride(new CallableGroupJoinAuthorizer(
            static function (int $fd, string $userId, string $group, array $params): ?string {
                if ($group === 'secret' && ($params['password'] ?? '') !== 'pass') {
                    return 'invalid room password';
                }

                return null;
            }
        ));

        $this->assertSame(
            'invalid room password',
            GroupJoinAuthorizerFactory::authorize(1, 'u1', 'secret', ['password' => 'bad'])
        );
        $this->assertNull(GroupJoinAuthorizerFactory::authorize(1, 'u1', 'secret', ['password' => 'pass']));
    }

    public function testPushToUserRejectsEmptyUserId(): void
    {
        ClusterConfig::setWebsocketOverride([
            'cluster' => ['enable' => true, 'server_id' => 'ws-security-test'],
        ]);
        ClusterNodeIdentity::reset();

        $this->expectException(ClusterRedisException::class);
        ExternalPushPublisher::pushToUser('', 'chat.private', ['msg' => 'hi']);
    }

    public function testClusterRequiresStableServerId(): void
    {
        ClusterNodeIdentity::reset();
        ClusterConfig::setWebsocketOverride([
            'cluster' => ['enable' => true, 'server_id' => ''],
        ]);

        try {
            ClusterNodeIdentity::getServerId();
            $this->fail('cluster mode should require explicit server_id');
        } catch (ClusterRedisException $e) {
            $this->assertInstanceOf(ClusterRedisException::class, $e);
        }

        ClusterNodeIdentity::reset();
        ClusterConfig::setWebsocketOverride([
            'cluster' => ['enable' => true, 'server_id' => 'ws-test-01'],
        ]);
        $this->assertSame('ws-test-01', ClusterNodeIdentity::getServerId());
    }
}
