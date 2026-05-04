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

/**
 * 生成 HTTP SDK（独立 Composer 包目录，可与 swoolefy 仓库同级）：
 * php script.php start {AppName} --c=gen:sdk [--router=Test/Router] [--out=../GenerateSdk/swoolefy]
 *
 * - --out：项目包根目录（例如与 swoolefy 同级的 GenerateSdk 下的 swoolefy 子目录），解析 **最后一级目录名** 作为「项目名」并转为 PascalCase 参与命名空间。
 * - 应用名来自命令中的 AppName（即常量 APP_NAME，如 Test）。
 * - 生成命名空间：GenerateSdk\\{ProjectPascal}\\{APP_NAME}\\...
 * - 文件输出：{out 绝对路径}/{APP_NAME}/...
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

        (new SdkCodeGenerator($projectRoot, $routerDir, $outputRoot, $sdkNamespacePrefix))->run();
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
