<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Resolver;

use PDO;
use RuntimeException;
use Swoolefy\Core\Application;
use Swoolefy\Library\Db\PDOConnection;

/**
 * 从 Swoolefy 组件容器解析 PDO（供 MariaDB VectorStore 使用）。
 */
final class RagPdoResolver
{
    public static function resolve(string $componentName): PDO
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new RuntimeException(
                'MariaDB vector store requires Swoolefy Application context (HTTP/Worker coroutine).',
            );
        }

        $container = $app->get($componentName);
        if (!is_object($container) || !method_exists($container, 'getObject')) {
            throw new RuntimeException("RAG mariadb component [{$componentName}] is not available.");
        }

        $connection = $container->getObject();
        if ($connection instanceof PDOConnection) {
            $pdo = $connection->getPdo();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }

        if ($connection instanceof PDO) {
            return $connection;
        }

        throw new RuntimeException(
            "RAG mariadb component [{$componentName}] must return " . PDOConnection::class . ' or ' . PDO::class,
        );
    }
}
