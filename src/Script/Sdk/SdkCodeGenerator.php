<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Scans {App}/Router, copies {APP_NAME}\* DTOs into GenerateSdk\{ProjectName}\{AppName}\*, emits *Api clients (Guzzle).
 */
final class SdkCodeGenerator
{
    /** e.g. Test\\ or Shop\\ — from APP_NAME */
    private string $appNamespacePrefix;

    public function __construct(
        private string $projectRoot,
        private string $routerDir,
        private string $outputRoot,
        private string $sdkNamespacePrefix,
        private ?OutputInterface $output = null,
    ) {
        $this->appNamespacePrefix = APP_NAME . '\\';
    }

    public function run(): void
    {
        $output = $this->output ?? new StreamOutput(\STDOUT);

        $appOut = $this->outputRoot . DIRECTORY_SEPARATOR . APP_NAME;

        $this->ensureDir($appOut . '/Support');

        $supportNs = $this->sdkNamespacePrefix . '\\Support';
        $support = new SdkSupportWriter($appOut . DIRECTORY_SEPARATOR . 'Support', $supportNs);
        $support->writeAll();

        $routes = $this->scanRoutes($this->routerDir);
        if ($routes === []) {
            fwrite(STDERR, "[gen:sdk] No routes with dispatch_route found under {$this->routerDir}\n");
            return;
        }

        $this->writeDuplicateDispatchRouteHints($routes, $output);

        $byController = [];
        foreach ($routes as $route) {
            $ctrl = $route['controller'];
            $byController[$ctrl][] = $route;
        }

        $allDtoClasses = [];
        $routeBar = $this->createColoredProgressBar($output, count($routes), '路由API');
        $routeBar->start();
        foreach ($routes as $route) {
            foreach ($this->collectDtoClassesForAction($route['controller'], $route['action']) as $c) {
                $allDtoClasses[$c] = true;
            }
            $routeBar->advance();
        }
        $routeBar->finish();
        $output->writeln('');

        $dtoList = $this->expandTestDtoClosure(array_keys($allDtoClasses));
        sort($dtoList);

        $writer = new SdkDtoWriter($this->projectRoot, $appOut, $this->sdkNamespacePrefix, $this->appNamespacePrefix);
        $writer->copyCommonConstAndEnumTrees();
        if ($dtoList !== []) {
            $dtoBar = $this->createColoredProgressBar($output, count($dtoList), 'DTO');
            $dtoBar->start();
            foreach ($dtoList as $className) {
                $writer->writeClass($className);
                $dtoBar->advance();
            }
            $dtoBar->finish();
            $output->writeln('');
        }

        $apiWriter = new SdkApiWriter($appOut, $this->sdkNamespacePrefix, $this->appNamespacePrefix);
        foreach ($byController as $controller => $ctrlRoutes) {
            $apiWriter->writeControllerApi($controller, $ctrlRoutes);
        }

        $output->writeln(sprintf(
            '<info>[gen:sdk]</info> <comment>namespace:</comment> %s, <comment>routes:</comment> %d, <comment>DTO classes:</comment> %d, <comment>output:</comment> %s',
            OutputFormatter::escape($this->sdkNamespacePrefix),
            count($routes),
            count($dtoList),
            OutputFormatter::escape($appOut)
        ));
    }

    private function createColoredProgressBar(OutputInterface $output, int $max, string $message): ProgressBar
    {
        $bar = new ProgressBar($output, $max);
        $bar->setBarWidth(32);
        $bar->setBarCharacter('<fg=green>█</>');
        $bar->setEmptyBarCharacter('<fg=gray>░</>');
        $bar->setProgressCharacter('<fg=green;options=bold>▓</>');
        $bar->setFormat(
            ' <fg=cyan;options=bold>●</> '
            . '<info>%current%</info><comment>/</comment><info>%max%</info> '
            . '[%bar%] '
            . '<comment>%percent:3s%%</comment> '
            . '<fg=magenta>—</> '
            . '<fg=yellow;options=bold>%message%</>'
        );
        $bar->setMessage($message);

        return $bar;
    }

