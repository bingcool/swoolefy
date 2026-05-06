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

        file_put_contents($this->supportDir . '/SdkAbstractDto.php', $this->abstractDto());
        file_put_contents($this->supportDir . '/SdkBaseRequest.php', $this->baseRequest());
        file_put_contents($this->supportDir . '/SdkBaseResponse.php', $this->baseResponse());
        file_put_contents($this->supportDir . '/SdkClientException.php', $this->exception());
        file_put_contents($this->supportDir . '/BaseClientApi.php', $this->baseClientApi());
        file_put_contents($this->supportDir . '/ApiProperty.php', $this->apiProperty());
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
    public function __construct(
        protected ClientInterface $httpClient,
        protected string $baseUri = '',
    ) {
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
            $msg = (string) ($payload['msg'] ?? 'error');
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

    private function abstractDto(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ReflectionProperty;

/**
 * SDK copy of core DTO helpers (no framework deps).
 */
class SdkAbstractDto extends \stdClass
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

class SdkBaseRequest extends SdkAbstractDto
{
    public function setRequestInput(mixed $requestInput): static
    {
        return $this;
    }

    public function getRequestInput(): never
    {
        throw new \BadMethodCallException('SDK client has no RequestInput.');
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

class SdkBaseResponse
{
    protected mixed $data = [];

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
}
