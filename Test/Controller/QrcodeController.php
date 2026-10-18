<?php

declare(strict_types=1);

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Library\Qrcode\Exception\InvalidQrcodeContentException;
use Swoolefy\Library\Qrcode\Exception\MissingExtensionException;
use Swoolefy\Library\Qrcode\Exception\QrcodeException;
use Swoolefy\Library\Qrcode\QrcodeManager;
use Throwable;

/**
 * bingcool/library Qrcode 模块 HTTP 联调。
 */
class QrcodeController extends BController
{
    /**
     * 生成 URL 二维码 PNG Base64。
     *
     * Route: GET|POST /api/qrcode/png
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/qrcode/png?url=https://example.com/pay/1' | jq .
     ```
     */
    #[ApiOperation(description: 'Qrcode PNG base64')]
    public function png(RequestInput $requestInput): array
    {
        try {
            $url = trim((string) $requestInput->input('url', 'https://example.com'));
            $size = max(80, min(800, (int) $requestInput->input('size', 280)));
            $level = strtoupper(trim((string) $requestInput->input('level', 'M')));

            $result = $this->qrcode()->url($url)
                ->size($size)
                ->errorCorrection($level)
                ->png();

            return $this->ok([
                'content' => $result->content,
                'mime' => $result->mime,
                'format' => $result->format,
                'bytes' => strlen($result->binary),
                'base64' => 'data:' . $result->mime . ';base64,' . base64_encode($result->binary),
            ]);
        } catch (MissingExtensionException $e) {
            return $this->fail(503, $e->getMessage());
        } catch (InvalidQrcodeContentException|QrcodeException $e) {
            return $this->fail(400, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * 生成 SVG（无需 gd，仅需 xmlwriter）。
     *
     * Route: GET|POST /api/qrcode/svg
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/qrcode/svg?text=hello-qr' | jq -r '.data.svg' | head
     ```
     */
    #[ApiOperation(description: 'Qrcode SVG string')]
    public function svg(RequestInput $requestInput): array
    {
        try {
            $text = trim((string) $requestInput->input('text', 'hello-swoolefy-qrcode'));
            $result = $this->qrcode()->make($text)->size(200)->svg();

            return $this->ok([
                'content' => $result->content,
                'mime' => $result->mime,
                'svg' => $result->binary,
            ]);
        } catch (MissingExtensionException $e) {
            return $this->fail(503, $e->getMessage());
        } catch (InvalidQrcodeContentException $e) {
            return $this->fail(400, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * WiFi 连接串二维码。
     *
     * Route: GET|POST /api/qrcode/wifi
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/qrcode/wifi?ssid=Demo&password=secret&auth=WPA' | jq .
     ```
     */
    #[ApiOperation(description: 'Qrcode WiFi payload PNG base64')]
    public function wifi(RequestInput $requestInput): array
    {
        try {
            $ssid = trim((string) $requestInput->input('ssid', 'Demo'));
            $password = (string) $requestInput->input('password', 'secret');
            $auth = (string) $requestInput->input('auth', 'WPA');

            $result = $this->qrcode()->wifi($ssid, $password, $auth)
                ->errorCorrection('M')
                ->png();

            return $this->ok([
                'mime' => $result->mime,
                'base64' => 'data:' . $result->mime . ';base64,' . base64_encode($result->binary),
            ]);
        } catch (MissingExtensionException $e) {
            return $this->fail(503, $e->getMessage());
        } catch (InvalidQrcodeContentException $e) {
            return $this->fail(400, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * 带中心 Logo 的 PNG（需 gd + image 组件 + Resource/Image/watermark.png）。
     *
     * Route: GET|POST /api/qrcode/logo
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/qrcode/logo?url=https://example.com' | jq .
     ```
     */
    #[ApiOperation(description: 'Qrcode PNG with optional center logo')]
    public function logo(RequestInput $requestInput): array
    {
        try {
            $url = trim((string) $requestInput->input('url', 'https://example.com'));
            $logoPath = APP_PATH . '/Resource/Image/watermark.png';
            if (!is_file($logoPath)) {
                return $this->fail(400, 'Logo file missing: Test/Resource/Image/watermark.png');
            }

            $builder = $this->qrcode()->url($url)
                ->errorCorrection('H')
                ->size(300)
                ->withLogo($logoPath, 0.16);

            $result = $builder->png();

            return $this->ok([
                'base64' => 'data:' . $result->mime . ';base64,' . base64_encode($result->binary),
            ]);
        } catch (MissingExtensionException $e) {
            return $this->fail(503, $e->getMessage());
        } catch (InvalidQrcodeContentException|QrcodeException $e) {
            return $this->fail(400, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * 内联 PNG 响应。
     *
     * Route: GET /api/qrcode/png-inline
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/qrcode/png-inline' -o /tmp/qr.png && file /tmp/qr.png
     ```
     */
    #[ApiOperation(description: 'Qrcode inline PNG')]
    public function pngInline(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        try {
            $result = $this->qrcode()->make('swoolefy-qrcode-inline')->size(220)->png();
            Application::getApp()->setEnd();
            $responseOutput->withHeader('Content-Type', 'image/png');
            $responseOutput->withHeader('Content-Disposition', 'inline; filename="qrcode.png"');
            $this->swooleResponse->end($result->binary);
        } catch (Throwable $e) {
            Application::getApp()->setEnd();
            $responseOutput->withHeader('Content-Type', 'application/json; charset=utf-8');
            $this->swooleResponse->end(json_encode([
                'code' => 500,
                'msg' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    private function qrcode(): QrcodeManager
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new \RuntimeException('Application is not available');
        }

        $component = $app->get('qrcode');
        if ($component instanceof ContainerObjectDto) {
            $component = $component->getObject();
        }
        if (!$component instanceof QrcodeManager) {
            throw new \RuntimeException('qrcode component is not a QrcodeManager');
        }

        return $component;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): array
    {
        return ['code' => 0, 'msg' => 'ok', 'data' => $data];
    }

    private function fail(int $code, string $msg): array
    {
        return ['code' => $code, 'msg' => $msg, 'data' => null];
    }
}
