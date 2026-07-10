<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Exceptions;

/**
 * Parser 已选中并开始执行后的失败。
 *
 * 例如：pandoc 非零退出、OCR HTTP 错误、无效 JSON、超时配置不合法等。
 */
class ParserException extends DocumentException
{
}
