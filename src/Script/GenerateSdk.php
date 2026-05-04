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
 * 执行命令生成 HTTP SDK： php script.php start Test --c=gen:sdk [--router=Test/Router] [--out=src/GenerateSdk]
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
        $routerRel = (is_string($routerOpt) && $routerOpt !== '') ? $routerOpt : 'Test/Router';
        $outOpt = $this->getOption('out');
        $outRel = (is_string($outOpt) && $outOpt !== '') ? $outOpt : 'swoolefy/GenerateSdk';

        $routerDir = $this->toAbsoluteUnderRoot($projectRoot, $routerRel);
        $outputRoot = $this->toAbsoluteUnderRoot($projectRoot, $outRel);

        (new SdkCodeGenerator($projectRoot, $routerDir, $outputRoot))->run();
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
