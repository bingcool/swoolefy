<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * 标记 Controller action 为文件下载响应（二进制 body + Content-Disposition）。
 *
 * HTTP 客户端生成器据此走下载响应解析。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class DownloadResponse
{
}
