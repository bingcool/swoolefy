<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\TestCase;
use Swoole\Coroutine;
use Swoole\Http\Status as HttpStatus;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Exception\InvalidJsonException;
use Swoolefy\Http\RequestBodyParser;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;

/**
 * 阶段三 5.5（审计项 27）：非法 JSON 返回明确 400。
 * 目标：JSON_THROW_ON_ERROR；空 body/null/{}/[] 按约定；错误码 invalid_json，不记完整 body。
 */
final class RequestBodyParserTest extends TestCase
{
    /**
     * 测非法 JSON 抛 InvalidJsonException：HTTP 400、error_code=invalid_json、含 request_id、无 body。
     * 对应问题：旧实现静默 json_decode 失败后返回空数组，调用方无法区分坏 JSON。
     */
    public function testInvalidJsonThrowsBadRequestWithFixedErrorCode(): void
    {
        Coroutine\run(function (): void {
            Context::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, 'json-req-1');
            $bad = '{"a":1,}';
            try {
                RequestBodyParser::parseJsonPayload('application/json', $bad, 'POST');
                $this->fail('expected InvalidJsonException');
            } catch (InvalidJsonException $e) {
                $this->assertSame(HttpStatus::BAD_REQUEST, $e->getCode());
                $ctx = $e->getContextData();
                $this->assertSame(InvalidJsonException::ERROR_CODE, $ctx['error_code']);
                $this->assertSame('invalid_json', $ctx['error_code']);
                $this->assertSame('json-req-1', $ctx['request_id']);
                $this->assertNotEmpty($ctx['reason']);
                $this->assertStringNotContainsString($bad, $e->getMessage());
                $this->assertStringNotContainsString($bad, json_encode($ctx));
            }
        });
    }

    /**
     * 测空 body、合法 null、{}、[] 的现有约定：均不抛，并得到可合并的数组结果。
     */
    public function testEmptyNullObjectAndArrayBodiesFollowExistingContract(): void
    {
        $this->assertSame([], RequestBodyParser::parseJsonPayload('application/json', '', 'POST'));
        $this->assertSame([], RequestBodyParser::parseJsonPayload('application/json', 'null', 'POST'));
        $this->assertSame([], RequestBodyParser::parseJsonPayload('application/json', '{}', 'POST'));
        $this->assertSame([], RequestBodyParser::parseJsonPayload('application/json', '[]', 'POST'));
        $this->assertSame(['a' => 1], RequestBodyParser::parseJsonPayload('application/json', '{"a":1}', 'POST'));
    }

    /**
     * 测超深 JSON 触发 JsonException 并映射为 invalid_json。
     * 对应问题：深度攻击应明确 400，而不是静默吞掉。
     */
    public function testTooDeepJsonMapsToInvalidJson(): void
    {
        $deep = str_repeat('[', 600) . '1' . str_repeat(']', 600);
        try {
            RequestBodyParser::parseJsonPayload('application/json', $deep, 'POST');
            $this->fail('expected InvalidJsonException');
        } catch (InvalidJsonException $e) {
            $this->assertSame('invalid_json', $e->getContextData()['error_code']);
            $this->assertSame(HttpStatus::BAD_REQUEST, $e->getCode());
        }
    }
}
