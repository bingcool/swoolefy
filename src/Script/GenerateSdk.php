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

namespace Swoolefy\Script;

use Swoolefy\Script\Sdk\SdkCodeGenerator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * 生成 HTTP SDK（独立 Composer 包目录，可与 swoolefy 仓库同级）：
 * php script.php start {AppName} --c=gen:sdk [--router=Test/Router] [--out=../service-generate-sdk/swoolefy]
 *
 * - --out：项目包根目录（例如与 swoolefy 同级的 service-generate-sdk 下的 swoolefy 子目录），解析 **最后一级目录名** 作为「项目名」并转为 PascalCase 参与命名空间。
 * - 应用名来自命令中的 AppName（即常量 APP_NAME，如 Test）。
 * - 生成命名空间：GenerateSdk\\{ProjectPascal}\\{APP_NAME}\\...
 * - 文件输出：{out 绝对路径}/{APP_NAME}/...
 * - 若存在 {APP_NAME}/Common/Const 与 {APP_NAME}/Common/Enum，会递归复制其中 .php 并改写命名空间（与 DTO 一致）。
 */
class GenerateSdk extends MainCliScript
{
    /**
     * @var string
     */
    const command = 'gen:sdk';

    /**
     * @return void
     */
    public function handle()
    {
        $projectRoot = ROOT_PATH;
        $routerOpt = $this->getOption('router');
        $routerRel = (is_string($routerOpt) && $routerOpt !== '') ? $routerOpt : APP_NAME . '/Router';
        $outOpt = $this->getOption('out');
        $defaultOut = '..' . DIRECTORY_SEPARATOR . 'GenerateSdk' . DIRECTORY_SEPARATOR . basename($projectRoot);
        $outRel = (is_string($outOpt) && $outOpt !== '') ? $outOpt : $defaultOut;

        $routerDir = $this->toAbsoluteUnderRoot($projectRoot, $routerRel);
        $outputRoot = rtrim($this->toAbsoluteUnderRoot($projectRoot, $outRel), '/\\');

        $projectDirName = basename($outputRoot);
        $projectPascal = self::directoryNameToPascalCase($projectDirName);
        $sdkNamespacePrefix = 'GenerateSdk\\' . $projectPascal . '\\' . APP_NAME;

        $consoleOutput = $this->createGenSdkConsoleOutput();
        (new SdkCodeGenerator($projectRoot, $routerDir, $outputRoot, $sdkNamespacePrefix, $consoleOutput))->run();
    }

    /**
     * CLI 专用输出：StreamOutput(STDOUT)，decorated 为 null 时由 Symfony 按终端能力自动决定是否使用 ANSI（颜色与进度条样式）。
     * 若需固定开关颜色或传入自定义 Symfony OutputFormatter，在此集中调整即可。
     */
    private function createGenSdkConsoleOutput(): OutputInterface
    {
        return new StreamOutput(\STDOUT, StreamOutput::VERBOSITY_NORMAL, null);
    }

    /**
     * e.g. swoolefy → Swoolefy, my-app → MyApp
     */
    private static function directoryNameToPascalCase(string $name): string
    {
        $name = str_replace(['-', '_'], ' ', strtolower(trim($name)));

        return str_replace(' ', '', ucwords($name));
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
