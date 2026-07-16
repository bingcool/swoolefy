<?php
namespace Test\Controller;

use Swoolefy\Annotation\DownloadResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

class DownloadController extends BController
{
    /**
     * 测试 sendfile 附件下载。
     *
     * Route: GET /api/download/file
     *
     ```bash
     curl -OJ 'http://127.0.0.1:9501/api/download/file?file=demo.txt'
     ```
     */
    #[DownloadResponse]
    public function file(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $basename = basename((string) $requestInput->input('file', 'demo.txt'));
        $filePath = APP_PATH . '/Storage/Download/' . $basename;

        $responseOutput->download($filePath, $basename);
    }

    /**
     * 测试浏览器内联预览下载（Content-Disposition: inline）。
     *
     * Route: GET /api/download/inline
     *
     ```bash
     curl -OJ 'http://127.0.0.1:9501/api/download/inline?file=demo.txt'
     ```
     */
    #[DownloadResponse]
    public function inline(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $basename = basename((string) $requestInput->input('file', 'demo.txt'));
        $filePath = APP_PATH . '/Storage/Download/' . $basename;

        $responseOutput->download($filePath, $basename, inline: true);
    }
}
