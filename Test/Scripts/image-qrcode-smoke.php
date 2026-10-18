<?php

declare(strict_types=1);

/**
 * 不依赖 HTTP：直接加载 Test 配置验证 Image / Qrcode 组件。
 *
 *   php Test/scripts/image-qrcode-smoke.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

define('APP_PATH', $root . '/Test');

use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Qrcode\QrcodeManager;

$imageConfig = include APP_PATH . '/Config/image.php';
$qrcodeConfig = include APP_PATH . '/Config/qrcode.php';

$image = new ImageManager($imageConfig, null);
$qrcode = new QrcodeManager($qrcodeConfig, null, $image);

$png = $image->create(64, 48, '#336699')->encode(new EncodeOptions(format: 'png'));
assert(str_starts_with($png->binary, "\x89PNG"), 'image png magic');

$thumb = $image->create(400, 300, '#aaa')->applyPreset('thumb')->encode();
assert($thumb->size->width === 200 && $thumb->size->height === 200, 'thumb preset');

$qrPng = $qrcode->url('https://example.com/smoke')->size(160)->png();
assert(str_starts_with($qrPng->binary, "\x89PNG"), 'qrcode png magic');

$qrSvg = $qrcode->make('svg-smoke')->svg();
assert(str_contains($qrSvg->binary, '<svg'), 'qrcode svg');

echo "image-qrcode-smoke: OK\n";
