<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\UploadedFile;
use Swoolefy\Http\UploadException;

class UploadController extends BController
{
    /**
     * 单文件上传示例。
     *
     * curl 测试：
       curl -F "file=@/path/to/demo.jpg" "http://127.0.0.1:9501/api/upload/single"
     */
    public function single(RequestInput $requestInput): array
    {
        $uploaded = $requestInput->file('file');
        if (!$uploaded instanceof UploadedFile) {
            return ['code' => 400, 'msg' => 'file field is required'];
        }

        try {
            $uploaded->validateMaxSize(5 * 1024 * 1024);
            $uploaded->validateExtensions(['jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'pdf']);
            $savedPath = $uploaded->store(APP_PATH . '/Storage/Upload');
        } catch (UploadException $e) {
            return ['code' => 400, 'msg' => $e->getMessage()];
        }

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'path' => $savedPath,
                'original_name' => $uploaded->getClientOriginalName(),
                'mime' => $uploaded->getMimeType(),
                'size' => $uploaded->getSize(),
            ],
        ];
    }

    /**
     * 多文件上传示例（表单字段 files[]）。
     *
     * curl 测试：
     * curl -F "files[]=@a.jpg" -F "files[]=@b.png" "http://127.0.0.1:9501/api/upload/multiple"
     */
    public function multiple(RequestInput $requestInput): array
    {
        $uploadedFiles = $requestInput->file('files');
        if (!is_array($uploadedFiles) || $uploadedFiles === []) {
            return ['code' => 400, 'msg' => 'files[] field is required'];
        }

        $saved = [];
        foreach ($uploadedFiles as $uploaded) {
            if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
                continue;
            }

            try {
                $uploaded->validateMaxSize(5 * 1024 * 1024);
                $saved[] = [
                    'path' => $uploaded->store(APP_PATH . '/Storage/Upload'),
                    'original_name' => $uploaded->getClientOriginalName(),
                    'size' => $uploaded->getSize(),
                ];
            } catch (UploadException $e) {
                return ['code' => 400, 'msg' => $e->getMessage()];
            }
        }

        return ['code' => 0, 'msg' => 'ok', 'data' => $saved];
    }
}
