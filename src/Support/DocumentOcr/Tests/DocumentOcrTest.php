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

/**
 * DocumentOcr 模块回归测试（无需真实 Pandoc / DeepSeek OCR 服务）。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | AutoParser | docx→Pandoc、png/pdf→OCR、强制 parser、不支持扩展名异常 |
 * | PandocDriver | mock 执行器成功/失败、metadata 固定字段 |
 * | DeepSeekOcrDriver | 图片/PDF 端点、JSON 字段解析、大小/超时校验 |
 * | MarkdownNormalizer / ChunkingAdapter | 空白规范化、分块 metadata |
 * | DocumentOcrFactory | parseFile 规范化、fromConfig PDF 启用 |
 * | LocalFileLoader / WorkDirectory | 缺失文件、路径安全边界 |
 *
 * ## 运行
 * ```bash
 * php src/Support/DocumentOcr/Tests/DocumentOcrTest.php
 * # 或
 * composer test:document-ocr
 * ```
 */

use Swoolefy\Support\DocumentOcr\Chunking\ChunkingAdapter;
use Swoolefy\Support\DocumentOcr\DocumentOcrFactory;
use Swoolefy\Support\DocumentOcr\Exceptions\DocumentException;
use Swoolefy\Support\DocumentOcr\Exceptions\ParserException;
use Swoolefy\Support\DocumentOcr\Exceptions\UnsupportedDocumentException;
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

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 在系统临时目录创建带随机后缀的测试文件。
 *
 * @param string $suffix   文件扩展名（含点，如 `.md`）
 * @param string $contents 写入内容
 */
function tempFile(string $suffix, string $contents): string
{
    $path = sys_get_temp_dir() . '/swoolefy_ocr_' . bin2hex(random_bytes(4)) . $suffix;
    file_put_contents($path, $contents);

    return $path;
}

// ---------------------------------------------------------------------------
// AutoParser：按扩展名与强制选项路由
// ---------------------------------------------------------------------------

/**
 * 验证 AutoParser 对 docx 选择 Pandoc，且 selectionReason 为 `structured_format:docx`。
 */
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

/**
 * 验证 AutoParser 对 png 选择 DeepSeek OCR，且 reason 为 `image_extension:png`。
 */
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

/**
 * 验证 AutoParser 对 pdf 直接走 DeepSeek OCR（非 Pandoc），reason 为 `pdf_direct_deepseek_ocr`。
 */
function testAutoParserSelectsOcrForPdf(): void
{
    $auto = new AutoParser([
        new PandocDriver(commandExecutor: static fn () => [0, '']),
        new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
    ]);

    [$parser, $reason] = $auto->select(new DocumentSource('/tmp/a.pdf', 'pdf'));
    assertTrue($parser->name() === DeepSeekOcrDriver::NAME, 'pdf → deepseek_ocr');
    assertTrue($reason === 'pdf_direct_deepseek_ocr', 'pdf selection reason');
}

/**
 * 验证 ParseOptions::parser 强制指定解析器，覆盖扩展名默认路由。
 */
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

/**
 * 验证不支持的扩展名（xlsx）抛 UnsupportedDocumentException，且继承 ParserException / DocumentException。
 */
function testUnsupportedExtensionThrows(): void
{
    $auto = new AutoParser([
        new PandocDriver(commandExecutor: static fn () => [0, '']),
        new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
    ]);

    try {
        $auto->select(new DocumentSource('/tmp/a.xlsx', 'xlsx'));
        throw new RuntimeException('xlsx should throw');
    } catch (UnsupportedDocumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'xlsx'), 'unsupported message');
        assertTrue($e instanceof ParserException, 'is ParserException');
        assertTrue($e instanceof DocumentException, 'is DocumentException');
    }
}

// ---------------------------------------------------------------------------
// PandocDriver：mock 执行器
// ---------------------------------------------------------------------------

/**
 * 验证 PandocDriver 在 mock 成功时产出 markdown、parserName 及标准 metadata 字段。
 */
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
    assertTrue(($result->metadata['parser'] ?? '') === 'pandoc', 'metadata.parser');
    assertTrue(($result->metadata['extension'] ?? '') === 'html', 'metadata.extension');
    assertTrue(isset($result->metadata['sourcePath']), 'metadata.sourcePath');
    assertTrue($result->sourceHash !== null, 'source hash set');

    @unlink($input);
}

