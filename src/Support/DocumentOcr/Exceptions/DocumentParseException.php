<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Exceptions;

use RuntimeException;

/**
 * 文档解析失败（pandoc 退出非 0、OCR HTTP 错误、无效响应等）。
 */
final class DocumentParseException extends RuntimeException
{
}
