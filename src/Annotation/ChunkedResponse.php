<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * 标记 Controller action 为分块流响应（Chunked / NDJSON 等原始 body）。
 *
 * HTTP 客户端生成器据此走分块 body 解析。
 * 与 {@see \Swoolefy\Http\HttpChunkedResponse} 运行时类区分：本注解仅用于元数据声明。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ChunkedResponse
{
}
