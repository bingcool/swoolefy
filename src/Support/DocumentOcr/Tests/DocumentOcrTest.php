<?php

declare(strict_types=1);

/**
 * DocumentOcr 模块回归测试。
 *
 * 覆盖：AutoParser 选择、PDF 拒绝、Pandoc mock、DeepSeek OCR mock、
 * MarkdownNormalizer、ChunkingAdapter、超大图片、无效 JSON。
 *
 * 运行：php src/Support/DocumentOcr/Tests/DocumentOcrTest.php
 * 或：composer test:document-ocr
 */

use Swoolefy\Support\DocumentOcr\Chunking\ChunkingAdapter;
use Swoolefy\Support\DocumentOcr\DocumentOcrFactory;
use Swoolefy\Support\DocumentOcr\Exceptions\DocumentParseException;
use Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentTypeException;
use Swoolefy\Support\DocumentOcr\Loaders\LocalFileLoader;
use Swoolefy\Support\DocumentOcr\Markdown\MarkdownNormalizer;
use Swoolefy\Support\DocumentOcr\Parsers\AutoParser;
use Swoolefy\Support\DocumentOcr\Parsers\DeepSeekOcrDriver;
use Swoolefy\Support\DocumentOcr\Parsers\PandocDriver;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;
use Swoolefy\Support\DocumentOcr\WorkDirectory;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tempFile(string $suffix, string $contents): string
{
    $path = sys_get_temp_dir() . '/swoolefy_ocr_' . bin2hex(random_bytes(4)) . $suffix;
    file_put_contents($path, $contents);

    return $path;
}

function testAutoParserSelectsPandocForDocx(): void
{
    $auto = new AutoParser([
        new PandocDriver(commandExecutor: static fn () => [0, '']),
        new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
    ]);

    [$parser, $reason] = $auto->select(new DocumentSource('/tmp/a.docx', 'docx'));
    assertTrue($parser->name() === PandocDriver::NAME, 'docx → pandoc');
    assertTrue($reason === 'structured_format:docx', 'docx selection reason');
}

function testAutoParserSelectsOcrForPng(): void
{
    $auto = new AutoParser([
        new PandocDriver(commandExecutor: static fn () => [0, '']),
        new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
    ]);

    [$parser, $reason] = $auto->select(new DocumentSource('/tmp/a.png', 'png'));
    assertTrue($parser->name() === DeepSeekOcrDriver::NAME, 'png → deepseek_ocr');
    assertTrue($reason === 'image_extension:png', 'png selection reason');
}

function testAutoParserRejectsPdf(): void
{
    $auto = new AutoParser([
        new PandocDriver(commandExecutor: static fn () => [0, '']),
        new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
    ]);

    try {
        $auto->select(new DocumentSource('/tmp/a.pdf', 'pdf'));
        throw new RuntimeException('pdf should throw');
    } catch (UnsupportedDocumentTypeException $e) {
        assertTrue(str_contains($e->getMessage(), 'PDF'), 'pdf message');
    }
}

function testForcedParser(): void
{
    $auto = new AutoParser([
        new PandocDriver(commandExecutor: static fn () => [0, '']),
        new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
    ]);

    [$parser, $reason] = $auto->select(
        new DocumentSource('/tmp/a.md', 'md'),
        new ParseOptions(parser: 'pandoc'),
    );
    assertTrue($parser->name() === 'pandoc', 'forced pandoc');
    assertTrue($reason === 'forced_parser:pandoc', 'forced reason');
}

