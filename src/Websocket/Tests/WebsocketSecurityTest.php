<?php
/**
 * WebSocket security tests (auth / group join / server_id / pushToUser).
 *
 * Run:
 *   php src/Websocket/Tests/WebsocketSecurityTest.php
 */

use Swoole\Http\Request;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisException;
use Swoolefy\Websocket\Cluster\ExternalPushPublisher;
use Swoolefy\Websocket\Group\CallableGroupJoinAuthorizer;
use Swoolefy\Websocket\Group\GroupJoinAuthorizerFactory;
use Swoolefy\Websocket\WebsocketAuthenticator;
use Swoolefy\Websocket\WebsocketConnectionManager;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

define('APP_NAME', 'WebsocketService');
define('APP_PATH', $root . '/WebsocketService');
define('WORKER_PORT', 9508);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeRequest(array $query = [], array $headers = []): Request
{
    $request = new Request();
    $request->get = $query;
    $request->header = $headers;

    return $request;
}

function testAuthRequiresUserIdWhenEnabled(): void
{
    $config = [
        'auth' => [
            'enable' => true,
            'require_user_id' => true,
            'tokens' => ['dev-token'],
        ],
    ];

    $ok = WebsocketAuthenticator::authenticate(
        makeRequest(['token' => 'dev-token']),
        $config
    );
    assertTrue(!$ok['ok'], 'auth should fail without uid');

    $ok = WebsocketAuthenticator::authenticate(
        makeRequest(['token' => 'dev-token', 'uid' => 'user-1']),
        $config
    );
    assertTrue($ok['ok'] && $ok['user_id'] === 'user-1', 'auth should pass with uid');

    echo "[OK] auth require user_id\n";
}

function testAuthCallbackReturnsUserId(): void
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
        makeRequest(['token' => 'jwt-ok']),
        $config
    );
    assertTrue($result['ok'] && $result['user_id'] === 'from-callback', 'callback user_id mismatch');

    echo "[OK] auth callback user_id\n";
}

function testGroupJoinAuthorizer(): void
{
    GroupJoinAuthorizerFactory::setOverride(new CallableGroupJoinAuthorizer(
        static function (int $fd, string $userId, string $group, array $params): ?string {
            if ($group === 'secret' && ($params['password'] ?? '') !== 'pass') {
                return 'invalid room password';
            }

            return null;
        }
    ));

    $deny = GroupJoinAuthorizerFactory::authorize(1, 'u1', 'secret', ['password' => 'bad']);
    assertTrue($deny === 'invalid room password', 'group join should be denied');

    $allow = GroupJoinAuthorizerFactory::authorize(1, 'u1', 'secret', ['password' => 'pass']);
    assertTrue($allow === null, 'group join should be allowed');

    GroupJoinAuthorizerFactory::reset();
    echo "[OK] group join authorizer\n";
}

function testPushToUserRejectsEmptyUserId(): void
{
    ClusterConfig::setWebsocketOverride([
        'cluster' => ['enable' => true, 'server_id' => 'ws-security-test'],
    ]);
    ClusterNodeIdentity::reset();

    $threw = false;
    try {
        ExternalPushPublisher::pushToUser('', 'chat.private', ['msg' => 'hi']);
    } catch (ClusterRedisException $e) {
        $threw = true;
    }
    assertTrue($threw, 'pushToUser should reject empty user_id');

    ClusterConfig::setWebsocketOverride(null);
    ClusterNodeIdentity::reset();
    echo "[OK] pushToUser rejects empty user_id\n";
}

function testClusterRequiresStableServerId(): void
{
    ClusterNodeIdentity::reset();
    ClusterConfig::setWebsocketOverride([
        'cluster' => ['enable' => true, 'server_id' => ''],
    ]);

    $threw = false;
    try {
        ClusterNodeIdentity::getServerId();
    } catch (ClusterRedisException $e) {
        $threw = true;
    }
    assertTrue($threw, 'cluster mode should require explicit server_id');

    ClusterNodeIdentity::reset();
    ClusterConfig::setWebsocketOverride([
        'cluster' => ['enable' => true, 'server_id' => 'ws-test-01'],
    ]);
    assertTrue(ClusterNodeIdentity::getServerId() === 'ws-test-01', 'configured server_id mismatch');

    ClusterNodeIdentity::reset();
    ClusterConfig::setWebsocketOverride(null);
    echo "[OK] cluster server_id validation\n";
}

testAuthRequiresUserIdWhenEnabled();
testAuthCallbackReturnsUserId();
testGroupJoinAuthorizer();
testPushToUserRejectsEmptyUserId();
testClusterRequiresStableServerId();

echo "All websocket security tests passed.\n";
