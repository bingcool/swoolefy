<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * 标记 Controller action 为分块流响应（Chunked / NDJSON 等原始 body）。
 *
 * gen:sdk 据此生成 parseStreamResponse() 客户端逻辑。
 * 与 {@see \Swoolefy\Http\HttpChunkedResponse} 运行时类区分：本注解仅用于元数据声明。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ChunkedResponse
{
}
