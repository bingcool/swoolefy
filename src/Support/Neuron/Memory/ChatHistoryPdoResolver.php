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

namespace Swoolefy\Support\Neuron\Memory;

use PDO;
use RuntimeException;
use Swoolefy\Core\Application;
use Swoolefy\Library\Db\PDOConnection;

/**
 * 从 Swoolefy 组件容器解析 PDO，供 SQLChatHistory / SqlChatHistoryArchive 使用。
 */
final class ChatHistoryPdoResolver
{
    public static function resolve(string $componentName = 'db'): PDO
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new RuntimeException('Chat history PDO requires Swoolefy Application context.');
        }

        $container = $app->get($componentName);
        if (!is_object($container) || !method_exists($container, 'getObject')) {
            throw new RuntimeException("Database component [{$componentName}] is not available.");
        }

        $connection = $container->getObject();
        if ($connection instanceof PDOConnection) {
            $pdo = $connection->getPdo() ?? $connection->connect();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }

        if ($connection instanceof PDO) {
            return $connection;
        }

        throw new RuntimeException(
            "Database component [{$componentName}] must return " . PDOConnection::class . ' or ' . PDO::class,
        );
    }
}
