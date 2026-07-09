<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Parsers;

use Swoolefy\Core\CommandRunner;
use Swoolefy\Support\DocumentOcr\Contracts\DocumentParserInterface;
use Swoolefy\Support\DocumentOcr\Exceptions\DocumentParseException;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;
use Swoolefy\Support\DocumentOcr\Schema\ParseOptions;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;
use Swoolefy\Support\DocumentOcr\WorkDirectory;
use Throwable;

/**
 * 通过 pandoc 将 DOCX / DOC / HTML / MD / TXT 转为 Markdown。
 *
 * 必须经 CommandRunner：exec 前调用 isNextHandle()，否则抛 Missing call isNextHandle()。
 * 输出写入 work_dir 文件再读取，不依赖 stdout 大文本。
 *
 * 单测可注入 $commandExecutor，避免依赖本机 pandoc。
 */
final class PandocDriver implements DocumentParserInterface
{
    public const NAME = 'pandoc';

    /**
     * @param list<string> $inputFormats 支持的扩展名
     * @param callable|null $commandExecutor
     *        签名：fn(string $bin, list<string> $rawArgs, string $outputFile): array{0:int,1:string}
     *        返回 [exitCode, message]；null 时走真实 CommandRunner
     */
    public function __construct(
        private readonly string $bin = 'pandoc',
        private readonly string $outputFormat = 'gfm',
        private readonly array $inputFormats = ['docx', 'doc', 'html', 'htm', 'md', 'txt'],
        private readonly string $runnerName = 'document-pandoc',
        private readonly int $concurrent = 2,
        private readonly WorkDirectory $workDirectory = new WorkDirectory('/tmp/swoolefy_document_ocr/pandoc'),
        /** @var callable|null */
        private $commandExecutor = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(DocumentSource $source): bool
    {
        return in_array(strtolower($source->extension), $this->inputFormats, true);
    }

    public function parse(DocumentSource $source, ?ParseOptions $options = null): ParseResult
    {
        if (!$this->supports($source)) {
            throw new DocumentParseException(
                'PandocDriver does not support extension: ' . $source->extension,
            );
        }

        $startedAt = microtime(true);
        $jobDir = $this->workDirectory->createJobDir('pandoc');
        $outputFile = $jobDir . DIRECTORY_SEPARATOR . 'output.md';
        $mediaDir = $jobDir . DIRECTORY_SEPARATOR . 'media';

        try {
            $rawArgs = [
                $source->path,
                '-t',
                $this->outputFormat,
                '-o',
                $outputFile,
                '--extract-media=' . $mediaDir,
            ];

            [$exitCode, $message] = $this->runPandoc($rawArgs, $outputFile);
            if ($exitCode !== 0) {
                throw new DocumentParseException(
                    'pandoc failed with exit code ' . $exitCode . ($message !== '' ? ': ' . $message : ''),
                );
            }

            if (!is_file($outputFile)) {
                throw new DocumentParseException('pandoc did not produce output file: ' . $outputFile);
            }

            $markdown = (string) file_get_contents($outputFile);

            return new ParseResult(
                markdown: $markdown,
                parserName: self::NAME,
                selectionReason: 'structured_format:' . $source->extension,
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                sourceHash: hash_file('sha256', $source->path) ?: null,
                metadata: [
                    'extension' => $source->extension,
                    'outputFormat' => $this->outputFormat,
                ],
            );
        } catch (DocumentParseException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DocumentParseException('pandoc parse failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->workDirectory->removeDir($jobDir);
        }
    }

    /**
     * @param list<string> $rawArgs
     *
     * @return array{0: int, 1: string}
     */
    private function runPandoc(array $rawArgs, string $outputFile): array
    {
        if ($this->commandExecutor !== null) {
            $result = ($this->commandExecutor)($this->bin, $rawArgs, $outputFile);
            if (!is_array($result) || !isset($result[0])) {
                throw new DocumentParseException('Invalid pandoc commandExecutor return value');
            }

            return [(int) $result[0], (string) ($result[1] ?? '')];
        }

        $runner = CommandRunner::getInstance($this->runnerName, max(1, $this->concurrent));
        // 文档要求：exec 前必须 isNextHandle
        if (!$runner->isNextHandle(true, 120)) {
            throw new DocumentParseException('pandoc runner concurrent limit reached, try again later');
        }

        // CommandRunner 数值键参数原样拼接，路径必须自行 escapeshellarg
        $input = (string) ($rawArgs[0] ?? '');
        $format = (string) ($rawArgs[2] ?? $this->outputFormat);
        $output = (string) ($rawArgs[4] ?? $outputFile);
        $media = (string) ($rawArgs[5] ?? '');
        if (str_starts_with($media, '--extract-media=')) {
            $media = substr($media, strlen('--extract-media='));
        }

        $shellArgs = [
            escapeshellarg($input),
            '-t',
            escapeshellarg($format),
            '-o',
            escapeshellarg($output),
            '--extract-media=' . escapeshellarg($media),
        ];

        try {
            [, $execOutput, $returnCode] = $runner->exec($this->bin, '', $shellArgs, false, '/dev/null', true);
        } catch (Throwable $e) {
            return [1, $e->getMessage()];
        }

        $message = is_array($execOutput) ? implode("\n", $execOutput) : (string) $execOutput;

        return [(int) $returnCode, $message];
    }
}
