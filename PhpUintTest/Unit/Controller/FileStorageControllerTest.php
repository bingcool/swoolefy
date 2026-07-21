<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * FileStorageController 本地盘 curl 全链路（需 Test 服务；未启服则 skip）。
 *
 * @see \Test\Controller\FileStorageController
 *
 * ```bash
 * curl -X POST 'http://127.0.0.1:9501/api/file-storage/put' \
 *   -H 'Content-Type: application/json' -H 'Accept: application/json' \
 *   -d '{"path":"http-test/a.txt","contents":"hello"}'
 *
 * curl -X GET 'http://127.0.0.1:9501/api/file-storage/get?path=http-test/a.txt' \
 *   -H 'Accept: application/json'
 *
 * curl -X GET 'http://127.0.0.1:9501/api/file-storage/metadata?path=http-test/a.txt' \
 *   -H 'Accept: application/json'
 *
 * curl -X GET 'http://127.0.0.1:9501/api/file-storage/exists?path=http-test/a.txt' \
 *   -H 'Accept: application/json'
 *
 * curl -X POST 'http://127.0.0.1:9501/api/file-storage/delete' \
 *   -H 'Content-Type: application/json' -H 'Accept: application/json' \
 *   -d '{"path":"http-test/a.txt"}'
 *
 * curl -X POST 'http://127.0.0.1:9501/api/file-storage/roundtrip' \
 *   -H 'Content-Type: application/json' -H 'Accept: application/json' \
 *   -d '{"contents":"roundtrip"}'
 * ```
 */
final class FileStorageControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：缺 path 时 put 返回业务 code=400。
     */
    public function testPutWithoutPathReturns400(): void
    {
        $res = $this->postJson('/api/file-storage/put', ['contents' => 'x']);
        $this->assertSame(200, $res['status']);
        $payload = $this->businessPayload($res);
        $this->assertSame(400, $payload['code'] ?? null);
        $this->assertStringContainsString('path', strtolower((string) ($payload['msg'] ?? '')));
    }

    /**
     * 验证：local 盘 put → get → metadata → exists → delete 全链路。
     */
    public function testLocalDiskPutGetMetadataExistsDeleteChain(): void
    {
        $path = 'http-test/' . uniqid('fs_', true) . '.txt';
        $contents = 'hello-file-storage-' . bin2hex(random_bytes(4));

        $put = $this->postJson('/api/file-storage/put', [
            'path' => $path,
            'contents' => $contents,
            'mime' => 'text/plain',
        ]);
        $this->assertSame(200, $put['status']);
        $putPayload = $this->businessPayload($put);
        $this->assertSame(0, $putPayload['code'] ?? null, json_encode($put['body'], JSON_UNESCAPED_UNICODE));
        $putData = $putPayload['data'] ?? [];
        $this->assertIsArray($putData);
        $this->assertSame('local', $putData['driver'] ?? null);
        $this->assertSame($path, $putData['path'] ?? null);
        $this->assertSame(strlen($contents), $putData['size'] ?? null);

        $get = $this->getJson('/api/file-storage/get?path=' . rawurlencode($path));
        $this->assertSame(200, $get['status']);
        $getPayload = $this->businessPayload($get);
        $this->assertSame(0, $getPayload['code'] ?? null);
        $getData = $getPayload['data'] ?? [];
        $this->assertIsArray($getData);
        $this->assertSame($contents, $getData['contents'] ?? null);

        $meta = $this->getJson('/api/file-storage/metadata?path=' . rawurlencode($path));
        $this->assertSame(200, $meta['status']);
        $metaPayload = $this->businessPayload($meta);
        $this->assertSame(0, $metaPayload['code'] ?? null);
        $metaData = $metaPayload['data'] ?? [];
        $this->assertIsArray($metaData);
        $this->assertSame(strlen($contents), $metaData['size'] ?? null);
        $this->assertNotEmpty($metaData['etag'] ?? null);

        $exists = $this->getJson('/api/file-storage/exists?path=' . rawurlencode($path));
        $this->assertSame(200, $exists['status']);
        $existsPayload = $this->businessPayload($exists);
        $this->assertSame(0, $existsPayload['code'] ?? null);
        $this->assertTrue(($existsPayload['data']['exists'] ?? false) === true);

        $del = $this->postJson('/api/file-storage/delete', ['path' => $path]);
        $this->assertSame(200, $del['status']);
        $delPayload = $this->businessPayload($del);
        $this->assertSame(0, $delPayload['code'] ?? null);
        $this->assertFalse(($delPayload['data']['exists'] ?? true) === true);

        $gone = $this->getJson('/api/file-storage/get?path=' . rawurlencode($path));
        $gonePayload = $this->businessPayload($gone);
        $this->assertSame(404, $gonePayload['code'] ?? null);
    }

    /**
     * 验证：roundtrip 单次请求完成 put/get/delete。
     */
    public function testRoundtripEndpoint(): void
    {
        $res = $this->postJson('/api/file-storage/roundtrip', [
            'contents' => 'roundtrip-ok',
        ]);
        $this->assertSame(200, $res['status']);
        $payload = $this->businessPayload($res);
        $this->assertSame(0, $payload['code'] ?? null, json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $data = $payload['data'] ?? [];
        $this->assertIsArray($data);
        $this->assertSame('local', $data['driver'] ?? null);
        $this->assertSame('roundtrip-ok', $data['contents'] ?? null);
        $this->assertFalse(($data['exists_after_delete'] ?? true) === true);
        $this->assertNotEmpty($data['path'] ?? null);
    }

    /**
     * 兼容控制器直接返回 {code,msg,data} 与外层信封再包一层。
     *
     * @param array{status: int, body: mixed} $res
     * @return array<string, mixed>
     */
    private function businessPayload(array $res): array
    {
        $this->assertIsArray($res['body'], 'response body must be JSON object');
        $body = $res['body'];
        if (isset($body['data']) && is_array($body['data']) && array_key_exists('code', $body['data'])) {
            return $body['data'];
        }
        if (array_key_exists('code', $body)) {
            return $body;
        }
        if (isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return $body;
    }
}