function testPandocDriverWithMockExecutor(): void
{
    $work = new WorkDirectory(sys_get_temp_dir() . '/swoolefy_ocr_pandoc_test');
    $input = tempFile('.html', '<h1>Hello</h1>');

    $driver = new PandocDriver(
        workDirectory: $work,
        commandExecutor: static function (string $bin, array $args, string $outputFile): array {
            assertTrue($bin === 'pandoc', 'bin is pandoc');
            file_put_contents($outputFile, "# Hello\n\nFrom pandoc mock\n");

            return [0, 'ok'];
        },
    );

    $result = $driver->parse((new LocalFileLoader())->load($input));
    assertTrue($result->parserName === 'pandoc', 'parser name');
    assertTrue(str_contains($result->markdown, 'Hello'), 'markdown content');
    assertTrue($result->sourceHash !== null, 'source hash set');

    @unlink($input);
}

function testPandocDriverFailure(): void
{
    $work = new WorkDirectory(sys_get_temp_dir() . '/swoolefy_ocr_pandoc_fail');
    $input = tempFile('.txt', 'plain');

    $driver = new PandocDriver(
        workDirectory: $work,
        commandExecutor: static fn (): array => [1, 'boom'],
    );

    try {
        $driver->parse((new LocalFileLoader())->load($input));
        throw new RuntimeException('should fail');
    } catch (DocumentParseException $e) {
        assertTrue(str_contains($e->getMessage(), 'exit code 1'), 'exit code in message');
    }

    @unlink($input);
}

function testDeepSeekOcrDriverMock(): void
{
    $input = tempFile('.png', 'fake-png-bytes');
    $driver = new DeepSeekOcrDriver(
        httpClient: static function (string $uri, array $multipart): array {
            assertTrue(str_contains($uri, '/api/ocr'), 'ocr endpoint');
            assertTrue(count($multipart) >= 1, 'multipart has file');

            return ['markdown' => '# OCR Result\n\n识别文字'];
        },
    );

    $result = $driver->parse((new LocalFileLoader())->load($input));
    assertTrue($result->parserName === 'deepseek_ocr', 'ocr parser');
    assertTrue(str_contains($result->markdown, '识别文字'), 'ocr markdown');
    assertTrue($result->selectionReason === 'image_extension:png', 'ocr reason');

    @unlink($input);
}

function testDeepSeekOcrInvalidJsonFields(): void
{
    $input = tempFile('.jpg', 'bytes');
    $driver = new DeepSeekOcrDriver(
        retryTimes: 0,
        httpClient: static fn (): array => ['status' => 'ok'],
    );

    try {
        $driver->parse((new LocalFileLoader())->load($input));
        throw new RuntimeException('should fail on missing markdown');
    } catch (DocumentParseException $e) {
        assertTrue(str_contains($e->getMessage(), 'markdown'), 'missing markdown');
    }

    @unlink($input);
}

function testDeepSeekOcrMaxFileSize(): void
{
    $input = tempFile('.png', str_repeat('x', 100));
    $driver = new DeepSeekOcrDriver(
        maxFileSize: 10,
        httpClient: static fn (): array => ['markdown' => 'x'],
    );

    try {
        $driver->parse((new LocalFileLoader())->load($input));
        throw new RuntimeException('should reject oversized file');
    } catch (DocumentParseException $e) {
        assertTrue(str_contains($e->getMessage(), 'max file size'), 'size message');
    }

    @unlink($input);
}

function testDeepSeekOcrDataMarkdownField(): void
{
    $input = tempFile('.jpeg', 'img');
    $driver = new DeepSeekOcrDriver(
        httpClient: static fn (): array => ['data' => ['markdown' => 'from data.markdown']],
    );

    $result = $driver->parse((new LocalFileLoader())->load($input));
    assertTrue($result->markdown === 'from data.markdown', 'data.markdown field');

    @unlink($input);
}

function testMarkdownNormalizer(): void
{
    $n = new MarkdownNormalizer();
    $out = $n->normalize("  a\r\n\r\n\r\n\r\nb  ");
    assertTrue($out === "a\n\nb", 'normalize whitespace');
}

