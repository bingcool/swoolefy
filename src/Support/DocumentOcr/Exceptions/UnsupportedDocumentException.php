<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Exceptions;

/**
 * 当前无可用 Parser，或指定 Parser 拒绝该文档类型。
 *
 * 契约：不支持时必须抛本异常，禁止返回 null / false / 空 ParseResult。
 */
class UnsupportedDocumentException extends ParserException
{
}
