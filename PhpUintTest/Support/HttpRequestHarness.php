<?php

declare(strict_types=1);

namespace PhpUintTest\Support;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * 进程内 RequestInput / ResponseOutput 装配（P6）。
 *
 * 用途：Controller / Middleware 单测直接注入 {@see RequestInput}，无需真端口。
 * 非目标：不伪造完整 `HttpServer::onRequest` 流水线（路由/中间件链仍靠 HttpIntegration）。
 *
 * server 键同时写大小写，兼容 RequestParseTrait 对 REQUEST_METHOD 等的读取。
 */
final class HttpRequestHarness
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post   表单或已解析 JSON 字段（推荐；raw JSON 见 $jsonBody）
     * @param array<string, string> $headers 小写 header 名
     */
    public static function requestInput(
        string $method = 'GET',
        string $path = '/',
        array $query = [],
        array $post = [],
        array $headers = [],
        ?string $jsonBody = null,
    ): RequestInput {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        if ($path === '/') {
            $pathInfo = '/';
        } else {
            $pathInfo = $path;
        }

        $request = new SwooleRequest();
        $headerBag = array_change_key_case($headers, CASE_LOWER);
        if ($jsonBody !== null && $jsonBody !== '') {
            $headerBag['content-type'] = $headerBag['content-type'] ?? 'application/json';
        }
        $request->header = $headerBag;
        $request->server = [
            'request_method' => $method,
            'REQUEST_METHOD' => $method,
            'request_uri' => $path,
            'REQUEST_URI' => $path,
            'path_info' => $pathInfo,
            'PATH_INFO' => $pathInfo,
            'server_protocol' => 'HTTP/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];
        $request->get = $query;
        $request->post = $post;

        if ($jsonBody !== null && $jsonBody !== '') {
            // Swoole Request::rawContent() 在无 fd 时为空；把 JSON 字段并入 post，
            // 与 RequestParseTrait::getRequestParams 的 merge 行为对齐。
            $decoded = json_decode($jsonBody, true);
            if (is_array($decoded)) {
                $request->post = array_merge($request->post ?? [], $decoded);
            }
        }

        return new RequestInput($request, new SwooleResponse());
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $headers
     */
    public static function responseOutput(
        string $method = 'GET',
        string $path = '/',
        array $query = [],
        array $post = [],
        array $headers = [],
    ): ResponseOutput {
        $input = self::requestInput($method, $path, $query, $post, $headers);

        return new ResponseOutput($input->getSwooleRequest(), $input->getSwooleResponse());
    }
}
