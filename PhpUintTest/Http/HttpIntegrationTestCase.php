<?php

declare(strict_types=1);

namespace PhpUintTest\Http;

use GuzzleHttp\Client;
use PhpUintTest\Http\Support\HttpServerManager;
use PhpUintTest\Http\Support\HttpServerUnavailableException;
use PhpUintTest\TestCase;

/**
 * Http 全流程基类：真服务 + Guzzle。
 * 靠 suite「http」隔离，勿标会被默认 exclude 的 @group http。
 */
abstract class HttpIntegrationTestCase extends TestCase
{
    protected static Client $http;

    protected static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$baseUrl = rtrim(
            (string) (getenv('SWOOLEFY_TEST_BASE_URL') ?: 'http://127.0.0.1:9501'),
            '/',
        );
        try {
            HttpServerManager::ensureAvailable(self::$baseUrl);
        } catch (HttpServerUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }
        self::$http = new Client([
            'base_uri' => self::$baseUrl . '/',
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: mixed, headers: array<string, list<string>>}
     */
    protected function getJson(string $path, array $headers = []): array
    {
        $res = self::$http->get(ltrim($path, '/'), [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ]);
        $raw = (string) $res->getBody();
        $json = json_decode($raw, true);

        return [
            'status' => $res->getStatusCode(),
            'body' => is_array($json) ? $json : $raw,
            'headers' => $res->getHeaders(),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, body: mixed, headers: array<string, list<string>>}
     */
    protected function postJson(string $path, array $body = [], array $headers = []): array
    {
        $res = self::$http->post(ltrim($path, '/'), [
            'headers' => array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers),
            'json' => $body,
        ]);
        $raw = (string) $res->getBody();
        $json = json_decode($raw, true);

        return [
            'status' => $res->getStatusCode(),
            'body' => is_array($json) ? $json : $raw,
            'headers' => $res->getHeaders(),
        ];
    }

    /**
     * 解包框架信封 `{ code, msg, data }`；无 data 时退回整包 body。
     *
     * @param array{status: int, body: mixed, headers: array<string, list<string>>} $res
     * @return array<string, mixed>
     */
    protected function responseData(array $res): array
    {
        $this->assertIsArray($res['body'], 'response body must be JSON object');
        $body = $res['body'];
        if (isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return $body;
    }
}
