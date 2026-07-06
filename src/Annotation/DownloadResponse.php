<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * 标记 Controller action 为文件下载响应（二进制 body + Content-Disposition）。
 *
 * gen:sdk 据此生成 parseDownloadResponse() 客户端逻辑。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class DownloadResponse
{
}
