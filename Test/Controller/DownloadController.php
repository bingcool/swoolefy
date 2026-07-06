<?php
namespace Test\Controller;

use Swoolefy\Annotation\DownloadResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

class DownloadController extends BController
{
    /**
     * 文件下载示例：通过 sendfile 发送附件。
     *
     * 访问示例：
     * GET /api/download/file
     * GET /api/download/file?file=demo.txt
     *
     * curl 测试：
     * curl -OJ "http://127.0.0.1:9501/api/download/file?file=demo.txt"
     */
    #[DownloadResponse]
    public function file(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $basename = basename((string) $requestInput->input('file', 'demo.txt'));
        $filePath = APP_PATH . '/Storage/Download/' . $basename;

        $responseOutput->download($filePath, $basename);
    }

    /**
     * 浏览器内联预览示例（Content-Disposition: inline）。
     *
     * 访问示例：
     * GET /api/download/inline?file=demo.txt
     */
    #[DownloadResponse]
    public function inline(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $basename = basename((string) $requestInput->input('file', 'demo.txt'));
        $filePath = APP_PATH . '/Storage/Download/' . $basename;

        $responseOutput->download($filePath, $basename, inline: true);
    }
}
