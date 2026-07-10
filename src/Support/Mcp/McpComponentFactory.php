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

namespace Swoolefy\Support\Mcp;

use PDO;
use Swoolefy\Support\Neuron\NeuronAiConfig;

/**
 * 从 neuron_ai.php 装配 MCP 生产组件。
 *
 * DbMcpServerConfigRepository 通过 mcp.db_component 解析 PDO；
 * 生产须预执行 Schema/mcp_server_configs.sql。
 */
final class McpComponentFactory
{
    public static function pdo(?NeuronAiConfig $config = null): PDO
    {
        $config ??= NeuronAiConfig::load();

        return McpPdoResolver::resolve($config->mcpDbComponent());
    }

    public static function dbRepository(?NeuronAiConfig $config = null, ?PDO $pdo = null): DbMcpServerConfigRepository
    {
        return new DbMcpServerConfigRepository($pdo ?? self::pdo($config));
    }

    /**
     * @param array<string, array<string, mixed>> $servers 静态 MCP 配置（可选）
     */
    public static function factory(
        array $servers = [],
        ?NeuronAiConfig $config = null,
        ?McpServerConfigRepositoryInterface $repository = null,
    ): McpFactory {
        $config ??= NeuronAiConfig::load();

        return new McpFactory(
            servers: $servers,
            repository: $repository ?? self::dbRepository($config),
            processRunner: McpProcessRunner::fromEnv(),
            stdioGuard: $config->mcpStdioGuard(),
            urlGuard: $config->outboundUrlGuard(),
            config: $config,
        );
    }
}
