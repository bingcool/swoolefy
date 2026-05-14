<?php

declare(strict_types=1);

namespace Swoolefy\Script\ApiDoc;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\ArrayList;
use Swoolefy\Annotation\IntToString;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;
use Swoolefy\Http\BaseResponse;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Yaml\Yaml;

final class ApiDocGenerator
{
    private const DEFAULT_TITLE = '公共API';
    private const DEFAULT_DESCRIPTION = '公共API';

    /** @var array<string, array{title?: string, description?: string}> */
    private array $moduleMeta = [];

    public function __construct(
        private string $projectRoot,
        private string $routerDir,
        private string $outputDir,
        private ?OutputInterface $output = null,
    ) {
    }

    public function run(): void
    {
        $output = $this->output ?? new StreamOutput(\STDOUT);
        $this->moduleMeta = $this->loadModuleMeta();
        $routes = $this->scanRoutes($this->routerDir);
        if ($routes === []) {
            $output->writeln(sprintf(
                '<fg=yellow>[gen:apidoc] No routes with dispatch_route found under %s</fg=yellow>',
                OutputFormatter::escape($this->routerDir)
            ));
            return;
        }

        $this->ensureDir($this->outputDir);
        $this->cleanGeneratedOpenApiFiles();

        $byModule = [];
        foreach ($routes as $route) {
            $byModule[$route['module']][] = $route;
        }
        ksort($byModule);

        foreach ($byModule as $module => $moduleRoutes) {
            $doc = $this->buildOpenApiDocument($module, $moduleRoutes);
            $file = $this->outputDir . DIRECTORY_SEPARATOR . 'openapi-' . strtolower($module) . '.yaml';
            file_put_contents($file, Yaml::dump($doc, 12, 2, Yaml::DUMP_OBJECT_AS_MAP));
            $output->writeln(sprintf(
                '<info>[gen:apidoc]</info> <comment>%s</comment> routes:%d output:%s',
                OutputFormatter::escape($module),
                count($moduleRoutes),
                OutputFormatter::escape($file)
            ));
        }
    }