    /**
     * Warn when multiple routes target the same controller action. Each entry is shown as path(HTTP_METHODS).
     *
     * @param list<array{methods:list<string>,path:string,controller:string,action:string}> $routes
     */
    private function writeDuplicateDispatchRouteHints(array $routes, OutputInterface $output): void
    {
        $groups = [];
        foreach ($routes as $route) {
            $key = $route['controller'] . "\0" . $route['action'];
            $groups[$key][] = $route;
        }

        foreach ($groups as $items) {
            if (\count($items) < 2) {
                continue;
            }

            $sample = $items[0];
            $controller = $sample['controller'];
            $action = $sample['action'];

            $shortCtrl = str_contains($controller, '\\')
                ? substr($controller, strrpos($controller, '\\') + 1)
                : $controller;

            usort($items, static function (array $a, array $b): int {
                $cmp = $a['path'] <=> $b['path'];
                if ($cmp !== 0) {
                    return $cmp;
                }
                $am = $a['methods'];
                $bm = $b['methods'];
                sort($am);
                sort($bm);

                return implode(',', array_map('strtoupper', $am)) <=> implode(',', array_map('strtoupper', $bm));
            });

            $segments = [];
            foreach ($items as $r) {
                $mcopy = $r['methods'];
                sort($mcopy);
                $verbs = implode(',', array_map('strtoupper', $mcopy));
                $segments[] = $r['path'] . '(' . $verbs . ')';
            }
            $apiList = implode(', ', $segments);

            $output->writeln(sprintf(
                '<fg=yellow>[gen:sdk] %s%s ->[%s, %s]</fg=yellow>',
                "存在相同指向api：",
                OutputFormatter::escape($apiList),
                OutputFormatter::escape($shortCtrl),
                OutputFormatter::escape($action)
            ));
        }
    }

    /**
     * @return list<array{methods:list<string>,path:string,controller:string,action:string}>
     */
    private function scanRoutes(string $dir): array
    {
        $routes = [];
        foreach ($this->listPhpFiles($dir) as $file) {
            $routes = array_merge($routes, $this->scanRouteFile($file));
        }

        return $routes;
    }

    /**
     * @return list<string>
     */
    private function listPhpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (strtolower($f->getExtension()) === 'php') {
                $out[] = $f->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @return list<array{methods:list<string>,path:string,controller:string,action:string}>
     */
    private function scanRouteFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $lines = explode("\n", $content);
        $routes = [];
        $groupPrefix = '';

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];

            if (preg_match('/Route::group\s*\(\s*\[/', $line)) {
                for ($j = $i; $j < min($i + 40, $n); $j++) {
                    if (preg_match("/['\"]prefix['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $lines[$j], $pm)) {
                        $groupPrefix = $pm[1];
                        break;
                    }
                }
            }

            if (preg_match('/^\s*\}\);\s*$/', $line)) {
                $groupPrefix = '';
            }

            if (preg_match('/Route::(get|post|put|delete|any|head|options)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $rm)) {
                $verb = strtoupper($rm[1]);
                $uriPath = $rm[2];
                $chunk = $line;
                for ($k = $i + 1; $k < min($i + 120, $n); $k++) {
                    $chunk .= "\n" . $lines[$k];
                    if (preg_match(
                        "/['\"]dispatch_route['\"]\s*=>\s*\[\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*,\s*['\"]([A-Za-z0-9_]+)['\"]\s*\]/",
                        $chunk,
                        $dm
                    )) {
                        $controller = $dm[1];
                        $action = $dm[2];
                        $fullPath = $this->joinUriPath($groupPrefix, $uriPath);
                        $methods = $verb === 'ANY'
                            ? ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS']
                            : [$verb];
                        $routes[] = [
                            'methods' => $methods,
                            'path' => $fullPath,
                            'controller' => $controller,
                            'action' => $action,
                        ];
                        break;
                    }
                }
            }

            if (preg_match('/Route::match\s*\(\s*\[/', $line)) {
                $chunk = $line;
                $methods = [];
                if (preg_match("/Route::match\s*\(\s*\[([^\]]+)\]/", $line, $mm)) {
                    preg_match_all("/['\"]([A-Z]+)['\"]/", $mm[1], $mv);
                    $methods = $mv[1] ?? [];
                }
                if (preg_match('/Route::match\s*\(\s*\[[^\]]+\]\s*,\s*[\'"]([^\'"]+)[\'"]/', $line, $um)) {
                    $uriPath = $um[1];
                } else {
                    for ($k = $i + 1; $k < min($i + 10, $n); $k++) {
                        if (preg_match('/^\s*,\s*[\'"]([^\'"]+)[\'"]/', $lines[$k], $um)) {
                            $uriPath = $um[1];
                            $chunk .= "\n" . $lines[$k];
                            break;
                        }
                        $chunk .= "\n" . $lines[$k];
                    }
                }
                if (($methods !== []) && isset($uriPath)) {
                    for ($k = $i; $k < min($i + 120, $n); $k++) {
                        $chunk .= ($k === $i ? '' : "\n" . $lines[$k]);
                    }
                    $chunk = $line;
                    for ($k = $i; $k < min($i + 120, $n); $k++) {
                        $chunk .= ($k > $i ? "\n" . $lines[$k] : '');
                        if (preg_match(
                            "/['\"]dispatch_route['\"]\s*=>\s*\[\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*,\s*['\"]([A-Za-z0-9_]+)['\"]\s*\]/",
                            $chunk,
                            $dm
                        )) {
                            $controller = $dm[1];
                            $action = $dm[2];
                            $fullPath = $this->joinUriPath($groupPrefix, $uriPath);
                            $routes[] = [
                                'methods' => array_map('strtoupper', $methods),
                                'path' => $fullPath,
                                'controller' => $controller,
                                'action' => $action,
                            ];
                            break;
                        }
                    }
                }
            }
        }

        return $routes;
    }

