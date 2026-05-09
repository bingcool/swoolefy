<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Emits *Api classes using GuzzleHttp\ClientInterface.
 */
final class SdkApiWriter
{
    public function __construct(
        private string $outputTestRoot,
        private string $sdkNamespacePrefix,
        private string $appNamespacePrefix,
    ) {
    }

    /**
     * @param list<array{methods:list<string>,path:string,controller:string,action:string,source?:string}> $routes
     */
    public function writeControllerApi(string $controller, array $routes): void
    {
        if (!class_exists($controller)) {
            return;
        }

        $rc = new \ReflectionClass($controller);
        $parentNs = $rc->getNamespaceName();
        $apiNs = preg_replace('/\\\\Controller$/', '\\Client', $parentNs);
        if ($apiNs === $parentNs) {
            $apiNs = $parentNs . '\\Client';
        }

        $apiNsTail = $apiNs;
        if (str_starts_with($apiNsTail, $this->appNamespacePrefix)) {
            $apiNsTail = substr($apiNsTail, strlen($this->appNamespacePrefix));
        }
        $fullApiNs = $this->sdkNamespacePrefix . '\\' . $apiNsTail;
        $shortApiName = preg_replace('/Controller$/', 'Api', $rc->getShortName());

        $byAction = [];
        foreach ($routes as $r) {
            $byAction[$r['action']][] = $r;
        }
        $byAction = $this->sortActionRoutesByControllerOrder($controller, $byAction);

        $uses = [
            $this->sdkNamespacePrefix . '\\Support\\BaseClientApi',
        ];

        $methodsPhp = [];

        foreach ($byAction as $action => $actionRoutes) {
            if (!method_exists($controller, $action)) {
                continue;
            }

            $method = new ReflectionMethod($controller, $action);
            $reqFqcn = $this->firstTestObjectParam($method);
            // 检查是否只有 RequestInput 类型的参数
            $hasOnlyRequestInput = $this->hasOnlyRequestInputParam($method);
            // 只有当参数是 RequestInput 时，才使用 array $params
            $passParamsAsArray = $hasOnlyRequestInput;
            // 获取标量参数列表
            $scalarParams = $this->getScalarParameters($method);
            $retFqcn = $this->returnTestClassName($method);
            $retScalarType = $this->returnScalarType($method);
            $isVoid = $this->isVoidReturn($method);
            $isNullable = $this->isReturnNullable($method);
            $isReqNullable = $this->isRequestParameterNullable($method);

            $actionRoutes = $this->dedupeActionRoutes($actionRoutes);
            $hasRequestDto = !$passParamsAsArray;
            $expanded = $this->expandActionRoutesForSdk($action, $actionRoutes, $hasRequestDto);

            if ($reqFqcn !== null) {
                $uses[] = $this->toSdkFqcn($reqFqcn);
            }
            if ($retFqcn !== null) {
                $uses[] = $this->toSdkFqcn($retFqcn);
                $uses[] = $this->sdkNamespacePrefix . '\\Support\\SdkCovertProperty';
            }

            $reqParam = $this->buildRequestParam($passParamsAsArray, $reqFqcn, $isReqNullable, $scalarParams);
            $retType = $this->determineReturnType($isVoid, $retFqcn, $retScalarType, $isNullable);
            // 构建返回类型声明，如果为空则不添加冒号
            $retTypeDecl = $retType !== '' ? ': ' . $retType : '';

            $reqShort = $reqFqcn !== null ? $this->toSdkShortClassName($reqFqcn) : null;

            $docBlock = $this->formatControllerActionDocblock($method);

            foreach ($expanded as $em) {
                $sdkMethodName = $em['sdkMethodName'];
                $httpMethod = $em['httpMethod'];
                $path = $em['path'];

                $body = $this->emitActionBody(
                    $httpMethod,
                    $path,
                    $reqShort,
                    $passParamsAsArray,
                    $retFqcn,
                    $isVoid,
                    $scalarParams,
                );

                $methodDoc = $this->appendSdkApiDocLine($docBlock, $httpMethod, $path);

                $methodsPhp[] = $methodDoc . <<<PHP
    public function {$sdkMethodName}({$reqParam}){$retTypeDecl}
    {
{$body}
    }

PHP;
            }
        }

        if ($methodsPhp === []) {
            return;
        }

        $uses = array_values(array_unique($uses));
        sort($uses);
        $useBlock = implode("\n", array_map(static fn (string $u) => 'use ' . $u . ';', $uses));

        $methodsBlock = implode("\n", $methodsPhp);

        $relPath = str_replace('\\', DIRECTORY_SEPARATOR, $apiNs);
        $appSeg = rtrim($this->appNamespacePrefix, '\\');
        $relPath = preg_replace(
            '/^' . preg_quote($appSeg, '/') . preg_quote(DIRECTORY_SEPARATOR, '/') . '/',
            '',
            $relPath
        ) ?? $relPath;
        $dir = $this->outputTestRoot . DIRECTORY_SEPARATOR . $relPath;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . DIRECTORY_SEPARATOR . $shortApiName . '.php';
        $php = <<<PHP
<?php

declare(strict_types=1);

namespace {$fullApiNs};

{$useBlock}

class {$shortApiName} extends BaseClientApi
{
{$methodsBlock}
}

PHP;
        file_put_contents($file, $php);
    }

