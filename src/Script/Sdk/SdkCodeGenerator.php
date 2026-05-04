<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Scans {App}/Router, copies Test\* DTOs into GenerateSdk\{Project}\{AppName}\*, emits *Api clients (Guzzle).
 */
final class SdkCodeGenerator
{
    private const TEST_NAMESPACE_PREFIX = 'Test\\';

    public function __construct(
        private string $projectRoot,
        private string $routerDir,
        private string $outputRoot,
        private string $sdkNamespacePrefix,
    ) {
    }

    public function run(): void
    {
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

        $byController = [];
        foreach ($routes as $route) {
            $ctrl = $route['controller'];
            $byController[$ctrl][] = $route;
        }

        $allDtoClasses = [];
        foreach ($byController as $controller => $ctrlRoutes) {
            foreach ($ctrlRoutes as $r) {
                foreach ($this->collectDtoClassesForAction($controller, $r['action']) as $c) {
                    $allDtoClasses[$c] = true;
                }
            }
        }

        $dtoList = $this->expandTestDtoClosure(array_keys($allDtoClasses));
        sort($dtoList);

        $writer = new SdkDtoWriter($this->projectRoot, $appOut, $this->sdkNamespacePrefix);
        foreach ($dtoList as $className) {
            $writer->writeClass($className);
        }

        $apiWriter = new SdkApiWriter($appOut, $this->sdkNamespacePrefix);
        foreach ($byController as $controller => $ctrlRoutes) {
            $apiWriter->writeControllerApi($controller, $ctrlRoutes);
        }

        $this->writePackageComposerJson();

        echo '[gen:sdk] namespace: ' . $this->sdkNamespacePrefix . ', routes: ' . count($routes) . ', DTO classes: ' . count($dtoList) . ', output: ' . $appOut . "\n";
    }

    private function writePackageComposerJson(): void
    {
        $pkg = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', basename($this->outputRoot) . '-' . APP_NAME));
        $pkg = trim($pkg, '-');
        $manifest = [
            'name' => 'generatesdk/' . ($pkg !== '' ? $pkg : 'sdk'),
            'description' => 'Auto-generated HTTP SDK (' . APP_NAME . ')',
            'license' => 'MIT',
            'require' => [
                'php' => '>=8.4',
                'guzzlehttp/guzzle' => '^7.9',
            ],
            'autoload' => [
                'psr-4' => [
                    $this->sdkNamespacePrefix . '\\' => 'Test/',
                ],
            ],
        ];
        file_put_contents(
            $this->outputRoot . DIRECTORY_SEPARATOR . 'composer.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
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
            if (!is_string($c) || !str_starts_with($c, self::TEST_NAMESPACE_PREFIX)) {
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
            foreach (SdkDtoReflection::collectLinkedTestClassesFromAttributes($c) as $d) {
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
        return str_starts_with($name, self::TEST_NAMESPACE_PREFIX);
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
