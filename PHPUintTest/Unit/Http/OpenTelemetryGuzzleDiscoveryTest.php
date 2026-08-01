<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUintTest\TestCase;
use Psr\Http\Message\RequestInterface;
use Swoolefy\Library\CurlProxy\PrepareBodyMiddleware;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\Guzzle;

/**
 * OTLP export uses Guzzle discovery; prepare_body must set string Content-Length (psr7 2.11+).
 */
final class OpenTelemetryGuzzleDiscoveryTest extends TestCase
{
    public function testGuzzleDiscoveryUsesHandlerStackWithCompatiblePrepareBody(): void
    {
        $client = (new Guzzle())->create(['timeout' => 1]);

        $this->assertInstanceOf(HandlerStack::class, $client->getConfig('handler'));
    }

    public function testPrepareBodyMiddlewareSetsStringContentLength(): void
    {
        $captured = null;
        $middleware = new PrepareBodyMiddleware(static function (RequestInterface $request, array $options) use (&$captured) {
            $captured = $request;

            return new FulfilledPromise(new Response());
        });

        $middleware(new Request('POST', 'http://example.com', [], 'payload'), [])->wait();

        $this->assertNotNull($captured);
        $this->assertSame('7', $captured->getHeaderLine('Content-Length'));
    }
}
