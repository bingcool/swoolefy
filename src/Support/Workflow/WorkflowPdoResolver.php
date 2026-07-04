<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use PDO;
use Swoolefy\Core\Application;
use Swoolefy\Library\Db\PDOConnection;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 从 Swoolefy 组件容器解析 PDO（供 DbRunStore 使用）。
 *
 * component 对应 Config/component/database.php 中的别名（如 db）。
 */
final class WorkflowPdoResolver
{
    public static function resolve(string $componentName): PDO
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new WorkflowException(
                'Workflow db run_store requires Swoolefy Application context (HTTP/Worker coroutine).',
            );
        }

        $container = $app->get($componentName);
        if (!is_object($container) || !method_exists($container, 'getObject')) {
            throw new WorkflowException("Workflow db component [{$componentName}] is not available.");
        }

        $connection = $container->getObject();
        if ($connection instanceof PDOConnection) {
            $pdo = $connection->getPdo();
            if ($pdo instanceof PDO) {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                return $pdo;
            }
        }

        if ($connection instanceof PDO) {
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $connection;
        }

        throw new WorkflowException(
            "Workflow db component [{$componentName}] must return " . PDOConnection::class . ' or ' . PDO::class,
        );
    }
}
