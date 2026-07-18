<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * @see \Test\Controller\IndexController::testLog1
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/index/testLog1' -H 'Accept: application/json'
 * ```
 */
final class IndexControllerTest extends ControllerHttpTestCase
{
    public function testLog1ReturnsControllerAction(): void
    {
        $res = $this->getJson('/api/index/testLog1');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertArrayHasKey('Controller', $data);
        $this->assertArrayHasKey('Action', $data);
        $this->assertSame('testLog1', $data['Action'] ?? null);
    }
}
