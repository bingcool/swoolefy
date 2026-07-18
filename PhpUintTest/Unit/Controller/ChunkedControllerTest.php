<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * @see \Test\Controller\ChunkedController::ndjson
 *
 * ```bash
 * curl -N 'http://127.0.0.1:9501/api/chunked/ndjson?count=1&interval=0.1'
 * ```
 */
final class ChunkedControllerTest extends ControllerHttpTestCase
{
    public function testNdjsonEmitsIndexLine(): void
    {
        $res = $this->getRaw('/api/chunked/ndjson?count=1&interval=0.1');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('"index":1', $res['body']);
        $this->assertStringContainsString('"total":1', $res['body']);
    }
}
