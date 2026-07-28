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

use Swoole\Http\Status;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

class CorsMiddleware implements CorsMiddlewareInterface
{
    /**
     * __CORS_OPTIONS_HEADER_RESP
     */
    const __CORS_OPTIONS_HEADER_RESP = '__cors_options_header_resp';

    private $options = [
        'allowedPath'            => ['*'],
        'allowedHeaders'         => ['*'],
        'allowedMethods'         => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowedOrigins'         => [],
        'allowedOriginsPatterns' => ['*.example.com'],
        'exposedHeaders'         => [],
        'maxAge'                 => 86400,
        'supportsCredentials'    => false,
    ];

    /**
     * @var string $originPattern
     */
    protected $originPattern = null;

    /**
     * @return void
     */
    protected function init()
    {
        $this->buildAllowedOrigins();
        $this->buildAllowedOriginsPatterns();
        $this->setAccessControlMaxAge();
    }

    /**
     * allowedOrigins 配置全域名直接配置CORS_ALLOWED_ORIGINS=a.example.com,b.example.com
     *
     * @return void
     */
    protected function buildAllowedOrigins()
    {
        $allowedOrigins = env('CORS_ALLOWED_ORIGINS', '');
        if (!empty($allowedOrigins)) {
            $allowedOrigins = explode(',', $allowedOrigins);
        } else {
            $allowedOrigins = $this->options['allowedOrigins'];
        }
        foreach ($allowedOrigins as $allowedOrigin) {
            $allowedOrigin = trim($allowedOrigin);
            $allowedOrigin = trim($allowedOrigin,'/');
            $newAllowedOrigins[] = sprintf('https://%s', $allowedOrigin);
            $newAllowedOrigins[] = sprintf('http://%s', $allowedOrigin);
        }
        $this->options['allowedOrigins'] = $newAllowedOrigins ?? ['*'];
    }

    /**
     * allowedOriginsPatterns 简化正则配置,支持子域名(或全域名)直接配置CORS_ALLOWED_ORIGINS_PATTERNS=*.example.com,*.example1.com
     *
     * @return void
     */
    protected function buildAllowedOriginsPatterns()
    {
        $allowedOriginsPatterns = env('CORS_ALLOWED_ORIGINS_PATTERNS', '');
        if (!empty($allowedOriginsPatterns)) {
            $allowedOriginsPatterns = explode(',', $allowedOriginsPatterns);
        } else {
            $allowedOriginsPatterns = $this->options['allowedOriginsPatterns'];
        }

        $newAllowedOriginsPatterns = [];
        foreach ($allowedOriginsPatterns as $allowedOriginsPattern) {
            $domain = $this->normalizeAllowedOriginPatternDomain((string) $allowedOriginsPattern);
            if ($domain === null) {
                continue;
            }
            $quoted = preg_quote($domain, '/');
            $newAllowedOriginsPatterns[] = '/^http:\/\/(.*\.)?' . $quoted . '$/i';
            $newAllowedOriginsPatterns[] = '/^https:\/\/(.*\.)?' . $quoted . '$/i';
        }
        $this->options['allowedOriginsPatterns'] = $newAllowedOriginsPatterns;
    }

