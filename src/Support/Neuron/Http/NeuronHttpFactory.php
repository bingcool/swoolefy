<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Http;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use Swoolefy\Support\Neuron\NeuronAiConfig;

/**
 * Neuron HTTP 客户端工厂 —— 按 env / neuron_ai.php 选择 Swoole 协程或 Guzzle。
 *
 * 环境变量 {@see self::ENV_HTTP_CLIENT}={@see self::CLIENT_SWOOLE}|{@see self::CLIENT_GUZZLE}，默认 swoole。
 */
final class NeuronHttpFactory
{
    /** 环境变量名：HTTP 客户端驱动 */
    public const ENV_HTTP_CLIENT = 'NEURON_HTTP_CLIENT';

    /** Swoole 协程 HTTP（CurlProxy） */
    public const CLIENT_SWOOLE = 'swoole';

    /** Guzzle HTTP 客户端 */
    public const CLIENT_GUZZLE = 'guzzle';

    /** 创建 HTTP 客户端实例（每次新建，无全局单例）。 */
    public static function create(): HttpClientInterface
    {
        $driver = strtolower(NeuronAiConfig::load()->httpClient());

        if ($driver === self::CLIENT_GUZZLE) {
            return new GuzzleHttpClient();
        }

        return new SwooleHttpClientAdapter();
    }
}