    /**
     * Keep generated action methods in the same order as the controller source.
     *
     * @param array<string,list<array{methods:list<string>,path:string,controller:string,action:string,source?:string}>> $byAction
     * @return array<string,list<array{methods:list<string>,path:string,controller:string,action:string,source?:string}>>
     */
    private function sortActionRoutesByControllerOrder(string $controller, array $byAction): array
    {
        $positions = [];
        $rc = new \ReflectionClass($controller);
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $controller) {
                continue;
            }
            $positions[$method->getName()] = $method->getStartLine();
        }

        $ordered = [];
        foreach (array_keys($positions) as $action) {
            if (isset($byAction[$action])) {
                $ordered[$action] = $byAction[$action];
                unset($byAction[$action]);
            }
        }

        foreach ($byAction as $action => $actionRoutes) {
            $ordered[$action] = $actionRoutes;
        }

        return $ordered;
    }

    /**
     * Copy controller action docblock (trimmed) with 4-space indent for the generated client method.
     */
    private function formatControllerActionDocblock(ReflectionMethod $method): string
    {
        $doc = $method->getDocComment();
        if ($doc === false) {
            return '';
        }
        $doc = trim($doc);
        if ($doc === '' || !str_starts_with($doc, '/**') || !str_ends_with($doc, '*/')) {
            if ($doc === '') {
                return '';
            }
            $lines = preg_split('/\R/', $doc);

            return implode("\n", array_map(static fn (string $l) => '    ' . $l, $lines)) . "\n";
        }

        $inner = substr($doc, 3, -2);
        $rawLines = preg_split('/\R/', $inner);
        $bodyLines = [];
        foreach ($rawLines as $line) {
            $stripped = rtrim(preg_replace('/^\s*\*\s?/', '', $line) ?? $line);
            $bodyLines[] = $stripped;
        }
        while ($bodyLines !== [] && $bodyLines[0] === '') {
            array_shift($bodyLines);
        }
        while ($bodyLines !== [] && $bodyLines[array_key_last($bodyLines)] === '') {
            array_pop($bodyLines);
        }

        $out = ['    /**'];
        foreach ($bodyLines as $l) {
            $out[] = $l === '' ? '     *' : '     * ' . $l;
        }
        $out[] = '     */';

        return implode("\n", $out) . "\n";
    }

    /**
     * Inserts a line " * @api METHOD /path" before the closing star-slash of the indented docblock, or builds a minimal block.
     */
    private function appendSdkApiDocLine(string $indentedDocBlock, string $httpMethod, string $path): string
    {
        $verb = strtoupper($httpMethod);
        $defaultLine = '     * @api ' . $verb . ' ' . $path;

        $trimmed = rtrim($indentedDocBlock);
        if ($trimmed === '') {
            return "    /**\n{$defaultLine}\n     */\n";
        }

        $lines = explode("\n", $trimmed);
        $closeIdx = null;
        foreach ($lines as $idx => $line) {
            if (preg_match('/^\s*\*\/\s*$/', $line)) {
                $closeIdx = $idx;
            }
        }

        if ($closeIdx === null) {
            return $trimmed . "\n    /**\n{$defaultLine}\n     */\n";
        }

        $insertLine = $defaultLine;
        if ($closeIdx > 0) {
            $prev = $lines[$closeIdx - 1];
            if (preg_match('/^(\s+)\*\s/', $prev, $m)) {
                $insertLine = $m[1] . '* @api ' . $verb . ' ' . $path;
            }
        }

        array_splice($lines, $closeIdx, 0, [$insertLine]);

        return implode("\n", $lines) . "\n";
    }

    private function emitActionBody(
        string $httpMethod,
        string $path,
        ?string $requestShort,
        bool $passParamsAsArray,
        ?string $retTestFqcn,
        bool $isVoid,
        array $scalarParams = [],
    ): string {
        $pathLiteral = var_export($path, true);
        $lines = [];
        $lines[] = '        $requestDefaults = [];';
        
        if ($passParamsAsArray) {
            // RequestInput 类型，使用 $params
            $methodUpper = strtoupper($httpMethod);
            if (in_array($methodUpper, ['GET', 'HEAD', 'DELETE', 'OPTIONS'], true)) {
                $lines[] = '        if ($params !== []) {';
                $lines[] = '            $requestDefaults[\'query\'] = $params;';
                $lines[] = '        }';
            } else {
                $lines[] = '        $requestDefaults[\'body\'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);';
            }
        } elseif (!empty($scalarParams)) {
            // 有标量参数，构建查询或请求体
            $methodUpper = strtoupper($httpMethod);
            if (in_array($methodUpper, ['GET', 'HEAD', 'DELETE', 'OPTIONS'], true)) {
                // GET 等请求，将标量参数作为 query
                $lines[] = '        $queryParams = [];' ;
                foreach ($scalarParams as $param) {
                    // 提取参数名（去掉类型和默认值）
                    preg_match('/\$(\w+)/', $param, $matches);
                    if (!empty($matches)) {
                        $paramName = $matches[1];
                        $lines[] = "        if (isset(\${$paramName})) {";
                        $lines[] = "            \$queryParams['{$paramName}'] = \${$paramName};";
                        $lines[] = '        }';
                    }
                }
                $lines[] = '        if (!empty($queryParams)) {';
                $lines[] = '            $requestDefaults[\'query\'] = $queryParams;';
                $lines[] = '        }';
            } else {
                // POST 等请求，将标量参数作为 body
                $lines[] = '        $bodyData = [];' ;
                foreach ($scalarParams as $param) {
                    preg_match('/\$(\w+)/', $param, $matches);
                    if (!empty($matches)) {
                        $paramName = $matches[1];
                        $lines[] = "        \$bodyData['{$paramName}'] = \${$paramName};";
                    }
                }
                $lines[] = '        $requestDefaults[\'body\'] = json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);';
            }
        } elseif ($requestShort !== null) {
            // Request DTO 类型
            $lines[] = '        $requestDefaults[\'body\'] = json_encode($request->toDeepArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);';
        }
        
        $lines[] = '        $options = $this->mergeClientOptions($requestDefaults, $options);';
        $lines[] = '        $response = $this->httpClient->request(' . var_export($httpMethod, true) . ', $this->uri(' . $pathLiteral . '), $options);';
        $lines[] = '        $payload = $this->parseJsonResponse($response);';
        $lines[] = '        $this->assertBusinessOk($payload);';

        if ($isVoid) {
            $lines[] = '        return;';

            return implode("\n", $lines);
        }

        if ($retTestFqcn === null) {
            $lines[] = '        return $payload;';

            return implode("\n", $lines);
        }

        $lines[] = '        return SdkCovertProperty::toCovertDeepProperty($payload ?? null, ' . $this->toSdkShortClassName($retTestFqcn) . '::class);';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{path:string,httpMethod:string,sdkMethodName:string}>
     */
    private function expandActionRoutesForSdk(string $action, array $actionRoutes, bool $hasRequestDto): array
    {
        $resolved = [];
        $singleBuffer = [];

        $flushSingles = function () use (&$resolved, &$singleBuffer, $action, $hasRequestDto): void {
            if ($singleBuffer === []) {
                return;
            }
            $names = $this->assignSdkMethodNames($action, $singleBuffer, $hasRequestDto);
            foreach ($singleBuffer as $idx => $route) {
                $resolved[] = [
                    'path' => $route['path'],
                    'httpMethod' => $this->guessHttpMethod($route['methods'], $hasRequestDto),
                    'sdkMethodName' => $names[$idx],
                ];
            }
            $singleBuffer = [];
        };

        foreach ($actionRoutes as $route) {
            $source = $route['source'] ?? 'single';
            if ($source === 'any') {
                $flushSingles();
                foreach ($this->expandAnyRoute($action, $route) as $em) {
                    $resolved[] = $em;
                }
            } elseif ($source === 'match') {
                $flushSingles();
                foreach ($this->expandMatchRoute($action, $route) as $em) {
                    $resolved[] = $em;
                }
            } else {
                $singleBuffer[] = $route;
            }
        }
        $flushSingles();

        return $resolved;
    }

    /**
     * @param array{methods:list<string>,path:string,controller:string,action:string,source?:string} $route
     * @return list<array{path:string,httpMethod:string,sdkMethodName:string}>
     */
    private function expandAnyRoute(string $action, array $route): array
    {
        $path = $route['path'];
        $out = [
            ['path' => $path, 'httpMethod' => 'POST', 'sdkMethodName' => $action],
        ];
        foreach (['GET', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $verb) {
            $out[] = ['path' => $path, 'httpMethod' => $verb, 'sdkMethodName' => $action . 'Of' . $this->verbToPascal($verb)];
        }

        return $out;
    }

    /**
     * @param array{methods:list<string>,path:string,controller:string,action:string,source?:string} $route
     * @return list<array{path:string,httpMethod:string,sdkMethodName:string}>
     */
    private function expandMatchRoute(string $action, array $route): array
    {
        $path = $route['path'];
        $methods = array_values(array_unique(array_map('strtoupper', $route['methods'])));
        sort($methods);

        $out = [];
        if (in_array('POST', $methods, true)) {
            $out[] = ['path' => $path, 'httpMethod' => 'POST', 'sdkMethodName' => $action];
            foreach ($methods as $m) {
                if ($m === 'POST') {
                    continue;
                }
                $out[] = ['path' => $path, 'httpMethod' => $m, 'sdkMethodName' => $action . 'Of' . $this->verbToPascal($m)];
            }
        } else {
            foreach ($methods as $m) {
                $out[] = ['path' => $path, 'httpMethod' => $m, 'sdkMethodName' => $action . 'Of' . $this->verbToPascal($m)];
            }
        }

        return $out;
    }

    /**
     * Drop identical route rows (same path + same HTTP methods) so we do not emit duplicate client methods.
     *
     * @param list<array{methods:list<string>,path:string,controller:string,action:string,source?:string}> $routes
     * @return list<array{methods:list<string>,path:string,controller:string,action:string,source?:string}>
     */
    private function dedupeActionRoutes(array $routes): array
    {
        $seen = [];
        $out = [];
        foreach ($routes as $r) {
            $mcopy = $r['methods'];
            sort($mcopy);
            $key = $r['path'] . "\0" . implode(',', array_map('strtoupper', $mcopy));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Naming for `Route::get/post/...` rows only: one row keeps the action name; multiple rows use {action}Of{Verb}.
     *
     * @param list<array{methods:list<string>,path:string,controller:string,action:string,source?:string}> $routes
     * @return list<string>
     */
    private function assignSdkMethodNames(string $action, array $routes, bool $hasRequestDto): array
    {
        if (\count($routes) === 1) {
            return [$action];
        }

        $names = [];
        foreach ($routes as $route) {
            $verb = strtoupper($this->guessHttpMethod($route['methods'], $hasRequestDto));
            $names[] = $action . 'Of' . $this->verbToPascal($verb);
        }

        return $names;
    }

    private function verbToPascal(string $verb): string
    {
        return match ($verb) {
            'GET' => 'Get',
            'POST' => 'Post',
            'PUT' => 'Put',
            'PATCH' => 'Patch',
            'DELETE' => 'Delete',
            'HEAD' => 'Head',
            'OPTIONS' => 'Options',
            default => ucfirst(strtolower($verb)),
        };
    }

    private function guessHttpMethod(array $methods, bool $hasRequestDto): string
    {
        $methods = array_map('strtoupper', $methods);
        if ($hasRequestDto && in_array('POST', $methods, true)) {
            return 'POST';
        }

        return $methods[0] ?? 'GET';
    }

    private function firstTestObjectParam(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $param) {
            foreach ($this->reflectionTypesToClassNames($param->getType()) as $className) {
                if (str_starts_with($className, $this->appNamespacePrefix)) {
                    return $className;
                }
            }
        }

        return null;
    }

    private function returnTestClassName(ReflectionMethod $method): ?string
    {
        foreach ($this->reflectionTypesToClassNames($method->getReturnType()) as $className) {
            if (str_starts_with($className, $this->appNamespacePrefix)) {
                return $className;
            }
        }

        return null;
    }

    /**
     * 构建请求参数字符串
     */
    private function buildRequestParam(
        bool $passParamsAsArray,
        ?string $reqFqcn,
        bool $isReqNullable,
        array $scalarParams
    ): string {
        if ($passParamsAsArray) {
            return 'array $params = [], array $options = []';
        }

        $parts = [];
        
        // 添加 Request DTO 参数
        if ($reqFqcn !== null) {
            $parts[] = ($isReqNullable ? '?' : '') . $this->toSdkShortClassName($reqFqcn) . ' $request';
        }
        
        // 添加标量参数
        foreach ($scalarParams as $param) {
            $parts[] = $param;
        }
        
        // 添加 options 参数
        $parts[] = 'array $options = []';
        
        return implode(', ', $parts);
    }

    /**
     * 检查方法是否只有 RequestInput 类型的参数
     */
    private function hasOnlyRequestInputParam(ReflectionMethod $method): bool
    {
        $params = $method->getParameters();
        if (empty($params)) {
            return false;
        }
        
        foreach ($params as $param) {
            $type = $param->getType();
            if ($type === null) {
                continue;
            }
            
            // 检查是否是 RequestInput 类型
            foreach ($this->reflectionTypesToClassNames($type) as $className) {
                if (str_ends_with($className, '\\RequestInput') || $className === 'Swoolefy\\Http\\RequestInput') {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * 获取标量参数列表
     * @return array<string> 格式化的参数字符串数组
     */
    private function getScalarParameters(ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            
            // 跳过 RequestInput 和 Test\* 类型的参数
            if ($type !== null) {
                $skip = false;
                foreach ($this->reflectionTypesToClassNames($type) as $className) {
                    if (str_ends_with($className, '\\RequestInput') || 
                        $className === 'Swoolefy\\Http\\RequestInput' ||
                        str_starts_with($className, $this->appNamespacePrefix)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
            }
            
            // 构建标量参数声明
            $paramStr = '';
            if ($type !== null) {
                if ($type instanceof ReflectionNamedType) {
                    $typeName = $type->getName();
                    // 处理可空类型
                    if ($type->allowsNull() && $typeName !== 'mixed') {
                        $paramStr .= '?';
                    }
                    $paramStr .= $typeName . ' ';
                } elseif ($type instanceof ReflectionUnionType) {
                    // 联合类型，检查是否包含 null
                    $types = [];
                    $hasNull = false;
                    foreach ($type->getTypes() as $t) {
                        if ($t instanceof ReflectionNamedType) {
                            if ($t->getName() === 'null') {
                                $hasNull = true;
                            } else {
                                $types[] = $t->getName();
                            }
                        }
                    }
                    if ($hasNull && !empty($types)) {
                        $paramStr .= '?' . $types[0] . ' ';
                    } elseif (!empty($types)) {
                        $paramStr .= $types[0] . ' ';
                    }
                }
            }
            
            $paramStr .= '$' . $param->getName();
            
            // 添加默认值
            if ($param->isDefaultValueAvailable()) {
                $defaultValue = $param->getDefaultValue();
                $paramStr .= ' = ' . $this->formatDefaultValue($defaultValue);
            } elseif ($param->isOptional()) {
                $paramStr .= ' = []';
            }
            
            $params[] = $paramStr;
        }
        
        return $params;
    }

    /**
     * 格式化默认值
     */
    private function formatDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return var_export($value, true);
        }
        if (is_array($value)) {
            if (empty($value)) {
                return '[]';
            }
            return var_export($value, true);
        }
        return var_export($value, true);
    }

    /**
     * 检查返回类型是否可空
     */
    private function isReturnNullable(ReflectionMethod $method): bool
    {
        $t = $method->getReturnType();
        if ($t === null) {
            return false;
        }
        
        // 处理 ReflectionNamedType（包括可空类型如 ?LogResponse）
        if ($t instanceof ReflectionNamedType) {
            return $t->allowsNull() && $t->getName() !== 'mixed';
        }
        
        // 处理 ReflectionUnionType（如 LogResponse|null）
        if ($t instanceof ReflectionUnionType) {
            foreach ($t->getTypes() as $type) {
                if ($type instanceof ReflectionNamedType && $type->getName() === 'null') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 检查请求参数是否可空
     */
    private function isRequestParameterNullable(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type === null) {
                continue;
            }
            
            // 只检查第一个 Test\* 类型的参数
            foreach ($this->reflectionTypesToClassNames($type) as $className) {
                if (str_starts_with($className, $this->appNamespacePrefix)) {
                    // 处理 ReflectionNamedType（包括可空类型如 ?LogRequest）
                    if ($type instanceof ReflectionNamedType) {
                        return $type->allowsNull();
                    }
                    
                    // 处理 ReflectionUnionType（如 LogRequest|null）
                    if ($type instanceof ReflectionUnionType) {
                        foreach ($type->getTypes() as $t) {
                            if ($t instanceof ReflectionNamedType && $t->getName() === 'null') {
                                return true;
                            }
                        }
                    }
                    
                    return false;
                }
            }
        }

        return false;
    }

    private function determineReturnType(bool $isVoid, ?string $retFqcn, ?string $retScalarType, bool $isNullable = false): string
    {
        if ($isVoid) {
            return 'void';
        }

        if ($retFqcn !== null) {
            $shortName = $this->toSdkShortClassName($retFqcn);
            // 如果类型可空，添加 ? 前缀
            return $isNullable ? '?' . $shortName : $shortName;
        }

        if ($retScalarType !== null) {
            // 标量类型也可以可空
            return $isNullable ? '?' . $retScalarType : $retScalarType;
        }

        // 如果没有返回值类型，返回空字符串，表示不声明返回类型
        return '';
    }

    private function returnScalarType(ReflectionMethod $method): ?string
    {
        $t = $method->getReturnType();
        if (!$t instanceof ReflectionNamedType) {
            return null;
        }

        $typeName = $t->getName();
        // 只返回标量类型和 array，排除 void 和对象类型
        if (in_array($typeName, ['null','int', 'float', 'bool', 'string', 'array'], true)) {
            return 'array';
        }

        return null;
    }

    private function isVoidReturn(ReflectionMethod $method): bool
    {
        $t = $method->getReturnType();
        if ($t instanceof ReflectionNamedType) {
            return $t->getName() === 'void';
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function reflectionTypesToClassNames(?\ReflectionType $type): array
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

    private function toSdkFqcn(string $testClass): string
    {
        if (!str_starts_with($testClass, $this->appNamespacePrefix)) {
            return $testClass;
        }

        return $this->sdkNamespacePrefix . '\\' . substr($testClass, strlen($this->appNamespacePrefix));
    }

    private function toSdkShortClassName(string $testClass): string
    {
        $fqcn = $this->toSdkFqcn($testClass);
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

}
