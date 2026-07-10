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

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Parsers;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Swoolefy\Library\CurlProxy\CurlProxyHandler;
use Swoolefy\Support\DocumentOcr\Contracts\DocumentParserInterface;
use Swoolefy\Support\DocumentOcr\Exceptions\ParserException;
use Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentException;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;
use Swoolefy\Support\DocumentOcr\WorkDirectory;
use Throwable;

/**
 * DeepSeek-OCR HTTP 驱动。
 *
 * - PNG / JPG / JPEG → POST {baseUri}{endpoint}（默认 /api/ocr）
 * - PDF → POST {baseUri}{pdfEndpoint}（默认 /api/ocr/pdf），由服务端完成多页处理
 *
 * 响应兼容 markdown / mmd / text / content / result / data.*；
 * 若返回本地文件路径，仅允许读取 work_dir 内文件。
 *
 * 单测可注入 callable；生产可注入外部 Guzzle Client。
 */
final class DeepSeekOcrDriver implements DocumentParserInterface
{
    public const NAME = 'deepseek_ocr';

    private readonly float $timeout;

    private readonly float $connectTimeout;

    private readonly int $maxRetryNum;

    private readonly int $retrySleepMs;

    private readonly int $maxFileSize;

    private readonly int $pdfMaxFileSize;

    /**
     * @param list<string>         $allowedExtensions
     * @param Client|callable|null $httpClient callable: fn(string $uri, array $multipart): array
     */
    public function __construct(
        private readonly string $baseUri = 'http://127.0.0.1:7860',
        private readonly string $endpoint = '/api/ocr',
        private readonly string $pdfEndpoint = '/api/ocr/pdf',
        float $timeout = 120.0,
        float $connectTimeout = 3.0,
        private readonly bool $cleanTemp = true,
        private readonly bool $outputMmd = true,
        private readonly array $allowedExtensions = ['png', 'jpg', 'jpeg', 'pdf'],
        int $maxFileSize = 20_971_520,
        int $pdfMaxFileSize = 104_857_600,
        int $maxRetryNum = 1,
        int $retrySleepMs = 1000,
        private readonly WorkDirectory $workDirectory = new WorkDirectory('/tmp/swoolefy_document_ocr/deepseek'),
        /** @var Client|callable|null */
        private $httpClient = null,
    ) {
        // time_out 必须大于 connect_timeout，避免建连耗尽总超时
        if ($timeout <= 0 || $connectTimeout <= 0 || $timeout <= $connectTimeout) {
            throw new ParserException(
                'DeepSeek OCR time_out must be > connect_timeout and both must be positive; '
                . "got time_out={$timeout}, connect_timeout={$connectTimeout}",
            );
        }

        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxRetryNum = max(0, $maxRetryNum);
        // 重试等待上限 2000ms，避免 Worker 长时间空等
        $this->retrySleepMs = max(0, min(2000, $retrySleepMs));
        $this->maxFileSize = max(1, $maxFileSize);
        $this->pdfMaxFileSize = max(1, $pdfMaxFileSize);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(DocumentSource $source): bool
    {
        return in_array(strtolower($source->extension), $this->allowedExtensions, true);
    }

    public function parse(DocumentSource $source, ?ParseOptions $options = null): ParseResult
    {
        if (!$this->supports($source)) {
            throw new UnsupportedDocumentException(
                'DeepSeekOcrDriver does not support extension: ' . $source->extension,
            );
        }

        $ext = strtolower($source->extension);
        $limit = $ext === 'pdf' ? $this->pdfMaxFileSize : $this->maxFileSize;
        $size = filesize($source->path);
        if ($size === false || $size <= 0) {
            throw new ParserException('OCR input file is empty: ' . $source->path);
        }
        if ($size > $limit) {
            throw new ParserException(
                'OCR input exceeds max file size (' . $limit . ' bytes) for .' . $ext . ': ' . $source->path,
            );
        }

        $endpoint = $this->endpointFor($ext);
        $uri = $this->buildUri($endpoint);
        $startedAt = microtime(true);
        // max_retry_num 不含首次：1 表示最多请求 2 次
        $attempts = $this->maxRetryNum + 1;
        $lastError = null;

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0 && $this->retrySleepMs > 0) {
                usleep($this->retrySleepMs * 1000);
            }

            try {
                $payload = $this->requestOcr($uri, $source);
                $markdown = $this->extractMarkdown($payload);
                $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
                $sourceHash = hash_file('sha256', $source->path) ?: null;

                return new ParseResult(
                    markdown: $markdown,
                    parserName: self::NAME,
                    selectionReason: $ext === 'pdf'
                        ? 'pdf_direct_deepseek_ocr'
                        : 'image_extension:' . $ext,
                    durationMs: $durationMs,
                    sourceHash: $sourceHash,
                    metadata: array_merge($source->metadata, [
                        // 固定字段（跨 Parser 统一命名）
                        'parser' => self::NAME,
                        'mime' => $source->mimeType,
                        'extension' => $ext,
                        'durationMs' => $durationMs,
                        'sourcePath' => $source->path,
                        'sourceHash' => $sourceHash,
                        // Driver 特有
                        'endpoint' => $uri,
                        'attempts' => $i + 1,
                        'ocrResponseKeys' => array_keys($payload),
                    ]),
                );
            } catch (UnsupportedDocumentException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e instanceof ParserException
                    ? $e
                    : new ParserException($e->getMessage(), 0, $e);
            }
        }

