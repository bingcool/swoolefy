<?php

declare(strict_types=1);

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Library\FileStorageSystem\Exception\FileStorageException;
use Swoolefy\Library\FileStorageSystem\FileDisk;
use Swoolefy\Library\FileStorageSystem\FileStorageManager;

/**
 * FileStorageSystem 本地盘 HTTP Demo（curl 黄金路径）。
 *
 * 默认使用 Config/file_storage_system.php 的 local provider。
 */
class FileStorageController extends BController
{
    /**
     * 写入对象到本地盘。
     *
     * Route: POST /api/file-storage/put
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/file-storage/put' \
       -H 'Content-Type: application/json' -H 'Accept: application/json' \
       -d '{"path":"demo/hello.txt","contents":"hello local"}'
     ```
     */
    #[ApiOperation(description: 'FileStorage local putObject')]
    public function put(RequestInput $requestInput): array
    {
        $path = trim((string) $requestInput->input('path', ''));
        $contents = (string) $requestInput->input('contents', '');
        if ($path === '') {
            return ['code' => 400, 'msg' => 'path is required'];
        }

        try {
            $disk = $this->localDisk();
            $disk->putObject($path, $contents, [
                'mime' => (string) $requestInput->input('mime', 'text/plain'),
            ]);
            $meta = $disk->getMetadata($path);
        } catch (FileStorageException $e) {
            return ['code' => 500, 'msg' => $e->getMessage()];
        }

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'driver' => $disk->driver(),
                'path' => $path,
                'size' => $meta->size,
                'mime' => $meta->mime,
                'etag' => $meta->etag,
            ],
        ];
    }

    /**
     * 读取对象内容。
     *
     * Route: GET /api/file-storage/get?path=demo/hello.txt
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/file-storage/get?path=demo/hello.txt' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: 'FileStorage local getObject')]
    public function get(RequestInput $requestInput): array
    {
        $path = trim((string) $requestInput->input('path', ''));
        if ($path === '') {
            return ['code' => 400, 'msg' => 'path is required'];
        }

        try {
            $disk = $this->localDisk();
            if (!$disk->fileExists($path)) {
                return ['code' => 404, 'msg' => 'object not found', 'data' => ['path' => $path]];
            }
            $contents = $disk->getObject($path);
        } catch (FileStorageException $e) {
            return ['code' => 500, 'msg' => $e->getMessage()];
        }

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'driver' => $disk->driver(),
                'path' => $path,
                'contents' => $contents,
            ],
        ];
    }

    /**
     * 对象元数据。
     *
     * Route: GET /api/file-storage/metadata?path=demo/hello.txt
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/file-storage/metadata?path=demo/hello.txt' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: 'FileStorage local getMetadata')]
    public function metadata(RequestInput $requestInput): array
    {
        $path = trim((string) $requestInput->input('path', ''));
        if ($path === '') {
            return ['code' => 400, 'msg' => 'path is required'];
        }

        try {
            $disk = $this->localDisk();
            if (!$disk->fileExists($path)) {
                return ['code' => 404, 'msg' => 'object not found', 'data' => ['path' => $path]];
            }
            $meta = $disk->getMetadata($path);
        } catch (FileStorageException $e) {
            return ['code' => 500, 'msg' => $e->getMessage()];
        }

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'driver' => $disk->driver(),
                'path' => $path,
                'size' => $meta->size,
                'mime' => $meta->mime,
                'etag' => $meta->etag,
                'checksum' => $meta->checksum,
                'created' => $meta->created,
                'modified' => $meta->modified,
            ],
        ];
    }

    /**
     * 对象是否存在。
     *
     * Route: GET /api/file-storage/exists?path=demo/hello.txt
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/file-storage/exists?path=demo/hello.txt' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: 'FileStorage local fileExists')]
    public function exists(RequestInput $requestInput): array
    {
        $path = trim((string) $requestInput->input('path', ''));
        if ($path === '') {
            return ['code' => 400, 'msg' => 'path is required'];
        }

        try {
            $disk = $this->localDisk();
            $exists = $disk->fileExists($path);
        } catch (FileStorageException $e) {
            return ['code' => 500, 'msg' => $e->getMessage()];
        }

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'driver' => $disk->driver(),
                'path' => $path,
                'exists' => $exists,
            ],
        ];
    }

    /**
     * 删除对象。
     *
     * Route: POST /api/file-storage/delete
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/file-storage/delete' \
       -H 'Content-Type: application/json' -H 'Accept: application/json' \
       -d '{"path":"demo/hello.txt"}'
     ```
     */
    #[ApiOperation(description: 'FileStorage local delete')]
    public function delete(RequestInput $requestInput): array
    {
        $path = trim((string) $requestInput->input('path', ''));
        if ($path === '') {
            return ['code' => 400, 'msg' => 'path is required'];
        }

        try {
            $disk = $this->localDisk();
            $disk->delete($path);
            $exists = $disk->fileExists($path);
        } catch (FileStorageException $e) {
            return ['code' => 500, 'msg' => $e->getMessage()];
        }

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'driver' => $disk->driver(),
                'path' => $path,
                'exists' => $exists,
            ],
        ];
    }

    /**
     * 一站式：put → get → metadata → delete（便于单次 curl 冒烟）。
     *
     * Route: POST /api/file-storage/roundtrip
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/file-storage/roundtrip' \
       -H 'Content-Type: application/json' -H 'Accept: application/json' \
       -d '{"path":"demo/roundtrip.txt","contents":"roundtrip"}'
     ```
     */
    #[ApiOperation(description: 'FileStorage local put/get/metadata/delete roundtrip')]
    public function roundtrip(RequestInput $requestInput): array
    {
        $path = trim((string) $requestInput->input('path', ''));
        $contents = (string) $requestInput->input('contents', 'roundtrip');
        if ($path === '') {
            $path = 'demo/roundtrip-' . bin2hex(random_bytes(4)) . '.txt';
        }

        try {
            $disk = $this->localDisk();
            $disk->putObject($path, $contents, ['mime' => 'text/plain']);
            $read = $disk->getObject($path);
            $meta = $disk->getMetadata($path);
            $disk->delete($path);
            $existsAfter = $disk->fileExists($path);
        } catch (FileStorageException $e) {
            return ['code' => 500, 'msg' => $e->getMessage()];
        }

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'driver' => $disk->driver(),
                'path' => $path,
                'contents' => $read,
                'size' => $meta->size,
                'mime' => $meta->mime,
                'etag' => $meta->etag,
                'exists_after_delete' => $existsAfter,
            ],
        ];
    }

    private function localDisk(): FileDisk
    {
        return $this->manager()->disk('local');
    }

    private function manager(): FileStorageManager
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new FileStorageException('Application is not available');
        }

        $component = $app->get('file_storage');
        if (is_object($component) && method_exists($component, 'getObject')) {
            $component = $component->getObject();
        }
        if (!$component instanceof FileStorageManager) {
            throw new FileStorageException('file_storage component is not a FileStorageManager');
        }

        return $component;
    }
}