    /**
     * 将 *.example.com / example.com 规范为纯域名；拒绝 scheme、path、port 等非法配置。
     */
    protected function normalizeAllowedOriginPatternDomain(string $pattern): ?string
    {
        $pattern = trim($pattern);
        $pattern = trim($pattern, '/');
        if ($pattern === '') {
            return null;
        }

        // 拒绝完整 Origin / URL（含 scheme、path、query）
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $pattern) === 1
            || str_contains($pattern, '/')
            || str_contains($pattern, '?')
            || str_contains($pattern, '#')
            || str_contains($pattern, '@')
        ) {
            return null;
        }

        // 去掉可选端口（配置不应带端口；带了也拒绝以免误匹配）
        if (str_contains($pattern, ':')) {
            return null;
        }

        $pattern = preg_replace('/^\*\./', '', $pattern) ?? $pattern;
        $pattern = strtolower($pattern);

        // 宽松域名形态：label.label...（含 localhost）
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $pattern) !== 1) {
            return null;
        }

        return $pattern;
    }

    /**
     * @return void
     */
    protected function setAccessControlMaxAge()
    {
        $maxAge = env('CORS_MAX_AGE', '');
        if (!empty($maxAge) && is_numeric($maxAge)) {
            $this->options['maxAge'] = $maxAge;
        }
    }

    /**
     * @param RequestInput $requestInput
     * @param ResponseOutput $responseOutput
     * @return bool
     */
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        // 一次http请求只需要执行一次
        if (SwooleContext::has(self::__CORS_OPTIONS_HEADER_RESP)) {
            return true;
        }

        $this->init();

        // 跨域请求
        if ($requestInput->hasHeader('origin')) {
            $preflightResult = $this->handlePreflight($requestInput, $responseOutput);
            if (!$preflightResult) {
                return false;
            }
            $this->setCorsHeaders($requestInput, $responseOutput);
        }

        if (!SwooleContext::has(self::__CORS_OPTIONS_HEADER_RESP)) {
            SwooleContext::set(self::__CORS_OPTIONS_HEADER_RESP, true);
        }

        $method = strtoupper($requestInput->getMethod());
        if ($method == 'OPTIONS') {
            $responseOutput->withStatus(Status::NO_CONTENT)->getSwooleResponse()->end();
        }
        return true;
    }

    /**
     * Options 预检请求处理
     *
     * @param RequestInput $requestInput
     * @param ResponseOutput $responseOutput
     * @return bool
     */
    protected function handlePreflight(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        $forbiddenStatus = \Swoole\Http\Status::FORBIDDEN;
        if (!in_array('*', $this->options['allowedOrigins']) && !$requestInput->hasHeader('origin')) {
            $responseOutput->withStatus($forbiddenStatus)->getSwooleResponse()->end(
                sprintf('%d Forbidden Of `Origin` header not present', $forbiddenStatus));
            return false;
        }

        if (!$this->isOriginAllowed($requestInput)) {
            $responseOutput->withStatus($forbiddenStatus)->getSwooleResponse()->end(
                sprintf('`Origin` %d Forbidden', $forbiddenStatus));
            return false;
        }

        $path = $requestInput->getRequestUri();
        if (!$this->isPathAllowed($path)) {
            $responseOutput->withStatus($forbiddenStatus)->getSwooleResponse()->end(
                sprintf("api %d Forbidden, path={$path}", $forbiddenStatus));
            return false;
        }

        return true;
    }

    /**
     * 检查路径是否匹配
     *
     * @param string $path 当前请求路径
     * @return bool
    */
    protected function isPathAllowed(string $path): bool
    {
        if (empty($this->options['allowedPath'])) {
            return true;
        }

        foreach ($this->options['allowedPath'] as $pattern) {
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
    protected function isOriginAllowed(RequestInput $requestInput): bool
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
                $this->originPattern = $pattern;
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
    protected function setCorsHeaders(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $origin = $requestInput->getHeaderParams('origin');
        if (in_array('*', $this->options['allowedOrigins']) && !$this->options['supportsCredentials']) {
            $responseOutput->withHeader("Access-Control-Allow-Origin", '*');
        } else if (in_array($origin, $this->options['allowedOrigins'])) {
            $responseOutput->withHeader("Access-Control-Allow-Origin", $origin);
            // set support Cookie
            if ($this->options['supportsCredentials']) {
                $responseOutput->withHeader("Access-Control-Allow-Credentials", 'true');
            }
        } else if (!empty($this->originPattern)) {
            $responseOutput->withHeader("Access-Control-Allow-Origin", $origin);
        }

        // 动态设置Origin时必须添加Vary头
        $responseOutput->withHeader("Vary", 'Origin');

        $responseOutput->withHeader("Access-Control-Allow-Methods", implode(', ', $this->options['allowedMethods']));
        $responseOutput->withHeader("Access-Control-Allow-Headers", implode(', ', $this->options['allowedHeaders']));
        if (!empty($this->options['exposedHeaders'])) {
            $responseOutput->withHeader("Access-Control-Expose-Headers", implode(', ', $this->options['exposedHeaders']));
        }
        // set cache time
        if ($this->options['maxAge'] > 0) {
            $responseOutput->withHeader("Access-Control-Max-Age", $this->options['maxAge']);
        }
    }

}