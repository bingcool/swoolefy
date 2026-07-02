<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Http;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;

/**
 * Neuron HTTP 客户端工厂 —— 按 env 选择 Swoole 协程或默认 Guzzle。
 *
 * 环境变量 NEURON_HTTP_CLIENT=swoole|guzzle，默认 swoole（有 CurlProxy 时）。
 */
final class NeuronHttpFactory
{
    /** 创建 HTTP 客户端实例（每次新建，无全局单例）。 */
    public static function create(): HttpClientInterface
    {
        $driver = strtolower((string) (getenv('NEURON_HTTP_CLIENT') ?: 'swoole'));

        if ($driver === 'guzzle') {
            return new GuzzleHttpClient();
        }

        return new SwooleHttpClientAdapter();
    }
}
