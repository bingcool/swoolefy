<?php

declare(strict_types=1);

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Library\Image\Exception\ImageException;
use Swoolefy\Library\Image\Exception\MissingExtensionException;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Image\Watermark\ImageWatermark;
use Swoolefy\Library\Image\Watermark\TextWatermark;
use Throwable;

/**
 * bingcool/library Image 模块 HTTP 联调（需 ext-gd 或 ext-imagick）。
 */
class ImageController extends BController
{
    /**
     * 创建纯色画布并返回 PNG Base64。
     *
     * Route: GET|POST /api/image/create
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/image/create?width=320&height=200' | jq .
     ```
     */
    #[ApiOperation(description: 'Image create canvas + encode png base64')]
    public function create(RequestInput $requestInput): array
    {
        try {
            $w = max(1, min(2000, (int) $requestInput->input('width', 320)));
            $h = max(1, min(2000, (int) $requestInput->input('height', 200)));
            $color = (string) $requestInput->input('color', '#336699');

            $result = $this->image()->create($w, $h, $color)
                ->encode(new EncodeOptions(format: 'png'));

            return $this->ok([
                'mime' => $result->mime,
                'extension' => $result->extension,
                'width' => $result->size->width,
                'height' => $result->size->height,
                'bytes' => strlen($result->binary),
                'base64' => 'data:' . $result->mime . ';base64,' . base64_encode($result->binary),
            ]);
        } catch (MissingExtensionException $e) {
            return $this->fail(503, $e->getMessage());
        } catch (ImageException $e) {
            return $this->fail(400, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * 对测试画布应用命名 preset（thumb/list/detail/avatar）。
     *
     * Route: GET|POST /api/image/preset
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/image/preset?preset=thumb' | jq .
     ```
     */
    #[ApiOperation(description: 'Image applyPreset on generated canvas')]
    public function preset(RequestInput $requestInput): array
    {
        try {
            $preset = trim((string) $requestInput->input('preset', 'thumb'));
            if ($preset === '') {
                return $this->fail(400, 'preset is required');
            }

            $result = $this->image()->create(800, 600, '#8899aa')
                ->applyPreset($preset)
                ->encode();

            return $this->ok([
                'preset' => $preset,
                'mime' => $result->mime,
                'extension' => $result->extension,
                'width' => $result->size->width,
                'height' => $result->size->height,
                'base64' => 'data:' . $result->mime . ';base64,' . base64_encode($result->binary),
            ]);
        } catch (MissingExtensionException $e) {
            return $this->fail(503, $e->getMessage());
        } catch (ImageException $e) {
            return $this->fail(400, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * 水印联调：若 Resource/Image 下存在 watermark.png 与 DejaVuSans.ttf 则叠加水印。
     *
     * Route: GET|POST /api/image/watermark
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/image/watermark' | jq .
     ```
     */
    #[ApiOperation(description: 'Image watermark from config when assets exist')]
    public function watermark(RequestInput $requestInput): array
    {
        try {
            $editor = $this->image()->create(640, 360, '#445566');
            $cfg = $this->image()->config();
            $applied = [];

            $logoPath = APP_PATH . '/Resource/Image/watermark.png';
            if (is_file($logoPath)) {
                $editor->watermarkImage(ImageWatermark::fromConfig($cfg, ['path' => $logoPath]));
                $applied[] = 'image';
            }

            $fontPath = APP_PATH . '/Resource/Image/DejaVuSans.ttf';
            if (is_file($fontPath)) {
                $editor->watermarkText(TextWatermark::fromConfig($cfg, [
                    'content' => (string) $requestInput->input('text', 'swoolefy-image-test'),
                    'font' => $fontPath,
                ]));
                $applied[] = 'text';
            }

            $result = $editor->encode(new EncodeOptions(format: 'png'));

            return $this->ok([
                'watermark_applied' => $applied,
                'hint' => $applied === [] ? 'Place watermark.png and DejaVuSans.ttf under Test/Resource/Image/' : null,
                'base64' => 'data:' . $result->mime . ';base64,' . base64_encode($result->binary),
            ]);
        } catch (MissingExtensionException $e) {
            return $this->fail(503, $e->getMessage());
        } catch (ImageException $e) {
            return $this->fail(400, $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * 浏览器内联预览 PNG（非 JSON）。
     *
     * Route: GET /api/image/png
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/image/png' -o /tmp/demo.png && file /tmp/demo.png
     ```
     */
    #[ApiOperation(description: 'Image inline PNG response')]
    public function png(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        try {
            $result = $this->image()->create(240, 120, '#228866')
                ->encode(new EncodeOptions(format: 'png'));
            Application::getApp()->setEnd();
            $responseOutput->withHeader('Content-Type', 'image/png');
            $responseOutput->withHeader('Content-Disposition', 'inline; filename="image-demo.png"');
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

    private function image(): ImageManager
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new \RuntimeException('Application is not available');
        }

        $component = $app->get('image');
        if ($component instanceof ContainerObjectDto) {
            $component = $component->getObject();
        }
        if (!$component instanceof ImageManager) {
            throw new \RuntimeException('image component is not an ImageManager');
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