/**
 * 验证 Pandoc 非零退出码时抛 ParserException，消息含 exit code。
 */
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
    } catch (ParserException $e) {
        assertTrue(str_contains($e->getMessage(), 'exit code 1'), 'exit code in message');
    }

    @unlink($input);
}

// ---------------------------------------------------------------------------
// DeepSeekOcrDriver：HTTP mock 与校验
// ---------------------------------------------------------------------------

/**
 * 验证图片 OCR 走 `/api/ocr` 端点（非 pdf），multipart 含文件，响应 markdown 写入结果。
 */
function testDeepSeekOcrDriverMock(): void
{
    $input = tempFile('.png', 'fake-png-bytes');
    $driver = new DeepSeekOcrDriver(
        httpClient: static function (string $uri, array $multipart): array {
            assertTrue(str_contains($uri, '/api/ocr'), 'ocr endpoint');
            assertTrue(!str_contains($uri, '/api/ocr/pdf'), 'not pdf endpoint');
            assertTrue(count($multipart) >= 1, 'multipart has file');

            return ['markdown' => "# OCR Result\n\n识别文字"];
        },
    );

    $result = $driver->parse((new LocalFileLoader())->load($input));
    assertTrue($result->parserName === 'deepseek_ocr', 'ocr parser');
    assertTrue(str_contains($result->markdown, '识别文字'), 'ocr markdown');
    assertTrue($result->selectionReason === 'image_extension:png', 'ocr reason');
    assertTrue(($result->metadata['parser'] ?? '') === 'deepseek_ocr', 'metadata.parser');
    assertTrue(isset($result->metadata['endpoint']), 'metadata.endpoint');

    @unlink($input);
}

/**
 * 验证 PDF 文件请求 `/api/ocr/pdf` 端点，且响应 `text` 字段映射为 markdown。
 */
function testDeepSeekOcrPdfUsesPdfEndpoint(): void
{
    $input = tempFile('.pdf', '%PDF-1.4 fake');
    $seenUri = '';
    $driver = new DeepSeekOcrDriver(
        httpClient: static function (string $uri, array $multipart) use (&$seenUri): array {
            $seenUri = $uri;

            return ['text' => 'PDF page text'];
        },
    );

    $result = $driver->parse((new LocalFileLoader())->load($input));
    assertTrue(str_contains($seenUri, '/api/ocr/pdf'), 'pdf endpoint used');
    assertTrue($result->selectionReason === 'pdf_direct_deepseek_ocr', 'pdf reason');
    assertTrue($result->markdown === 'PDF page text', 'pdf text field');

    @unlink($input);
}

/**
 * 验证 OCR 响应缺少 markdown/text 时抛 ParserException。
 */
function testDeepSeekOcrInvalidJsonFields(): void
{
    $input = tempFile('.jpg', 'bytes');
    $driver = new DeepSeekOcrDriver(
        maxRetryNum: 0,
        httpClient: static fn (): array => ['status' => 'ok'],
    );

    try {
        $driver->parse((new LocalFileLoader())->load($input));
        throw new RuntimeException('should fail on missing markdown');
    } catch (ParserException $e) {
        assertTrue(str_contains($e->getMessage(), 'markdown'), 'missing markdown');
    }

    @unlink($input);
}

/**
 * 验证超过 maxFileSize 时在上传前 fail-fast，抛 ParserException。
 */
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
    } catch (ParserException $e) {
        assertTrue(str_contains($e->getMessage(), 'max file size'), 'size message');
    }

    @unlink($input);
}

/**
 * 验证构造时 connectTimeout > timeout 抛 ParserException（参数合法性校验）。
 */
function testDeepSeekOcrTimeoutValidation(): void
{
    try {
        new DeepSeekOcrDriver(timeout: 2, connectTimeout: 3);
        throw new RuntimeException('should reject invalid timeout');
    } catch (ParserException $e) {
        assertTrue(str_contains($e->getMessage(), 'time_out'), 'timeout message');
    }
}

/**
 * 验证响应 `data.markdown` 嵌套字段可被正确提取为结果 markdown。
 */
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

/**
 * 验证 endpointFor() 按扩展名返回图片 `/api/ocr` 与 PDF `/api/ocr/pdf`。
 */
function testDeepSeekEndpointFor(): void
{
    $driver = new DeepSeekOcrDriver(httpClient: static fn (): array => ['markdown' => 'x']);
    assertTrue($driver->endpointFor('png') === '/api/ocr', 'image endpoint');
    assertTrue($driver->endpointFor('pdf') === '/api/ocr/pdf', 'pdf endpoint');
}

