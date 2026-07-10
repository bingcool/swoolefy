<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Exceptions;

use RuntimeException;

/**
 * DocumentOcr 模块顶层异常。
 *
 * 业务层可统一 catch DocumentException，覆盖文件不可读、解析失败、类型不支持等。
 */
class DocumentException extends RuntimeException
{
}
