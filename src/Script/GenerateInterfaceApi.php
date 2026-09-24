<?php

declare(strict_types=1);

namespace Swoolefy\Script;

use Swoolefy\Script\InterfaceApi\InterfaceApiSupportWriter;

/**
 * 初始化 InterfaceApi 契约包 Support 目录（注解 + 基础 DTO / Client 类）。
 *
 * 用法：
 *   php script.php start {AppName} --c=init::interface --out=/home/wwwroot/swoolefy
 *
 * 将在 {out}/InterfaceApi/Support 下生成 docs/InterfaceApi.md 第 4 节所需文件。
 */
class GenerateInterfaceApi extends MainCliScript
{
    public const command = 'init::interface';

    public function handle(): void
    {
        $outOpt = $this->getOption('out');
        if (!is_string($outOpt) || trim($outOpt) === '') {
            fmtPrintError('Missing required option --out=/path/to/output (e.g. --out=/home/wwwroot/swoolefy)');

            return;
        }

        $projectRoot = defined('ROOT_PATH') ? (string) constant('ROOT_PATH') : getcwd();
        $outputRoot = rtrim($this->toAbsoluteUnderRoot($projectRoot, trim($outOpt)), '/\\');
        $supportDir = $outputRoot . DIRECTORY_SEPARATOR . 'InterfaceApi' . DIRECTORY_SEPARATOR . 'Support';

        $written = (new InterfaceApiSupportWriter($supportDir))->writeAll();

        fmtPrintInfo(sprintf(
            'InterfaceApi Support generated: %s (%d files)',
            $supportDir,
            count($written),
        ));
    }

    private function toAbsoluteUnderRoot(string $root, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $root;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
