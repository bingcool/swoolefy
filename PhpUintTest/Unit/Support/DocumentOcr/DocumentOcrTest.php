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

namespace PhpUintTest\Unit\Support\DocumentOcr;

use RuntimeException;
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
use PhpUintTest\TestCase;

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
 */
final class DocumentOcrTest extends TestCase
{
    /**
     * 在系统临时目录创建带随机后缀的测试文件。
     *
     * @param string $suffix   文件扩展名（含点，如 `.md`）
     * @param string $contents 写入内容
     */
    private function tempFile(string $suffix, string $contents): string
    {
        $path = sys_get_temp_dir() . '/swoolefy_ocr_' . bin2hex(random_bytes(4)) . $suffix;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * 验证 AutoParser 对 docx 选择 Pandoc，且 selectionReason 为 `structured_format:docx`。
     */
    public function testAutoParserSelectsPandocForDocx(): void
    {
        $auto = new AutoParser([
            new PandocDriver(commandExecutor: static fn () => [0, '']),
            new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
        ]);

        [$parser, $reason] = $auto->select(new DocumentSource('/tmp/a.docx', 'docx'));
        $this->assertTrue($parser->name() === PandocDriver::NAME, 'docx → pandoc');
        $this->assertTrue($reason === 'structured_format:docx', 'docx selection reason');
    }

    /**
     * 验证 AutoParser 对 png 选择 DeepSeek OCR，且 reason 为 `image_extension:png`。
     */
    public function testAutoParserSelectsOcrForPng(): void
    {
        $auto = new AutoParser([
            new PandocDriver(commandExecutor: static fn () => [0, '']),
            new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
        ]);

        [$parser, $reason] = $auto->select(new DocumentSource('/tmp/a.png', 'png'));
        $this->assertTrue($parser->name() === DeepSeekOcrDriver::NAME, 'png → deepseek_ocr');
        $this->assertTrue($reason === 'image_extension:png', 'png selection reason');
    }

    /**
     * 验证 AutoParser 对 pdf 直接走 DeepSeek OCR（非 Pandoc），reason 为 `pdf_direct_deepseek_ocr`。
     */
    public function testAutoParserSelectsOcrForPdf(): void
    {
        $auto = new AutoParser([
            new PandocDriver(commandExecutor: static fn () => [0, '']),
            new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
        ]);

        [$parser, $reason] = $auto->select(new DocumentSource('/tmp/a.pdf', 'pdf'));
        $this->assertTrue($parser->name() === DeepSeekOcrDriver::NAME, 'pdf → deepseek_ocr');
        $this->assertTrue($reason === 'pdf_direct_deepseek_ocr', 'pdf selection reason');
    }

    /**
     * 验证 ParseOptions::parser 强制指定解析器，覆盖扩展名默认路由。
     */
    public function testForcedParser(): void
    {
        $auto = new AutoParser([
            new PandocDriver(commandExecutor: static fn () => [0, '']),
            new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
        ]);

        [$parser, $reason] = $auto->select(
            new DocumentSource('/tmp/a.md', 'md'),
            new ParseOptions(parser: 'pandoc'),
        );
        $this->assertTrue($parser->name() === 'pandoc', 'forced pandoc');
        $this->assertTrue($reason === 'forced_parser:pandoc', 'forced reason');
    }

    /**
     * 验证不支持的扩展名（xlsx）抛 UnsupportedDocumentException，且继承 ParserException / DocumentException。
     */
    public function testUnsupportedExtensionThrows(): void
    {
        $auto = new AutoParser([
            new PandocDriver(commandExecutor: static fn () => [0, '']),
            new DeepSeekOcrDriver(httpClient: static fn () => ['markdown' => 'x']),
        ]);

        try {
            $auto->select(new DocumentSource('/tmp/a.xlsx', 'xlsx'));
            throw new RuntimeException('xlsx should throw');
        } catch (UnsupportedDocumentException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'xlsx'), 'unsupported message');
            $this->assertTrue($e instanceof ParserException, 'is ParserException');
            $this->assertTrue($e instanceof DocumentException, 'is DocumentException');
        }
    }

    /**
     * 验证 PandocDriver 在 mock 成功时产出 markdown、parserName 及标准 metadata 字段。
     */
    public function testPandocDriverWithMockExecutor(): void
    {
        $work = new WorkDirectory(sys_get_temp_dir() . '/swoolefy_ocr_pandoc_test');
        $input = $this->tempFile('.html', '<h1>Hello</h1>');

        $driver = new PandocDriver(
            workDirectory: $work,
            commandExecutor: function (string $bin, array $args, string $outputFile): array {
                $this->assertTrue($bin === 'pandoc', 'bin is pandoc');
                file_put_contents($outputFile, "# Hello\n\nFrom pandoc mock\n");

                return [0, 'ok'];
            },
        );

        $result = $driver->parse((new LocalFileLoader())->load($input));
        $this->assertTrue($result->parserName === 'pandoc', 'parser name');
        $this->assertTrue(str_contains($result->markdown, 'Hello'), 'markdown content');
        $this->assertTrue(($result->metadata['parser'] ?? '') === 'pandoc', 'metadata.parser');
        $this->assertTrue(($result->metadata['extension'] ?? '') === 'html', 'metadata.extension');
        $this->assertTrue(isset($result->metadata['sourcePath']), 'metadata.sourcePath');
        $this->assertTrue($result->sourceHash !== null, 'source hash set');

        @unlink($input);
    }

    /**
     * 验证 Pandoc 非零退出码时抛 ParserException，消息含 exit code。
     */
    public function testPandocDriverFailure(): void
    {
        $work = new WorkDirectory(sys_get_temp_dir() . '/swoolefy_ocr_pandoc_fail');
        $input = $this->tempFile('.txt', 'plain');

        $driver = new PandocDriver(
            workDirectory: $work,
            commandExecutor: static fn (): array => [1, 'boom'],
        );

        try {
            $driver->parse((new LocalFileLoader())->load($input));
            throw new RuntimeException('should fail');
        } catch (ParserException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'exit code 1'), 'exit code in message');
        }

        @unlink($input);
    }

    /**
     * 验证图片 OCR 走 `/api/ocr` 端点（非 pdf），multipart 含文件，响应 markdown 写入结果。
     */
    public function testDeepSeekOcrDriverMock(): void
    {
        $input = $this->tempFile('.png', 'fake-png-bytes');
        $driver = new DeepSeekOcrDriver(
            httpClient: function (string $uri, array $multipart): array {
                $this->assertTrue(str_contains($uri, '/api/ocr'), 'ocr endpoint');
                $this->assertTrue(!str_contains($uri, '/api/ocr/pdf'), 'not pdf endpoint');
                $this->assertTrue(count($multipart) >= 1, 'multipart has file');

                return ['markdown' => "# OCR Result\n\n识别文字"];
            },
        );

        $result = $driver->parse((new LocalFileLoader())->load($input));
        $this->assertTrue($result->parserName === 'deepseek_ocr', 'ocr parser');
        $this->assertTrue(str_contains($result->markdown, '识别文字'), 'ocr markdown');
        $this->assertTrue($result->selectionReason === 'image_extension:png', 'ocr reason');
        $this->assertTrue(($result->metadata['parser'] ?? '') === 'deepseek_ocr', 'metadata.parser');
        $this->assertTrue(isset($result->metadata['endpoint']), 'metadata.endpoint');

        @unlink($input);
    }

    /**
     * 验证 PDF 文件请求 `/api/ocr/pdf` 端点，且响应 `text` 字段映射为 markdown。
     */
    public function testDeepSeekOcrPdfUsesPdfEndpoint(): void
    {
        $input = $this->tempFile('.pdf', '%PDF-1.4 fake');
        $seenUri = '';
        $driver = new DeepSeekOcrDriver(
            httpClient: static function (string $uri, array $multipart) use (&$seenUri): array {
                $seenUri = $uri;

                return ['text' => 'PDF page text'];
            },
        );

        $result = $driver->parse((new LocalFileLoader())->load($input));
        $this->assertTrue(str_contains($seenUri, '/api/ocr/pdf'), 'pdf endpoint used');
        $this->assertTrue($result->selectionReason === 'pdf_direct_deepseek_ocr', 'pdf reason');
        $this->assertTrue($result->markdown === 'PDF page text', 'pdf text field');

        @unlink($input);
    }

    /**
     * 验证 OCR 响应缺少 markdown/text 时抛 ParserException。
     */
    public function testDeepSeekOcrInvalidJsonFields(): void
    {
        $input = $this->tempFile('.jpg', 'bytes');
        $driver = new DeepSeekOcrDriver(
            maxRetryNum: 0,
            httpClient: static fn (): array => ['status' => 'ok'],
        );

        try {
            $driver->parse((new LocalFileLoader())->load($input));
            throw new RuntimeException('should fail on missing markdown');
        } catch (ParserException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'markdown'), 'missing markdown');
        }

        @unlink($input);
    }

    /**
     * 验证超过 maxFileSize 时在上传前 fail-fast，抛 ParserException。
     */
    public function testDeepSeekOcrMaxFileSize(): void
    {
        $input = $this->tempFile('.png', str_repeat('x', 100));
        $driver = new DeepSeekOcrDriver(
            maxFileSize: 10,
            httpClient: static fn (): array => ['markdown' => 'x'],
        );

        try {
            $driver->parse((new LocalFileLoader())->load($input));
            throw new RuntimeException('should reject oversized file');
        } catch (ParserException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'max file size'), 'size message');
        }

        @unlink($input);
    }

    /**
     * 验证构造时 connectTimeout > timeout 抛 ParserException（参数合法性校验）。
     */
    public function testDeepSeekOcrTimeoutValidation(): void
    {
        try {
            new DeepSeekOcrDriver(timeout: 2, connectTimeout: 3);
            throw new RuntimeException('should reject invalid timeout');
        } catch (ParserException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'time_out'), 'timeout message');
        }
    }

    /**
     * 验证响应 `data.markdown` 嵌套字段可被正确提取为结果 markdown。
     */
    public function testDeepSeekOcrDataMarkdownField(): void
    {
        $input = $this->tempFile('.jpeg', 'img');
        $driver = new DeepSeekOcrDriver(
            httpClient: static fn (): array => ['data' => ['markdown' => 'from data.markdown']],
        );

        $result = $driver->parse((new LocalFileLoader())->load($input));
        $this->assertTrue($result->markdown === 'from data.markdown', 'data.markdown field');

        @unlink($input);
    }

    /**
     * 验证 endpointFor() 按扩展名返回图片 `/api/ocr` 与 PDF `/api/ocr/pdf`。
     */
    public function testDeepSeekEndpointFor(): void
    {
        $driver = new DeepSeekOcrDriver(httpClient: static fn (): array => ['markdown' => 'x']);
        $this->assertTrue($driver->endpointFor('png') === '/api/ocr', 'image endpoint');
        $this->assertTrue($driver->endpointFor('pdf') === '/api/ocr/pdf', 'pdf endpoint');
    }

    /**
     * 验证 MarkdownNormalizer 折叠多余空行、统一换行并 trim 行首尾空白。
     */
    public function testMarkdownNormalizer(): void
    {
        $n = new MarkdownNormalizer();
        $out = $n->normalize("  a\r\n\r\n\r\n\r\nb  ");
        $this->assertTrue($out === "a\n\nb", 'normalize whitespace');
    }

    /**
     * 验证 ChunkingAdapter 将 ParseResult 切分为 Document，并设置 sourceName / sourceType。
     */
    public function testChunkingAdapter(): void
    {
        $result = new ParseResult(
            markdown: "Para one.\n\nPara two is longer text.\n\nPara three.",
            parserName: 'pandoc',
            selectionReason: 'structured_format:md',
            sourceHash: 'abc',
        );

        $docs = (new ChunkingAdapter(maxChars: 50))->splitParseResult($result, sourceName: 'demo.md');
        $this->assertTrue(count($docs) >= 1, 'at least one chunk');
        $this->assertTrue($docs[0]->sourceName === 'demo.md', 'sourceName');
        $this->assertTrue($docs[0]->sourceType === 'document_ocr', 'sourceType');
    }

    /**
     * 验证 Factory::parseFile 经 Pandoc mock 后规范化多余空行；parseFileToDocuments 产出分块。
     */
    public function testFactoryParseFileNormalizes(): void
    {
        $input = $this->tempFile('.md', "# Title\n\n\n\nBody");
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
        $this->assertTrue($result->markdown === "# Title\n\nBody", 'factory normalizes');
        $this->assertTrue($result->parserName === 'pandoc', 'factory parser');

        $docs = $factory->parseFileToDocuments($input, sourceName: 'manual.md');
        $this->assertTrue(count($docs) >= 1, 'documents from factory');

        @unlink($input);
    }

    /**
     * 验证 fromConfig 在 pandoc 禁用、deepseek_ocr 含 pdf 扩展时，pdf 仍路由到 OCR。
     */
    public function testFactoryFromConfigPdfEnabled(): void
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
        $this->assertTrue($auto instanceof AutoParser, 'auto parser');
        [$parser, $reason] = $auto->select(new DocumentSource('/tmp/x.pdf', 'pdf'));
        $this->assertTrue($parser->name() === 'deepseek_ocr', 'pdf driver from config');
        $this->assertTrue($reason === 'pdf_direct_deepseek_ocr', 'pdf reason from config');
    }

    /**
     * 验证 LocalFileLoader 对不存在路径抛 DocumentException，消息含 not found。
     */
    public function testLocalFileLoaderMissingFile(): void
    {
        try {
            (new LocalFileLoader())->load('/tmp/not-exists-' . uniqid('', true) . '.docx');
            throw new RuntimeException('should fail');
        } catch (DocumentException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'not found'), 'missing file');
        }
    }

    /**
     * 验证 WorkDirectory::removeDir 仅删除自身 job 目录，不越界删除外部路径。
     */
    public function testWorkDirectoryPathSafety(): void
    {
        $base = sys_get_temp_dir() . '/swoolefy_ocr_safe_' . bin2hex(random_bytes(3));
        $work = new WorkDirectory($base);
        $job = $work->createJobDir('t');
        $this->assertTrue(is_dir($job), 'job dir created');

        $outside = sys_get_temp_dir() . '/swoolefy_ocr_outside_' . bin2hex(random_bytes(3));
        mkdir($outside);
        file_put_contents($outside . '/x.txt', 'keep');
        $work->removeDir($outside);
        $this->assertTrue(is_file($outside . '/x.txt'), 'outside dir not deleted');

        $work->removeDir($job);
        @unlink($outside . '/x.txt');
        @rmdir($outside);
        @rmdir($base);
    }
}
