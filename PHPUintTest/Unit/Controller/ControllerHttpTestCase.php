<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Controller;

use PHPUintTest\Http\HttpIntegrationTestCase;

/**
 * Test\Controller curl 黄金路径基类。
 *
 * 物理目录在 Unit/Controller；suite 归 http。
 * 默认把每次请求的 HTTP status / body 打到 STDOUT（PhpStorm Run 可见）。
 * 关闭：`SWOOLEFY_HTTP_DUMP=0`
 */
abstract class ControllerHttpTestCase extends HttpIntegrationTestCase
{
    private const BODY_DUMP_MAX = 2000;

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: mixed, headers: array<string, list<string>>}
     */
    protected function getJson(string $path, array $headers = []): array
    {
        $res = parent::getJson($path, $headers);
        $this->dumpHttpResponse('GET', $path, $res);

        return $res;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, body: mixed, headers: array<string, list<string>>}
     */
    protected function postJson(string $path, array $body = [], array $headers = []): array
    {
        $res = parent::postJson($path, $body, $headers);
        $this->dumpHttpResponse('POST', $path, $res);

        return $res;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    protected function getRaw(string $path, array $headers = []): array
    {
        $res = parent::getRaw($path, $headers);
        $this->dumpHttpResponse('GET', $path, $res);

        return $res;
    }

    /**
     * @param array<string, mixed> $multipart
     * @param array<string, string> $headers
     * @return array{status: int, body: mixed, headers: array<string, list<string>>}
     */
    protected function postMultipart(string $path, array $multipart = [], array $headers = []): array
    {
        $res = parent::postMultipart($path, $multipart, $headers);
        $this->dumpHttpResponse('POST', $path, $res);

        return $res;
    }

    /**
     * @param array{status: int, body: mixed, headers?: array<string, list<string>>} $res
     */
    protected function dumpHttpResponse(string $method, string $path, array $res): void
    {
        $enabled = getenv('SWOOLEFY_HTTP_DUMP');
        if ($enabled === false) {
            $enabled = '1';
        }
        if (!filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $body = $res['body'] ?? null;
        if (is_array($body)) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $text = is_string($encoded) ? $encoded : var_export($body, true);
        } else {
            $text = (string) $body;
        }

        if (strlen($text) > self::BODY_DUMP_MAX) {
            $text = substr($text, 0, self::BODY_DUMP_MAX) . "\n…(truncated, total " . strlen((string) $body) . " bytes)";
        }

        $test = $this->name();
        fwrite(STDOUT, "\n──── HTTP {$method} {$path}  [{$test}]\n");
        fwrite(STDOUT, 'status: ' . (string) ($res['status'] ?? '') . "\n");
        fwrite(STDOUT, "body:\n{$text}\n");
        fwrite(STDOUT, "────\n");
    }
}