    /**
     * @param list<array{module:string,tag:string,methods:list<string>,path:string,controller:string,action:string,source:string}> $routes
     * @return array<string, mixed>
     */
    private function buildOpenApiDocument(string $module, array $routes): array
    {
        $meta = $this->moduleMeta[$module] ?? $this->moduleMeta[strtolower($module)] ?? [];
        $title = (string)($meta['title'] ?? self::DEFAULT_TITLE);
        $description = (string)($meta['description'] ?? self::DEFAULT_DESCRIPTION);

        $paths = [];
        usort($routes, static function (array $a, array $b): int {
            return [$a['path'], implode(',', $a['methods']), $a['controller'], $a['action']]
                <=> [$b['path'], implode(',', $b['methods']), $b['controller'], $b['action']];
        });

        foreach ($routes as $route) {
            foreach ($route['methods'] as $method) {
                $verb = strtolower($method);
                $paths[$route['path']][$verb] = $this->buildOperation($route, strtoupper($method));
            }
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $title,
                'description' => $description,
                'version' => '1.0.0',
            ],
            'paths' => $paths,
        ];
    }

    /**
     * @param array{module:string,tag:string,methods:list<string>,path:string,controller:string,action:string,source:string} $route
     * @return array<string, mixed>
     */
    private function buildOperation(array $route, string $method): array
    {
        $operation = [
            'tags' => [$route['tag']],
            'summary' => $route['action'],
            'operationId' => $this->operationId($method, $route['controller'], $route['action']),
        ];

        if (!class_exists($route['controller']) || !method_exists($route['controller'], $route['action'])) {
            $operation['responses'] = $this->responseObject($this->standardResponseSchema(['nullable' => true]));
            return $operation;
        }

        $reflection = new ReflectionMethod($route['controller'], $route['action']);
        $summary = $this->operationSummary($reflection);
        if ($summary !== '') {
            $operation['summary'] = $summary;
            $operation['description'] = $summary;
        }

        [$requestBody, $parameters] = $this->buildRequestSpec($reflection);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        $operation['responses'] = $this->responseObject($this->buildReturnDataSchema($reflection));

        return $operation;
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function buildRequestSpec(ReflectionMethod $method): array
    {
        $requestClass = null;
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $className = $this->firstClassType($parameter->getType());
            if ($className !== null && is_a($className, BaseRequest::class, true)) {
                $requestClass = $className;
                continue;
            }

            if ($className === null) {
                $parameters[] = $this->buildScalarParameter($parameter);
            }
        }

        $requestBody = null;
        if ($requestClass !== null) {
            $requestBody = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => $this->classSchema($requestClass),
                    ],
                ],
            ];
        }

        return [$requestBody, $parameters];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScalarParameter(ReflectionParameter $parameter): array
    {
        return [
            'name' => $parameter->getName(),
            'in' => 'query',
            'required' => !$parameter->isDefaultValueAvailable() && !$parameter->allowsNull(),
            'schema' => $this->schemaFromReflectionType($parameter->getType()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReturnDataSchema(ReflectionMethod $method): array
    {
        $type = $method->getReturnType();
        $className = $this->firstClassType($type);
        if ($className !== null) {
            if (is_a($className, BaseResponse::class, true)) {
                return $this->responseDataSchema($className);
            }

            return $this->classSchema($className);
        }

        return $this->schemaFromReflectionType($type);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseDataSchema(string $responseClass): array
    {
        if (!class_exists($responseClass)) {
            return ['nullable' => true];
        }

        try {
            $rc = new ReflectionClass($responseClass);
        } catch (\ReflectionException) {
            return ['nullable' => true];
        }

        if ($rc->hasProperty('data')) {
            return $this->schemaFromProperty($rc->getProperty('data'), []);
        }

        if ($rc->hasMethod('getData')) {
            $method = $rc->getMethod('getData');
            if ($method->getDeclaringClass()->getName() !== BaseResponse::class) {
                $returnType = $method->getReturnType();
                $className = $this->firstClassType($returnType);
                if ($className !== null) {
                    return $this->classSchema($className);
                }
                if ($returnType instanceof ReflectionNamedType && $returnType->isBuiltin() && $returnType->getName() !== 'array') {
                    return $this->schemaFromReflectionType($returnType);
                }
            }
        }

        return $this->classSchema($responseClass);
    }

    /**
     * @param array<string, mixed> $dataSchema
     * @return array<string, mixed>
     */
    private function standardResponseSchema(array $dataSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'integer',
                    'example' => 0,
                ],
                'msg' => [
                    'type' => 'string',
                    'example' => 'success',
                ],
                'trace_id' => [
                    'type' => 'string',
                    'example' => '',
                ],
                'data' => $dataSchema,
            ],
            'required' => ['code', 'msg', 'trace_id', 'data'],
        ];
    }

    /**
     * @param array<string, mixed> $dataSchema
     * @return array<string, mixed>
     */
    private function responseObject(array $dataSchema): array
    {
        return [
            '200' => [
                'description' => 'success',
                'content' => [
                    'application/json' => [
                        'schema' => $this->standardResponseSchema($dataSchema),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, bool> $seen
     * @return array<string, mixed>
     */
    private function classSchema(string $className, array $seen = []): array
    {
        if (!class_exists($className)) {
            return ['type' => 'object'];
        }
        if (isset($seen[$className])) {
            return ['type' => 'object'];
        }
        $seen[$className] = true;

        try {
            $rc = new ReflectionClass($className);
        } catch (\ReflectionException) {
            return ['type' => 'object'];
        }

        $properties = [];
        $required = [];
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE) as $property) {
            if ($property->isStatic() || $property->getDeclaringClass()->getName() === BaseRequest::class) {
                continue;
            }
            if ($property->getDeclaringClass()->getName() === BaseResponse::class) {
                continue;
            }

            $schema = $this->schemaFromProperty($property, $seen);
            $description = $this->apiPropertyDescription($property);
            if ($description !== '') {
                $schema['description'] = $description;
            }
            $properties[$property->getName()] = $schema;

            if ($this->isRequiredProperty($property)) {
                $required[] = $property->getName();
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param array<string, bool> $seen
     * @return array<string, mixed>
     */
    private function schemaFromProperty(ReflectionProperty $property, array $seen): array
    {
        if ($property->getAttributes(IntToString::class) !== []) {
            return ['type' => 'string'];
        }
        if ($property->getAttributes(StringToInt::class) !== []) {
            return ['type' => 'integer'];
        }

        $validation = $this->validationRule($property);
        if ($validation !== null) {
            $itemClass = $validation->getItemClass();
            if ($itemClass !== '' && class_exists($itemClass)) {
                return [
                    'type' => 'array',
                    'items' => $this->classSchema($itemClass, $seen),
                ];
            }

            if ($this->ruleContains($validation->getRule(), 'array')) {
                return [
                    'type' => 'array',
                    'items' => $this->schemaFromRule($validation->getItemRule() !== '' ? $validation->getItemRule() : 'string'),
                ];
            }
        }

        foreach ($property->getAttributes(ArrayList::class) as $attr) {
            try {
                $arrayList = $attr->newInstance();
            } catch (\Throwable) {
                continue;
            }
            $itemClass = $arrayList->getItemClass();
            if ($itemClass !== '' && class_exists($itemClass)) {
                return [
                    'type' => 'array',
                    'items' => $this->classSchema($itemClass, $seen),
                ];
            }
        }

        $className = $this->firstClassType($property->getType());
        if ($className !== null) {
            return $this->classSchema($className, $seen);
        }

        $schema = $this->schemaFromReflectionType($property->getType());
        if (($schema['type'] ?? null) === 'array') {
            $docItem = $this->docblockArrayItemSchema($property, $seen);
            if ($docItem !== null) {
                $schema['items'] = $docItem;
            }
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaFromReflectionType(?ReflectionType $type): array
    {
        if ($type === null) {
            return ['nullable' => true];
        }

        if ($type instanceof ReflectionUnionType) {
            $schema = ['nullable' => $type->allowsNull()];
            foreach ($type->getTypes() as $namedType) {
                if ($namedType instanceof ReflectionNamedType && $namedType->getName() !== 'null') {
                    $schema = array_merge($schema, $this->schemaFromNamedType($namedType));
                    break;
                }
            }
            return $schema;
        }

        if ($type instanceof ReflectionNamedType) {
            $schema = $this->schemaFromNamedType($type);
            if ($type->allowsNull()) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        return ['nullable' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaFromNamedType(ReflectionNamedType $type): array
    {
        if (!$type->isBuiltin()) {
            return $this->classSchema($type->getName());
        }

        return $this->schemaFromRule($type->getName());
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaFromRule(string $rule): array
    {
        $tokens = $this->ruleTokens($rule);
        foreach ($tokens as $token) {
            if (in_array($token, ['integer', 'int'], true)) {
                return ['type' => 'integer'];
            }
            if ($token === 'number' || $token === 'float' || $token === 'double') {
                return ['type' => 'number'];
            }
            if (in_array($token, ['boolean', 'bool'], true)) {
                return ['type' => 'boolean'];
            }
            if ($token === 'array') {
                return [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ];
            }
            if ($token === 'object') {
                return ['type' => 'object'];
            }
            if ($token === 'null') {
                return ['nullable' => true];
            }
        }

        return ['type' => 'string'];
    }

    /**
     * @param array<string, bool> $seen
     * @return ?array<string, mixed>
     */
    private function docblockArrayItemSchema(ReflectionProperty $property, array $seen): ?array
    {
        $doc = $property->getDocComment();
        if ($doc === false || $doc === '') {
            return null;
        }
        if (!preg_match('/@var\s+array\s*<\s*(?:int|string)\s*,\s*([^>|]+)(?:\|[^>]*)?>/i', $doc, $match)
            && !preg_match('/@var\s+array\s*<\s*([^>|]+)(?:\|[^>]*)?>/i', $doc, $match)
        ) {
            return null;
        }

        $inner = trim($match[1]);
        if ($inner === '') {
            return null;
        }
        $ruleSchema = $this->schemaFromRule($inner);
        if (($ruleSchema['type'] ?? null) !== 'string' || in_array(strtolower($inner), ['string'], true)) {
            return $ruleSchema;
        }

        $className = $this->resolveDocblockClass($property, $inner);
        if ($className !== null) {
            return $this->classSchema($className, $seen);
        }

        if (strtolower($inner) === 'mixed') {
            return ['nullable' => true];
        }

        return null;
    }

    private function resolveDocblockClass(ReflectionProperty $property, string $type): ?string
    {
        $type = ltrim(trim($type), '\\');
        if ($type === '' || in_array(strtolower($type), ['int', 'integer', 'string', 'float', 'bool', 'boolean', 'array', 'mixed'], true)) {
            return null;
        }
        if (class_exists($type)) {
            return $type;
        }

        $namespace = $property->getDeclaringClass()->getNamespaceName();
        if ($namespace !== '') {
            $candidate = $namespace . '\\' . $type;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function operationSummary(ReflectionMethod $method): string
    {
        foreach ($method->getAttributes(ApiOperation::class) as $attr) {
            try {
                return $attr->newInstance()->getDescription();
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }

    private function apiPropertyDescription(ReflectionProperty $property): string
    {
        foreach ($property->getAttributes(ApiProperty::class) as $attr) {
            try {
                return $attr->newInstance()->getDescription();
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }

    private function validationRule(ReflectionProperty $property): ?ValidationRule
    {
        foreach ($property->getAttributes(ValidationRule::class) as $attr) {
            try {
                return $attr->newInstance();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function isRequiredProperty(ReflectionProperty $property): bool
    {
        $validation = $this->validationRule($property);
        if ($validation === null) {
            return false;
        }

        $tokens = $this->ruleTokens($validation->getRule());
        foreach (['required', 'require', 'must'] as $requiredRule) {
            if (in_array($requiredRule, $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    private function ruleContains(string $rule, string $token): bool
    {
        return in_array(strtolower($token), $this->ruleTokens($rule), true);
    }

    /**
     * @return list<string>
     */
    private function ruleTokens(string $rule): array
    {
        $tokens = preg_split('/[|,\s:]+/', strtolower($rule), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($tokens ?: []));
    }

    private function operationId(string $method, string $controller, string $action): string
    {
        $short = str_contains($controller, '\\') ? substr($controller, strrpos($controller, '\\') + 1) : $controller;
        return strtolower($method) . $short . ucfirst($action);
    }

    private function firstClassType(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? null : $type->getName();
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $namedType) {
                if ($namedType instanceof ReflectionNamedType && !$namedType->isBuiltin()) {
                    return $namedType->getName();
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array{title?: string, description?: string}>
     */
    private function loadModuleMeta(): array
    {
        $file = $this->routerDir . DIRECTORY_SEPARATOR . 'api_router_module.json';
        if (!is_file($file)) {
            return [];
        }

        $json = file_get_contents($file);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $module => $meta) {
            if (!is_string($module) || !is_array($meta)) {
                continue;
            }
            $out[$module] = [
                'title' => isset($meta['title']) ? (string)$meta['title'] : self::DEFAULT_TITLE,
                'description' => isset($meta['description']) ? (string)$meta['description'] : self::DEFAULT_DESCRIPTION,
            ];
            $out[strtolower($module)] = $out[$module];
        }

        return $out;
    }

    /**
     * @return list<array{module:string,tag:string,methods:list<string>,path:string,controller:string,action:string,source:string}>
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
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (strtolower($file->getExtension()) === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return list<array{module:string,tag:string,methods:list<string>,path:string,controller:string,action:string,source:string}>
     */
    private function scanRouteFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $lines = explode("\n", $content);
        $namespace = $this->extractNamespace($content);
        $useAliases = $this->extractUseAliases($content);
        $routes = [];
        $groupPrefix = '';
        $module = $this->moduleNameFromRouteFile($path);
        $tag = $this->routeTagFromFile($path, $content);

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

            if (preg_match('/Route::(get|post|put|patch|delete|any|head|options)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $rm)) {
                $verb = strtoupper($rm[1]);
                $this->appendRouteFromChunk($routes, $module, $tag, $lines, $i, $verb, $rm[2], $groupPrefix, $namespace, $useAliases, $verb === 'ANY' ? 'any' : 'single');
            }

            if (preg_match('/Route::match\s*\(\s*\[/', $line)) {
                $chunk = $line;
                $methods = [];
                $uriPath = null;
                for ($k = $i; $k < min($i + 20, $n); $k++) {
                    $chunk .= ($k > $i ? "\n" . $lines[$k] : '');
                    if ($methods === [] && preg_match("/Route::match\s*\(\s*\[([^\]]+)\]/", $chunk, $mm)) {
                        preg_match_all("/['\"]([A-Za-z]+)['\"]/", $mm[1], $mv);
                        $methods = array_map('strtoupper', $mv[1] ?? []);
                    }
                    if ($uriPath === null && preg_match('/Route::match\s*\(\s*\[[^\]]+\]\s*,\s*[\'"]([^\'"]+)[\'"]/', $chunk, $um)) {
                        $uriPath = $um[1];
                    }
                    if ($methods !== [] && $uriPath !== null) {
                        break;
                    }
                }
                if ($methods !== [] && $uriPath !== null) {
                    $this->appendRouteFromChunk($routes, $module, $tag, $lines, $i, $methods, $uriPath, $groupPrefix, $namespace, $useAliases, 'match');
                }
            }
        }

        return $routes;
    }

    /**
     * @param list<array{module:string,tag:string,methods:list<string>,path:string,controller:string,action:string,source:string}> $routes
     * @param string|list<string> $methodOrMethods
     * @param array<string, string> $useAliases
     */
    private function appendRouteFromChunk(
        array &$routes,
        string $module,
        string $tag,
        array $lines,
        int $start,
        string|array $methodOrMethods,
        string $uriPath,
        string $groupPrefix,
        string $namespace,
        array $useAliases,
        string $source
    ): void {
        $chunk = '';
        for ($k = $start, $n = count($lines); $k < min($start + 120, $n); $k++) {
            $chunk .= ($k > $start ? "\n" : '') . $lines[$k];
            if (!preg_match(
                "/['\"]dispatch_route['\"]\s*=>\s*\[\s*([\\\\]?[A-Za-z_][A-Za-z0-9_\\\\]*)::class\s*,\s*['\"]([A-Za-z0-9_]+)['\"]\s*\]/",
                $chunk,
                $dm
            )) {
                continue;
            }

            $methods = is_array($methodOrMethods) ? $methodOrMethods : [$methodOrMethods];
            if ($methods === ['ANY']) {
                $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
            }
            $routes[] = [
                'module' => $module,
                'tag' => $tag,
                'methods' => array_values(array_unique(array_map('strtoupper', $methods))),
                'path' => $this->joinUriPath($groupPrefix, $uriPath),
                'controller' => $this->resolveClassName($dm[1], $namespace, $useAliases),
                'action' => $dm[2],
                'source' => $source,
            ];
            return;
        }
    }

    private function moduleNameFromRouteFile(string $path): string
    {
        $relative = ltrim(str_replace($this->routerDir, '', $path), '/\\');
        $parts = preg_split('#[\\\\/]#', $relative) ?: [];
        $first = (string)($parts[0] ?? 'Api');
        if (str_ends_with($first, '.php')) {
            $first = substr($first, 0, -4);
        }

        return $first !== '' ? $first : 'Api';
    }

    private function routeTagFromFile(string $path, string $content): string
    {
        $fileName = pathinfo($path, PATHINFO_FILENAME);
        $apiDescription = $this->extractFirstApiDocDescription($content);
        if ($apiDescription !== '') {
            return  $apiDescription. '(' . $fileName . ')';
        }

        return $fileName;
    }

    private function extractFirstApiDocDescription(string $content): string
    {
        if (!preg_match('/\/\*\*.*?@api\s+([^\r\n*]+).*?\*\//s', $content, $match)) {
            return '';
        }

        return trim($match[1]);
    }

    private function extractNamespace(string $content): string
    {
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $content, $match)) {
            return trim($match[1]);
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function extractUseAliases(string $content): array
    {
        $aliases = [];
        if (!preg_match_all('/^\s*use\s+([^;]+);/m', $content, $matches)) {
            return $aliases;
        }

        foreach ($matches[1] as $use) {
            $use = trim($use);
            if ($use === '' || str_contains($use, '{') || preg_match('/^(function|const)\s+/i', $use)) {
                continue;
            }
            if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $use, $match)) {
                $class = ltrim(trim($match[1]), '\\');
                $alias = trim($match[2]);
            } else {
                $class = ltrim($use, '\\');
                $pos = strrpos($class, '\\');
                $alias = $pos === false ? $class : substr($class, $pos + 1);
            }
            $aliases[$alias] = $class;
        }

        return $aliases;
    }

    /**
     * @param array<string, string> $useAliases
     */
    private function resolveClassName(string $className, string $namespace, array $useAliases): string
    {
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        $parts = explode('\\', $className, 2);
        $head = $parts[0];
        if (isset($useAliases[$head])) {
            return $useAliases[$head] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }

        return $namespace === '' ? $className : $namespace . '\\' . $className;
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

    private function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (is_file($path)) {
            throw new \RuntimeException("[gen:apidoc] Cannot create directory {$path}: a regular file exists at this path.");
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException("[gen:apidoc] mkdir failed: {$path}");
        }
    }

    private function cleanGeneratedOpenApiFiles(): void
    {
        $files = glob($this->outputDir . DIRECTORY_SEPARATOR . 'openapi-*.yaml') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
