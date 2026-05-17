<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

/**
 * Minimal framework stubs for generated SDK DTOs (no Swoole / Http runtime).
 */
final class SdkSupportWriter
{
    public function __construct(
        private string $supportDir,
        private string $supportNamespace,
    ) {
    }

    private function ns(string $template): string
    {
        return str_replace('__SDK_SUPPORT_NAMESPACE__', $this->supportNamespace, $template);
    }

    public function writeAll(): void
    {
        if (!is_dir($this->supportDir)) {
            mkdir($this->supportDir, 0755, true);
        }

        file_put_contents($this->supportDir . '/SdkArrayDto.php', $this->arrayDto());
        file_put_contents($this->supportDir . '/SdkAbstractDto.php', $this->abstractDto());
        file_put_contents($this->supportDir . '/SdkBaseRequest.php', $this->baseRequest());
        file_put_contents($this->supportDir . '/SdkBasePageRequest.php', $this->basePageRequest());
        file_put_contents($this->supportDir . '/SdkBaseResponse.php', $this->baseResponse());
        file_put_contents($this->supportDir . '/SdkBasePageResultResponse.php', $this->basePageResultResponse());
        file_put_contents($this->supportDir . '/SdkClientException.php', $this->exception());
        file_put_contents($this->supportDir . '/BaseClientApi.php', $this->baseClientApi());
        file_put_contents($this->supportDir . '/ApiProperty.php', $this->apiProperty());
        file_put_contents($this->supportDir . '/ArrayList.php', $this->arrayList());
        file_put_contents($this->supportDir . '/SdkCovertProperty.php', $this->covertProperty());
        file_put_contents($this->supportDir . '/SdkArrayInterface.php', $this->arrayInterface());
        file_put_contents($this->supportDir . '/SdkArrayInteger.php', $this->arrayInteger());
        file_put_contents($this->supportDir . '/SdkArrayString.php', $this->arrayString());
        file_put_contents($this->supportDir . '/StringToInt.php', $this->stringToInt());
        file_put_contents($this->supportDir . '/IntToString.php', $this->intToString());
    }

    private function apiProperty(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: documents a property (or method) for client-side hints; no framework dependency.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class ApiProperty
{
    public function __construct(
        protected string $description = ''
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}

PHP);
    }

