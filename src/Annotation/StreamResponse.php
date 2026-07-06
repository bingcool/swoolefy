<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * 标记 Controller action 为 SSE 流式响应（text/event-stream）。
 *
 * gen:sdk 据此生成 parseSseResponse() 客户端逻辑，而非 JSON 信封解析。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class StreamResponse
{
}
