<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Exceptions;

use RuntimeException;

/**
 * 当前 Phase 不支持的文档类型（如 PDF），或无可用 Driver。
 *
 * 策略：显式抛异常，不静默兜底。
 */
final class UnsupportedDocumentTypeException extends RuntimeException
{
}