    private function arrayList(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: marks list properties and their item DTO class.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayList
{
    public function __construct(
        protected string $itemClass = ''
    ) {
    }

    public function getItemClass(): string
    {
        return $this->itemClass;
    }
}

PHP);
    }

    private function covertProperty(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use __SDK_SUPPORT_NAMESPACE__\ArrayList;
use __SDK_SUPPORT_NAMESPACE__\SdkArrayInteger;
use __SDK_SUPPORT_NAMESPACE__\SdkArrayString;
use __SDK_SUPPORT_NAMESPACE__\SdkArrayInterface;

final class SdkCovertProperty
{
    public static function toCovertDeepProperty(mixed $data, String $tagetClass): mixed
    {
        if (!class_exists($tagetClass)) {
            throw new \InvalidArgumentException('Target class does not exist: ' . $tagetClass);
        }

        $data = self::normalizeSourceData($data);

        // API 响应中的数组 -> SdkArrayInteger / SdkArrayString
        if (is_array($data) && (is_a($tagetClass, SdkArrayInteger::class, true) || is_a($tagetClass, SdkArrayString::class, true))) {
            return new $tagetClass($data);
        }

        // 其他实现 SdkArrayInterface 的类型
        if (is_array($data) && is_a($tagetClass, SdkArrayInterface::class, true)) {
            return new $tagetClass($data);
        }

        $object = self::newObject($tagetClass);
        if (!is_array($data)) {
            if (method_exists($object, 'setData')) {
                $object->setData($data);
            }

            return $object;
        }

        $filled = self::fillObject($object, $data);
        if (!$filled && method_exists($object, 'setData')) {
            $object->setData($data);
        }

        return $object;
    }

    private static function fillObject(object $object, array $data): bool
    {
        $filled = false;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $property = self::reflectionPropertyForDeclaredField($object, (string) $key);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }

            $property->setAccessible(true);
            $convertedValue = self::valueForProperty($property, $value);
            $property->setValue($object, $convertedValue);
            $filled = true;
        }

        return $filled;
    }

    private static function valueForProperty(ReflectionProperty $property, mixed $value): mixed
    {
        $value = self::normalizeSourceData($value);
        
        // 检查是否有 ArrayList 注解
        $itemClass = self::arrayListItemClass($property);
        if ($itemClass !== null && is_array($value)) {
            // 对数组中的每个元素进行递归转换
            $convertedItems = [];
            foreach ($value as $key => $item) {
                $convertedItems[$key] = self::toCovertDeepProperty($item, $itemClass);
            }
            return $convertedItems;
        }

        $class = self::propertyObjectClass($property);
        if ($class !== null && $value !== null) {
            // 属性为 SdkArrayInteger 等时，数组入参包装为集合对象
            if (is_a($class, SdkArrayInterface::class, true) && is_array($value)) {
                return new $class($value);
            }

            return self::toCovertDeepProperty($value, $class);
        }

        return $value;
    }

    private static function arrayListItemClass(ReflectionProperty $property): ?string
    {
        foreach ($property->getAttributes(ArrayList::class) as $attribute) {
            $arrayList = $attribute->newInstance();
            $itemClass = $arrayList->getItemClass();
            if ($itemClass === '') {
                continue;
            }
            if (!class_exists($itemClass)) {
                throw new \InvalidArgumentException('ArrayList item class does not exist: ' . $itemClass);
            }

            return $itemClass;
        }

        return null;
    }

    private static function propertyObjectClass(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();
        if (!class_exists($class)) {
            return null;
        }

        return $class;
    }

    private static function normalizeSourceData(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::normalizeSourceData($value);
            }

            return $data;
        }

        if (is_object($data) && method_exists($data, 'toDeepArray')) {
            return self::normalizeSourceData($data->toDeepArray());
        }

        if (is_object($data) && method_exists($data, 'toArray')) {
            return self::normalizeSourceData($data->toArray());
        }

        return $data;
    }

    private static function newObject(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function reflectionPropertyForDeclaredField(object $object, string $name): ?ReflectionProperty
    {
        if ($name === '') {
            return null;
        }

        for (
            $class = new ReflectionClass($object);
            $class !== null && $class->getName() !== 'stdClass';
            $class = $class->getParentClass()
        ) {
            if (!$class->hasProperty($name)) {
                continue;
            }

            $property = $class->getProperty($name);
            if ($property->isStatic()) {
                return null;
            }

            return $property;
        }

        return null;
    }
}


PHP);
    }

    private function stringToInt(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: marks request integer fields that may be supplied as numeric strings.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class StringToInt
{
}

PHP);
    }

    private function intToString(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: marks response integer fields that should be treated as strings by clients.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IntToString
{
}

PHP);
    }

    private function baseClientApi(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

abstract class BaseClientApi
{
    protected string $baseUri = '';
    
    public function __construct(
        protected ClientInterface $httpClient,
        string $baseUri = '',
    ) {
    }
    
    public static function make(ClientInterface $httpClient): static
    {
        return new static($httpClient);
    }

    protected function uri(string $path): string
    {
        return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseJsonResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new SdkClientException('Unexpected HTTP status: ' . $status, $status);
        }
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SdkClientException('Invalid JSON: ' . $e->getMessage(), $response->getStatusCode(), $raw);
        }
        if (!is_array($decoded)) {
            throw new SdkClientException('Expected JSON object', $response->getStatusCode(), $decoded);
        }

        return $decoded;
    }

    protected function assertBusinessOk(array $payload): void
    {
        $code = $payload['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            $msg = (string) ($payload['msg'] ?? 'server error');
            $status = is_int($code) ? $code : 0;
            throw new SdkClientException($msg, $status, $payload);
        }
    }

    /**
     * JSON client defaults + per-request keys (body, query, …) + caller overrides; headers are deep-merged.
     *
     * @param array<string, mixed> $requestDefaults
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function mergeClientOptions(array $requestDefaults, array $options = []): array
    {
        $defaults = [
            'http_errors' => false,
            'headers' => ['Content-Type' => 'application/json'],
        ];
        $defaults = array_merge($defaults, $requestDefaults);
        $merged = array_merge($defaults, $options);
        if (isset($defaults['headers'], $options['headers']) && is_array($defaults['headers']) && is_array($options['headers'])) {
            $merged['headers'] = array_merge($defaults['headers'], $options['headers']);
        }

        return $merged;
    }
}

PHP);
    }

    private function arrayDto(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ReflectionProperty;

/**
 * SDK copy of core DTO helpers (no framework deps).
 */