    private function joinUriPath(string $prefix, string $path): string
    {
        $prefix = trim($prefix, '/');
        $path = trim($path, '/');
        if ($prefix === '') {
            return '/' . $path;
        }
        if ($path === '') {
            return '/' . $prefix;
        }

        return '/' . $prefix . '/' . $path;
    }

    /**
     * @return list<string> Test\* class names
     */
    private function collectDtoClassesForAction(string $controller, string $action): array
    {
        if (!class_exists($controller)) {
            fwrite(STDERR, "[gen:sdk] Skip unknown controller {$controller}::{$action}\n");
            return [];
        }

        if (!method_exists($controller, $action)) {
            fwrite(STDERR, "[gen:sdk] Skip unknown action {$controller}::{$action}\n");
            return [];
        }

        $method = new ReflectionMethod($controller, $action);
        $classes = [];

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $names = $this->typesToClassNames($type);
            foreach ($names as $name) {
                if ($this->isTestDtoClass($name)) {
                    $classes[$name] = true;
                    foreach ($this->collectPropertyTypes($name) as $dep) {
                        $classes[$dep] = true;
                    }
                }
            }
        }

        $ret = $method->getReturnType();
        foreach ($this->typesToClassNames($ret) as $name) {
            if ($this->isTestDtoClass($name)) {
                $classes[$name] = true;
                foreach ($this->collectPropertyTypes($name) as $dep) {
                    $classes[$dep] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * Breadth-first: seed DTOs plus Test\* classes referenced from properties, annotations (itemClass, ResponseProperty), and @var array<X> docblocks.
     *
     * @param list<string> $seed
     * @return list<string>
     */
    private function expandTestDtoClosure(array $seed): array
    {
        $seen = [];
        $queue = $seed;
        while ($queue !== []) {
            $c = array_pop($queue);
            if (!is_string($c) || !str_starts_with($c, $this->appNamespacePrefix)) {
                continue;
            }
            if (isset($seen[$c])) {
                continue;
            }
            if (!class_exists($c)) {
                continue;
            }
            $seen[$c] = true;

            foreach ($this->collectPropertyTypes($c) as $d) {
                if (!isset($seen[$d])) {
                    $queue[] = $d;
                }
            }
            foreach (SdkDtoReflection::collectLinkedTestClassesFromAttributes($c, $this->appNamespacePrefix) as $d) {
                if (!isset($seen[$d])) {
                    $queue[] = $d;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * @return list<string>
     */
    private function collectPropertyTypes(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        $out = [];
        try {
            $rc = new ReflectionClass($className);
        } catch (\ReflectionException) {
            return [];
        }

        foreach ($rc->getProperties() as $prop) {
            $t = $prop->getType();
            foreach ($this->typesToClassNames($t) as $name) {
                if ($this->isTestDtoClass($name)) {
                    $out[$name] = true;
                    foreach ($this->collectPropertyTypes($name) as $d) {
                        $out[$d] = true;
                    }
                }
            }
        }

        return array_keys($out);
    }

    private function typesToClassNames(?\ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return [];
            }

            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) {
                    $names[] = $t->getName();
                }
            }

            return $names;
        }

        return [];
    }

    private function isTestDtoClass(string $name): bool
    {
        return str_starts_with($name, $this->appNamespacePrefix);
    }

    private function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (is_file($path)) {
            throw new \RuntimeException(
                "[gen:sdk] Cannot create directory {$path}: a regular file exists at this path. "
                . 'Use --out= to pick the SDK package root (e.g. ../GenerateSdk/swoolefy).'
            );
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException("[gen:sdk] mkdir failed: {$path}");
        }
    }
}
