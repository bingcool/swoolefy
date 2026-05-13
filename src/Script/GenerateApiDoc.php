<?php

namespace Swoolefy\Script;

use Swoolefy\Script\ApiDoc\ApiDocGenerator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Generate OpenAPI YAML documents from {APP_NAME}/Router.
 *
 * php script.php start {AppName} --c=gen:apidoc [--router=Test/Router] [--out=swaggerui/apidoc]
 */
class GenerateApiDoc extends MainCliScript
{
    const command = 'gen:apidoc';

    public function handle()
    {
        $projectRoot = ROOT_PATH;
        $routerOpt = $this->getOption('router');
        $routerRel = (is_string($routerOpt) && $routerOpt !== '') ? $routerOpt : APP_NAME . '/Router';
        $outOpt = $this->getOption('out');
        $outRel = (is_string($outOpt) && $outOpt !== '') ? $outOpt : 'swaggerui/apidoc';

        $routerDir = $this->toAbsoluteUnderRoot($projectRoot, $routerRel);
        $outputDir = rtrim($this->toAbsoluteUnderRoot($projectRoot, $outRel), '/\\');

        (new ApiDocGenerator($projectRoot, $routerDir, $outputDir, $this->createConsoleOutput()))->run();
    }

    private function createConsoleOutput(): OutputInterface
    {
        return new StreamOutput(\STDOUT, StreamOutput::VERBOSITY_NORMAL, null);
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
