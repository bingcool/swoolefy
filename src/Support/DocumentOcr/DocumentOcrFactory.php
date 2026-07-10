<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr;

use GuzzleHttp\Client;
use Swoolefy\Support\DocumentOcr\Chunking\ChunkingAdapter;
use Swoolefy\Support\DocumentOcr\Contracts\DocumentLoaderInterface;
use Swoolefy\Support\DocumentOcr\Contracts\DocumentParserInterface;
use Swoolefy\Support\DocumentOcr\Loaders\LocalFileLoader;
use Swoolefy\Support\DocumentOcr\Markdown\MarkdownNormalizer;
use Swoolefy\Support\DocumentOcr\Parsers\AutoParser;
use Swoolefy\Support\DocumentOcr\Parsers\DeepSeekOcrDriver;
use Swoolefy\Support\DocumentOcr\Parsers\PandocDriver;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;

/**
 * DocumentOcr 装配与门面。
 *
 * 职责：
 * 1. 从 document_ocr.php 配置创建 Pandoc / DeepSeek / AutoParser；
 * 2. parseFile()：加载 → 解析 → 统一 MarkdownNormalizer（避免 Driver 重复 normalize）。
 *
 * 不绑定 RAG；入库时再经 ChunkingAdapter 转 Neuron Document。
 */
final class DocumentOcrFactory
{
    public function __construct(
        private readonly DocumentParserInterface $parser,
        private readonly DocumentLoaderInterface $loader = new LocalFileLoader(),
        private readonly MarkdownNormalizer $normalizer = new MarkdownNormalizer(),
    ) {
    }

    /**
     * 从配置数组装配工厂。
     *
     * @param array<string, mixed> $config         document_ocr.php 返回值
     * @param Client|null          $deepSeekClient 可选外部 Guzzle Client；注入后优先于默认 Client
     */
    public static function fromConfig(array $config, ?Client $deepSeekClient = null): self
    {
        $parsers = [];

        $pandoc = is_array($config['pandoc'] ?? null) ? $config['pandoc'] : [];
        if (self::isEnabled($pandoc['enabled'] ?? true)) {
            $parsers[] = new PandocDriver(
                bin: (string) ($pandoc['bin'] ?? 'pandoc'),
                outputFormat: (string) ($pandoc['output_format'] ?? 'gfm'),
                inputFormats: self::stringList($pandoc['input_formats'] ?? ['docx', 'doc', 'html', 'htm', 'md', 'txt']),
                runnerName: (string) ($pandoc['runner_name'] ?? 'document-pandoc'),
                concurrent: max(1, (int) ($pandoc['concurrent'] ?? 2)),
                workDirectory: new WorkDirectory((string) ($pandoc['work_dir'] ?? '/tmp/swoolefy_document_ocr/pandoc')),
            );
        }

        $ocr = is_array($config['deepseek_ocr'] ?? null) ? $config['deepseek_ocr'] : [];
        if (self::isEnabled($ocr['enabled'] ?? true)) {
            // 兼容旧键 timeout / retry.times
            $timeout = (float) ($ocr['time_out'] ?? $ocr['timeout'] ?? 120);
            $connectTimeout = (float) ($ocr['connect_timeout'] ?? 3);
            $maxRetryNum = (int) ($ocr['max_retry_num'] ?? $ocr['retry']['times'] ?? $ocr['retry_times'] ?? 1);
            $retrySleepMs = (int) ($ocr['retry_sleep_ms'] ?? 1000);

            $parsers[] = new DeepSeekOcrDriver(
                baseUri: (string) ($ocr['base_uri'] ?? 'http://127.0.0.1:7860'),
                endpoint: (string) ($ocr['endpoint'] ?? '/api/ocr'),
                pdfEndpoint: (string) ($ocr['pdf_endpoint'] ?? '/api/ocr/pdf'),
                timeout: $timeout,
                connectTimeout: $connectTimeout,
                cleanTemp: (bool) ($ocr['clean_temp'] ?? true),
                outputMmd: (bool) ($ocr['output_mmd'] ?? true),
                allowedExtensions: self::stringList($ocr['allowed_extensions'] ?? ['png', 'jpg', 'jpeg', 'pdf']),
                maxFileSize: max(1, (int) ($ocr['max_file_size'] ?? 20_971_520)),
                pdfMaxFileSize: max(1, (int) ($ocr['pdf_max_file_size'] ?? 104_857_600)),
                maxRetryNum: max(0, $maxRetryNum),
                retrySleepMs: max(0, $retrySleepMs),
                workDirectory: new WorkDirectory((string) ($ocr['work_dir'] ?? '/tmp/swoolefy_document_ocr/deepseek')),
                httpClient: $deepSeekClient,
            );
        }

        return new self(new AutoParser($parsers));
    }

    /**
     * 解析本地文件为 Markdown（已 normalize）。
     */
    public function parseFile(string $path, ?ParseOptions $options = null, array $metadata = []): ParseResult
    {
        $source = $this->loader->load($path, $metadata);
        $result = $this->parser->parse($source, $options);

        return $result->with([
            'markdown' => $this->normalizer->normalize($result->markdown),
        ]);
    }

    /** 底层 AutoParser / 自定义 Parser，供高级用法。 */
    public function parser(): DocumentParserInterface
    {
        return $this->parser;
    }

    /** 便捷：解析后切分为 Neuron Document[]。 */
    public function parseFileToDocuments(
        string $path,
        ?ParseOptions $options = null,
        string $sourceName = '',
        array $metadata = [],
        int $maxChars = 2000,
    ): array {
        $result = $this->parseFile($path, $options, $metadata);

        return (new ChunkingAdapter($maxChars))->splitParseResult(
            $result,
            sourceName: $sourceName !== '' ? $sourceName : basename($path),
            extraMeta: $metadata,
        );
    }

    /**
     * @param mixed $raw
     *
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '' && !in_array($value, $list, true)) {
                $list[] = strtolower($value);
            }
        }

        return $list;
    }

    private static function isEnabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