        throw new ParserException(
            'DeepSeek OCR failed after ' . $attempts . ' attempt(s): ' . ($lastError?->getMessage() ?? 'unknown'),
            0,
            $lastError,
        );
    }

    /** 按扩展名选择 OCR endpoint。 */
    public function endpointFor(string $extension): string
    {
        return strtolower($extension) === 'pdf' ? $this->pdfEndpoint : $this->endpoint;
    }

    private function buildUri(string $endpoint): string
    {
        return rtrim($this->baseUri, '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOcr(string $uri, DocumentSource $source): array
    {
        $multipart = [
            [
                'name' => 'file',
                'contents' => (string) file_get_contents($source->path),
                'filename' => basename($source->path),
            ],
            [
                'name' => 'clean_temp',
                'contents' => $this->cleanTemp ? 'true' : 'false',
            ],
            [
                'name' => 'output_mmd',
                'contents' => $this->outputMmd ? 'true' : 'false',
            ],
        ];

        if (is_callable($this->httpClient)) {
            $result = ($this->httpClient)($uri, $multipart);
            if (!is_array($result)) {
                throw new ParserException('OCR httpClient callable must return array');
            }

            return $result;
        }

        $client = $this->httpClient instanceof Client ? $this->httpClient : $this->createDefaultClient();
        $response = $client->request('POST', $uri, [
            'multipart' => $multipart,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => true,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new ParserException('OCR HTTP status ' . $status . ': ' . mb_substr($body, 0, 200));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ParserException('OCR response is not valid JSON');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractMarkdown(array $payload): string
    {
        foreach (['markdown', 'mmd', 'text', 'content', 'result'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return $payload[$key];
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['markdown', 'mmd', 'text', 'content', 'result'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                    return $data[$key];
                }
            }
        }

        $path = $payload['path'] ?? $payload['file'] ?? $payload['output_path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $this->readPathInsideWorkDir($path);
        }

        throw new ParserException('OCR response missing markdown content');
    }

    private function readPathInsideWorkDir(string $path): string
    {
        $base = realpath($this->workDirectory->ensureBase());
        $real = realpath($path);
        if ($base === false || $real === false || !is_file($real)) {
            throw new ParserException('OCR output path is not a readable file under work_dir');
        }

        if ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new ParserException('OCR output path escapes work_dir: ' . $path);
        }

        $content = file_get_contents($real);
        if ($content === false || trim($content) === '') {
            throw new ParserException('OCR output file is empty');
        }

        return $content;
    }

    private function createDefaultClient(): Client
    {
        $options = [
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => true,
        ];

        if (class_exists(CurlProxyHandler::class) && defined('APP_PATH') && (string) APP_PATH !== '') {
            try {
                $stack = HandlerStack::create(CurlProxyHandler::getStackHandler());
                CurlProxyHandler::applyPsr7CompatiblePrepareBody($stack);
                $options['handler'] = $stack;
            } catch (Throwable) {
                // CLI / 无 APP 上下文时回退默认 Guzzle handler
            }
        }

        return new Client($options);
    }
}