class SdkArrayDto extends \stdClass
{
    public function toArray(): array
    {
        $out = [];
        foreach (
            (new \ReflectionClass($this))->getProperties(
                ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE
            ) as $property
        ) {
            if ($property->isStatic()) {
                continue;
            }
            $property->setAccessible(true);
            if (!$property->isInitialized($this)) {
                continue;
            }
            $out[$property->getName()] = $property->getValue($this);
        }
        foreach (get_object_vars($this) as $name => $value) {
            if (!array_key_exists($name, $out)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    public function toDeepArray(): array
    {
        return $this->valueToDeepArray($this->toArray());
    }

    private function valueToDeepArray(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->valueToDeepArray($item);
            }

            return $value;
        }

        // SdkArrayInteger / SdkArrayString：序列化前转为纯数组
        if ($value instanceof SdkArrayInterface) {
            return $this->valueToDeepArray($value->toDeepArray());
        }

        if ($value instanceof self) {
            return $value->toDeepArray();
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->valueToDeepArray($value->toArray());
        }

        return $value;
    }

    public function copyProperty(array|self $data): void
    {
        $data = $data instanceof self ? $data->toArray() : $data;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($name === '') {
                continue;
            }
            $property = $this->reflectionPropertyForDeclaredField($name);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($this, $value);
        }
    }

    public function copyDeepProperty(array|self $data): void
    {
        $data = $data instanceof self ? $data->toArray() : $data;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($name === '') {
                continue;
            }
            $property = $this->reflectionPropertyForDeclaredField($name);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($this, $this->valueForDeepProperty($property, $value));
        }
    }

    private function valueForDeepProperty(ReflectionProperty $property, mixed $value): mixed
    {
        if ($value instanceof SdkArrayInterface) {
            return $value;
        }

        // copyDeepProperty：JSON 数组 -> SdkArrayInteger / SdkArrayString
        if (is_array($value)) {
            $arrayStructClass = $this->arrayStructClassFromProperty($property);
            if ($arrayStructClass !== null) {
                return new $arrayStructClass($value);
            }
        }

        if ($value instanceof self) {
            $value = $value->toArray();
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($property->isInitialized($this)) {
            $currentValue = $property->getValue($this);
            if ($currentValue instanceof self) {
                $currentValue->copyDeepProperty($value);

                return $currentValue;
            }
        }

        $dto = $this->newDtoFromPropertyType($property);
        if ($dto === null) {
            return $value;
        }

        $dto->copyDeepProperty($value);

        return $dto;
    }

    /** 解析属性类型是否为 SdkArrayInteger / SdkArrayString 等集合类 */
    private function arrayStructClassFromProperty(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();
        if (!is_a($className, SdkArrayInterface::class, true)) {
            return null;
        }

        return $className;
    }

    private function newDtoFromPropertyType(ReflectionProperty $property): ?self
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();
        if (!is_a($className, self::class, true)) {
            return null;
        }

        $class = new \ReflectionClass($className);
        if (!$class->isInstantiable()) {
            return null;
        }

        $constructor = $class->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        return $class->newInstance();
    }

    private function reflectionPropertyForDeclaredField(string $name): ?ReflectionProperty
    {
        for (
            $class = new \ReflectionClass($this);
            $class !== null && $class->getName() !== 'stdClass';
            $class = $class->getParentClass()
        ) {
            if (!$class->hasProperty($name)) {
                continue;
            }
            $property = $class->getProperty($name);
            if ($property->isStatic()) {
                return null;
            }

            return $property;
        }

        return null;
    }
}

PHP);
    }

    private function abstractDto(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy of core DTO base (no framework deps).
 */
class SdkAbstractDto extends SdkArrayDto
{

    public function __set(string $name, $value): void
    {
        $this->$name = $value;
    }

    public function __get(string $name)
    {
        return $this->$name ?? null;
    }
}

PHP);
    }

    private function baseRequest(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBaseRequest extends SdkArrayDto
{
    public function getRequestInput(): never
    {
        throw new \BadMethodCallException('SDK client has no RequestInput.');
    }
}

PHP);
    }

    private function basePageRequest(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBasePageRequest extends SdkBaseRequest
{
    /**
     * @var int
     * #[ValidationRule(
     *   rule: 'required|int',
     *   message: [
     *       'required' => 'page is required',
     *       'int' => 'page must be int'
     *   ]
     * )]
     */
    #[ApiProperty(
        description: 'page页码'
    )]
    protected int $page = 1;

    /**
     * @var int
     * #[ValidationRule(
     *   rule: 'required|int',
     *   message: [
     *       'required' => 'pageSize is required',
     *       'int' => 'pageSize must be int'
     *   ]
     * )]
     */
    #[ApiProperty(
        description: 'pageSize每页数量'
    )]
    protected int $pageSize = 10;

    public function setPage(int $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }
}