// ---------------------------------------------------------------------------
// Markdown 规范化与分块
// ---------------------------------------------------------------------------

/**
 * 验证 MarkdownNormalizer 折叠多余空行、统一换行并 trim 行首尾空白。
 */
function testMarkdownNormalizer(): void
{
    $n = new MarkdownNormalizer();
    $out = $n->normalize("  a\r\n\r\n\r\n\r\nb  ");
    assertTrue($out === "a\n\nb", 'normalize whitespace');
}

/**
 * 验证 ChunkingAdapter 将 ParseResult 切分为 Document，并设置 sourceName / sourceType。
 */
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

// ---------------------------------------------------------------------------
// DocumentOcrFactory 集成
// ---------------------------------------------------------------------------

/**
 * 验证 Factory::parseFile 经 Pandoc mock 后规范化多余空行；parseFileToDocuments 产出分块。
 */
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

/**
 * 验证 fromConfig 在 pandoc 禁用、deepseek_ocr 含 pdf 扩展时，pdf 仍路由到 OCR。
 */
function testFactoryFromConfigPdfEnabled(): void
{
    $factory = DocumentOcrFactory::fromConfig([
        'pandoc' => ['enabled' => false],
        'deepseek_ocr' => [
            'enabled' => true,
            'allowed_extensions' => ['png', 'jpg', 'jpeg', 'pdf'],
            'pdf_endpoint' => '/api/ocr/pdf',
        ],
    ]);

    $auto = $factory->parser();
    assertTrue($auto instanceof AutoParser, 'auto parser');
    [$parser, $reason] = $auto->select(new DocumentSource('/tmp/x.pdf', 'pdf'));
    assertTrue($parser->name() === 'deepseek_ocr', 'pdf driver from config');
    assertTrue($reason === 'pdf_direct_deepseek_ocr', 'pdf reason from config');
}

// ---------------------------------------------------------------------------
// Loader 与 WorkDirectory 边界
// ---------------------------------------------------------------------------

/**
 * 验证 LocalFileLoader 对不存在路径抛 DocumentException，消息含 not found。
 */
function testLocalFileLoaderMissingFile(): void
{
    try {
        (new LocalFileLoader())->load('/tmp/not-exists-' . uniqid('', true) . '.docx');
        throw new RuntimeException('should fail');
    } catch (DocumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'not found'), 'missing file');
    }
}

/**
 * 验证 WorkDirectory::removeDir 仅删除自身 job 目录，不越界删除外部路径。
 */
function testWorkDirectoryPathSafety(): void
{
    $base = sys_get_temp_dir() . '/swoolefy_ocr_safe_' . bin2hex(random_bytes(3));
    $work = new WorkDirectory($base);
    $job = $work->createJobDir('t');
    assertTrue(is_dir($job), 'job dir created');

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
    'auto selects ocr for pdf' => 'testAutoParserSelectsOcrForPdf',
    'forced parser' => 'testForcedParser',
    'unsupported extension throws' => 'testUnsupportedExtensionThrows',
    'pandoc mock executor' => 'testPandocDriverWithMockExecutor',
    'pandoc failure' => 'testPandocDriverFailure',
    'deepseek ocr mock' => 'testDeepSeekOcrDriverMock',
    'deepseek pdf endpoint' => 'testDeepSeekOcrPdfUsesPdfEndpoint',
    'deepseek invalid json' => 'testDeepSeekOcrInvalidJsonFields',
    'deepseek max file size' => 'testDeepSeekOcrMaxFileSize',
    'deepseek timeout validation' => 'testDeepSeekOcrTimeoutValidation',
    'deepseek data.markdown' => 'testDeepSeekOcrDataMarkdownField',
    'deepseek endpointFor' => 'testDeepSeekEndpointFor',
    'markdown normalizer' => 'testMarkdownNormalizer',
    'chunking adapter' => 'testChunkingAdapter',
    'factory parseFile normalize' => 'testFactoryParseFileNormalizes',
    'factory fromConfig pdf' => 'testFactoryFromConfigPdfEnabled',
    'loader missing file' => 'testLocalFileLoaderMissingFile',
    'work dir path safety' => 'testWorkDirectoryPathSafety',
];

foreach ($tests as $label => $fn) {
    $fn();
    echo "[OK] {$label}\n";
}

echo "All DocumentOcr tests passed.\n";
