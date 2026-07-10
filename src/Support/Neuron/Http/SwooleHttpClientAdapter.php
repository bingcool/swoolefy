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

namespace Swoolefy\Support\Neuron\Http;

use GuzzleHttp\HandlerStack;
use NeuronAI\HttpClient\GuzzleHttpClient;
use Swoolefy\Library\CurlProxy\CurlProxyHandler;

/**
 * Neuron HTTP 客户端 —— 注入 Swoolefy CurlProxy 协程 Handler。
 *
 * 用于 LLM Provider、Embedding、Meilisearch、远程 MCP 等出站请求，
 * 在 Swoole Worker 内走协程化 Guzzle，避免阻塞进程。
 *
 * @see docs/SwoolefyAI.md §8.2 Neuron/Http/SwooleHttpClientAdapter
 */
final class SwooleHttpClientAdapter extends GuzzleHttpClient
{
    /**
     * @param array<string, string> $customHeaders 默认请求头
     * @param float                 $timeout         请求超时秒
     * @param float                 $connectTimeout  连接超时秒
     */
    public function __construct(
        array $customHeaders = [],
        float $timeout = 120.0,
        float $connectTimeout = 30.0,
    ) {
        $stack = HandlerStack::create(CurlProxyHandler::getStackHandler());
        CurlProxyHandler::applyPsr7CompatiblePrepareBody($stack);
        parent::__construct(
            customHeaders: $customHeaders,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            handler: $stack,
        );
    }
}