function testChunkingAdapter(): void
{
    $result = new ParseResult(
        markdown: "Para one.\n\nPara two is longer text.\n\nPara three.",
        parserName: 'pandoc',
        selectionReason: 'structured_format:md',
        sourceHash: 'abc',
    );

    $docs = (new ChunkingAdapter(maxChars: 50))->splitParseResult($result, sourceName: 'demo.md');
    assertTrue(count($docs) >= 1, 'at least one chunk');
    assertTrue($docs[0]->sourceName === 'demo.md', 'sourceName');
    assertTrue($docs[0]->sourceType === 'document_ocr', 'sourceType');
}

function testFactoryParseFileNormalizes(): void
{
    $input = tempFile('.md', "# Title\n\n\n\nBody");
    $work = new WorkDirectory(sys_get_temp_dir() . '/swoolefy_ocr_factory');

    $factory = new DocumentOcrFactory(
        new AutoParser([
            new PandocDriver(
                workDirectory: $work,
                commandExecutor: static function (string $bin, array $args, string $outputFile): array {
                    file_put_contents($outputFile, "# Title\n\n\n\nBody\n");

                    return [0, ''];
                },
            ),
        ]),
    );

    $result = $factory->parseFile($input);
    assertTrue($result->markdown === "# Title\n\nBody", 'factory normalizes');
    assertTrue($result->parserName === 'pandoc', 'factory parser');

    $docs = $factory->parseFileToDocuments($input, sourceName: 'manual.md');
    assertTrue(count($docs) >= 1, 'documents from factory');

    @unlink($input);
}

function testLocalFileLoaderMissingFile(): void
{
    try {
        (new LocalFileLoader())->load('/tmp/not-exists-' . uniqid('', true) . '.docx');
        throw new RuntimeException('should fail');
    } catch (DocumentParseException $e) {
        assertTrue(str_contains($e->getMessage(), 'not found'), 'missing file');
    }
}

function testWorkDirectoryPathSafety(): void
{
    $base = sys_get_temp_dir() . '/swoolefy_ocr_safe_' . bin2hex(random_bytes(3));
    $work = new WorkDirectory($base);
    $job = $work->createJobDir('t');
    assertTrue(is_dir($job), 'job dir created');

    // 不允许删除 work_dir 外路径（应静默跳过）
    $outside = sys_get_temp_dir() . '/swoolefy_ocr_outside_' . bin2hex(random_bytes(3));
    mkdir($outside);
    file_put_contents($outside . '/x.txt', 'keep');
    $work->removeDir($outside);
    assertTrue(is_file($outside . '/x.txt'), 'outside dir not deleted');

    $work->removeDir($job);
    @unlink($outside . '/x.txt');
    @rmdir($outside);
    @rmdir($base);
}

$tests = [
    'auto selects pandoc for docx' => 'testAutoParserSelectsPandocForDocx',
    'auto selects ocr for png' => 'testAutoParserSelectsOcrForPng',
    'auto rejects pdf' => 'testAutoParserRejectsPdf',
    'forced parser' => 'testForcedParser',
    'pandoc mock executor' => 'testPandocDriverWithMockExecutor',
    'pandoc failure' => 'testPandocDriverFailure',
    'deepseek ocr mock' => 'testDeepSeekOcrDriverMock',
    'deepseek invalid json' => 'testDeepSeekOcrInvalidJsonFields',
    'deepseek max file size' => 'testDeepSeekOcrMaxFileSize',
    'deepseek data.markdown' => 'testDeepSeekOcrDataMarkdownField',
    'markdown normalizer' => 'testMarkdownNormalizer',
    'chunking adapter' => 'testChunkingAdapter',
    'factory parseFile normalize' => 'testFactoryParseFileNormalizes',
    'loader missing file' => 'testLocalFileLoaderMissingFile',
    'work dir path safety' => 'testWorkDirectoryPathSafety',
];

foreach ($tests as $label => $fn) {
    $fn();
    echo "[OK] {$label}\n";
}

echo "All DocumentOcr tests passed.\n";
