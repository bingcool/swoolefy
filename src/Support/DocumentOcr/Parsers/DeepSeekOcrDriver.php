<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Parsers;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Swoolefy\Library\CurlProxy\CurlProxyHandler;
use Swoolefy\Support\DocumentOcr\Contracts\DocumentParserInterface;
use Swoolefy\Support\DocumentOcr\Exceptions\DocumentParseException;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;
use Swoolefy\Support\DocumentOcr\WorkDirectory;
use Throwable;

/**
 * DeepSeek-OCR HTTP 驱动：PNG / JPG / JPEG → Markdown。
 *
 * POST multipart/form-data 到 {baseUri}{endpoint}，字段：
 *   file, clean_temp, output_mmd
 *
 * 响应兼容 markdown / mmd / text / data.markdown；若返回文件路径，
 * 仅允许读取 work_dir 内文件。
 *
 * 单测可注入 $httpClient（Guzzle Client 或 callable）。
 */
final class DeepSeekOcrDriver implements DocumentParserInterface
{
    public const NAME = 'deepseek_ocr';

    /**
     * @param list<string> $allowedExtensions
     * @param Client|callable|null $httpClient
     *        callable 签名：fn(string $uri, array $multipart): array 返回解码后的 JSON 数组
     */
    public function __construct(
        private readonly string $baseUri = 'http://127.0.0.1:7860',
        private readonly string $endpoint = '/api/ocr',
        private readonly float $timeout = 120.0,
        private readonly bool $cleanTemp = true,
        private readonly bool $outputMmd = true,
        private readonly array $allowedExtensions = ['png', 'jpg', 'jpeg'],
        private readonly int $maxFileSize = 10_485_760,
        private readonly int $retryTimes = 1,
        private readonly WorkDirectory $workDirectory = new WorkDirectory('/tmp/swoolefy_document_ocr/deepseek'),
        /** @var Client|callable|null */
        private $httpClient = null,
    ) {
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
            throw new DocumentParseException(
                'DeepSeekOcrDriver does not support extension: ' . $source->extension,
            );
        }

        $size = filesize($source->path);
        if ($size === false || $size <= 0) {
            throw new DocumentParseException('OCR input file is empty: ' . $source->path);
        }
        if ($size > $this->maxFileSize) {
            throw new DocumentParseException(
                'OCR input exceeds max file size (' . $this->maxFileSize . ' bytes): ' . $source->path,
            );
        }

        $startedAt = microtime(true);
        $attempts = max(1, $this->retryTimes + 1);
        $lastError = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $payload = $this->requestOcr($source);
                $markdown = $this->extractMarkdown($payload);

                return new ParseResult(
                    markdown: $markdown,
                    parserName: self::NAME,
                    selectionReason: 'image_extension:' . $source->extension,
                    durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                    sourceHash: hash_file('sha256', $source->path) ?: null,
                    metadata: [
                        'extension' => $source->extension,
                        'attempts' => $i + 1,
                    ],
                );
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        throw new DocumentParseException(
            'DeepSeek OCR failed after ' . $attempts . ' attempt(s): ' . ($lastError?->getMessage() ?? 'unknown'),
            0,
            $lastError,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOcr(DocumentSource $source): array
    {
        $uri = rtrim($this->baseUri, '/') . '/' . ltrim($this->endpoint, '/');
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
                throw new DocumentParseException('OCR httpClient callable must return array');
            }

            return $result;
        }

        $client = $this->httpClient ?? $this->createDefaultClient();
        $response = $client->request('POST', $uri, [
            'multipart' => $multipart,
            'timeout' => $this->timeout,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new DocumentParseException('OCR HTTP status ' . $status . ': ' . mb_substr($body, 0, 200));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new DocumentParseException('OCR response is not valid JSON');
        }

        return $decoded;
    }

    /**
     * 从 OCR JSON 中提取 Markdown；兼容多种字段名。
     *
     * @param array<string, mixed> $payload
     */
    private function extractMarkdown(array $payload): string
    {
        foreach (['markdown', 'mmd', 'text'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return $payload[$key];
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['markdown', 'mmd', 'text'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                    return $data[$key];
                }
            }
        }

        // 若返回文件路径，仅允许读取 work_dir 内文件
        $path = $payload['path'] ?? $payload['file'] ?? $payload['output_path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $this->readPathInsideWorkDir($path);
        }

        throw new DocumentParseException('OCR response missing markdown content');
    }

    private function readPathInsideWorkDir(string $path): string
    {
        $base = realpath($this->workDirectory->ensureBase());
        $real = realpath($path);
        if ($base === false || $real === false || !is_file($real)) {
            throw new DocumentParseException('OCR output path is not a readable file under work_dir');
        }

        if ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new DocumentParseException('OCR output path escapes work_dir: ' . $path);
        }

        $content = file_get_contents($real);
        if ($content === false || trim($content) === '') {
            throw new DocumentParseException('OCR output file is empty');
        }

        return $content;
    }

    private function createDefaultClient(): Client
    {
        $options = [
            'timeout' => $this->timeout,
            'http_errors' => false,
        ];

        // 与 AI Provider 一致：存在 CurlProxyHandler 时注入协程友好 handler
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
