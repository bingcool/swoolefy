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

namespace Swoolefy\Support\Neuron;

/**
 * Neuron MCP 相关环境变量（从 .env 经 env() 读取）。
 */
final class NeuronAiMcpEnv
{
    /** MCP 配置仓储使用的 database.php 组件别名。 */
    public const DATABASE_COMPONENT = 'MCP_DATABASE_COMPONENT';
}
