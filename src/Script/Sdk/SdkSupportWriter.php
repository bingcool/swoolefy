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
    ) {
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
    }

    private function abstractDto(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Swoolefy\GenerateSdk\Test\Support;

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

PHP;
    }

    private function baseRequest(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Swoolefy\GenerateSdk\Test\Support;

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

PHP;
    }

    private function baseResponse(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Swoolefy\GenerateSdk\Test\Support;

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

PHP;
    }

    private function exception(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Swoolefy\GenerateSdk\Test\Support;

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

PHP;
    }
}
