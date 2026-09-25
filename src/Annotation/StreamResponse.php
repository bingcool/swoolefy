<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * 标记 Controller action 为 SSE 流式响应（text/event-stream）。
 *
 * HTTP 客户端生成器据此走 SSE 解析，而非 JSON 信封解析。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class StreamResponse
{
}
