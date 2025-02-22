<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Http\Middleware;

use Swoolefy\Core\RouteMiddleware;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

class CorsMiddleware implements RouteMiddleware
{
    private $options = [
        'path'                   => ['*'],
        'allowedHeaders'         => ['*'],
        'allowedMethods'         => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowedOrigins'         => ["*"],
        'allowedOriginsPatterns' => [],
        'exposedHeaders'         => [],
        'maxAge'                 => 0,
        'supportsCredentials'    => false,
    ];
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        $path   = $requestInput->getRequestUri();
        if (!$this->isPathAllowed($path)) {
           $responseOutput->withStatus(403)->getSwooleResponse()->end('api path 403 Forbidden');
           return false;
        }

        if (!$requestInput->hasHeader('origin')) {
            $responseOutput->withStatus(403)->getSwooleResponse()->end('403 Forbidden Of `Origin` header not present');
            return false;
        }

        if (!$this->isOriginAllowed($requestInput)) {
            $responseOutput->withStatus(403)->getSwooleResponse()->end('403 Forbidden');
            return false;
        }

        $this->setCorsHeaders($requestInput, $responseOutput);

        $method = strtoupper($requestInput->getMethod());
        if ($method == 'OPTIONS') {
            $responseOutput->withStatus(204)->getSwooleResponse()->end();
            return false;
        }
    }
    /**
     * 检查路径是否匹配
     *
     * @param string $path 当前请求路径
     * @return bool
    */
    private function isPathAllowed(string $path): bool
    {
        if (empty($this->options['path'])) {
            return true;
        }

        foreach ($this->options['path'] as $pattern) {
            if ($pattern == '*' || fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param RequestInput $requestInput
     * @return bool
     */
    private function isOriginAllowed(RequestInput $requestInput): bool
    {
        if (in_array('*', $this->options['allowedOrigins'])) {
            return true;
        }

        $origin = $requestInput->getHeaderParams('origin');

        if (in_array($origin, $this->options['allowedOrigins'])) {
            return true;
        }

        foreach ($this->options['allowedOriginsPatterns'] as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param RequestInput $requestInput
     * @param ResponseOutput $responseOutput
     * @return void
     */
    private function setCorsHeaders(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $origin = $requestInput->hasHeader('origin');
        if (in_array('*', $this->options['allowedOrigins']) && !$this->options['supportsCredentials']) {
            $responseOutput->withHeader("Access-Control-Allow-Origin", '*');
        }else if (in_array($origin, $this->options['allowedOrigins']) && count($this->options['allowedOrigins']) == 1) {
            $responseOutput->withHeader("Access-Control-Allow-Origin", $origin);
        }

        $responseOutput->withHeader("Access-Control-Allow-Methods", implode(', ', $this->options['allowedMethods']));
        $responseOutput->withHeader("Access-Control-Allow-Headers", implode(', ', $this->options['allowedHeaders']));
        if (!empty($this->options['exposedHeaders'])) {
            $responseOutput->withHeader("Access-Control-Expose-Headers", implode(', ', $this->options['exposedHeaders']));
        }
        // set cache time
        if ($this->options['maxAge'] > 0) {
            $responseOutput->withHeader("Access-Control-Max-Age", $this->options['maxAge']);
        }
        // set support Cookie
        if ($this->options['supportsCredentials']) {
            $responseOutput->withHeader("Access-Control-Allow-Credentials", true);
        }
    }

}