PHP);
    }

    private function baseResponse(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBaseResponse extends SdkArrayDto
{
    protected int $code = 0;

    protected string $msg = 'success';
    
    protected string $trace_id = '';
        
    public function setCode(int $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setMsg(string $msg): static
    {
        $this->message = $msg;

        return $this;
    }

    public function getMsg(): string
    {
        return $this->message;
    }
    
    public function getTraceId(): string
    {
        return $this->trace_id;
    }

    public function setData(mixed $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}

PHP);
    }

    private function basePageResultResponse(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy: base page result response for pagination.
 * Extend this class for paginated list responses.
 */
class SdkBasePageResultResponse extends SdkBaseResponse
{
}

PHP);
    }

    private function exception(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkClientException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $statusCode = 0,
        private mixed $payload = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }
}

PHP);
    }

    private function arrayInterface(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy: typed array collections (ArrayInteger, ArrayString, …).
 */
interface SdkArrayInterface
{
    public function toArray(): array;

    public function toDeepArray(): array;
}

PHP);
    }

    private function arrayInteger(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * SDK copy: int[] collection (mirrors Swoolefy\DataStruct\ArrayInteger).
 */
class SdkArrayInteger implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, SdkArrayInterface
{
    /** @var int[] */
    protected array $items = [];

    public function __construct(mixed $items = [])
    {
        $this->items = $this->convertToIntegerArray($items);
    }

    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function add(int $value): static
    {
        $this->items[] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function toDeepArray(): array
    {
        return $this->items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function merge(mixed $items): static
    {
        return new static(array_merge($this->items, $this->convertToIntegerArray($items)));
    }

    public function distinct(): static
    {
        return new static(array_values(array_unique($this->items, SORT_NUMERIC)));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_values(array_filter($this->items, $callback)));
        }

        return new static(array_values(array_filter($this->items)));
    }

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function first(): ?int
    {
        return $this->items === [] ? null : $this->items[array_key_first($this->items)];
    }

    public function last(): ?int
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    public function count(): int
    {
        return count($this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): int
    {
        return $this->items[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('SdkArrayInteger only accepts integer values');
        }
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * @return int[]
     */
    protected function convertToIntegerArray(mixed $items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }

        $items = (array) $items;
        foreach ($items as $key => $value) {
            if (!is_int($value)) {
                throw new \InvalidArgumentException(
                    "SdkArrayInteger only accepts integer values. Invalid value at key '{$key}': " . gettype($value)
                );
            }
        }

        return $items;
    }
}

PHP);
    }

    private function arrayString(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * SDK copy: string[] collection (mirrors Swoolefy\DataStruct\ArrayString).
 */
class SdkArrayString implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, SdkArrayInterface
{
    /** @var string[] */
    protected array $items = [];

    public function __construct(mixed $items = [])
    {
        $this->items = $this->convertToStringArray($items);
    }

    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function add(string $value): static
    {
        $this->items[] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function toDeepArray(): array
    {
        return $this->items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function merge(mixed $items): static
    {
        return new static(array_merge($this->items, $this->convertToStringArray($items)));
    }

    public function distinct(): static
    {
        return new static(array_values(array_unique($this->items, SORT_STRING)));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_values(array_filter($this->items, $callback)));
        }

        return new static(array_values(array_filter($this->items)));
    }

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function first(): ?string
    {
        return $this->items === [] ? null : $this->items[array_key_first($this->items)];
    }

    public function last(): ?string
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    public function count(): int
    {
        return count($this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): string
    {
        return $this->items[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('SdkArrayString only accepts string values');
        }
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * @return string[]
     */
    protected function convertToStringArray(mixed $items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }

        $items = (array) $items;
        foreach ($items as $key => $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    "SdkArrayString only accepts string values. Invalid value at key '{$key}': " . gettype($value)
                );
            }
        }

        return $items;
    }
}

PHP);
    }
}
