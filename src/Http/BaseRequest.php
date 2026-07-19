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

declare(strict_types=1);

namespace Swoolefy\Http;

use Swoolefy\Core\Dto\ArrayDto;

/**
 * HTTP 入参 DTO 基类。
 *
 * - 挂载 {@see RequestInput}；路由在中间件前按 `#[ValidationRule]` 校验并 hydrate
 * - 提供 `validated()` / `only()` 等读参助手（无 authorize / prepare / failed 等 hooks）
 * - OpenAPI：`#[ApiProperty]` + `gen:apidoc`
 * - 分页可继承 {@see BasePageRequest}
 *
 * ## 示例
 *
 * ```php
 * final class CreateUserRequest extends BaseRequest
 * {
 *     #[ApiProperty(description: '用户名')]
 *     #[ValidationRule(rule: 'required|string|min:2')]
 *     protected string $username = '';
 *
 *     public function getUsername(): string
 *     {
 *          return $this->username;
 *     }
 *
 *     public function setUsername(string $username): static
 *     {
 *         $this->username = $username;
 *         return $this;
 *     }
 * }
 *
 * // Controller
 * public function create(CreateUserRequest $request): array
 * {
 *     return $request->getUsername()
 * }
 * ```
 *
 * 鉴权请用路由中间件（如 AuthenticateMiddleware），不要在本类加 hooks。
 *
 * @see RequestValidate
 * @see BasePageRequest
 */
class BaseRequest extends ArrayDto
{
    /**
     * @var RequestInput
     */
    private RequestInput $requestInput;

    /**
     * @param RequestInput $requestInput
     * @return static
     */
    public function setRequestInput(RequestInput $requestInput): static
    {
        $this->requestInput = $requestInput;
        return $this;
    }

    /**
     * @return RequestInput
     */
    public function getRequestInput(): RequestInput
    {
        return $this->requestInput;
    }

    /**
     * 校验并 hydrate 后的业务字段（不含框架内部的 requestInput）。
     *
     * - 无参：返回全部已声明属性的浅数组
     * - 有 key：取单个字段，不存在时返回 $default
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    protected function validated(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->validatedData();
        if ($key === null) {
            return $data;
        }

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * 仅取部分已校验字段。
     *
     * @param string ...$keys
     * @return array<string, mixed>
     */
    public function only(string ...$keys): array
    {
        $data = $this->validatedData();
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }

    /**
     * 排除部分字段后的已校验数据。
     *
     * @param string ...$keys
     * @return array<string, mixed>
     */
    public function except(string ...$keys): array
    {
        $data = $this->validatedData();
        foreach ($keys as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * 已校验数据中是否存在该键（含值为 null）。
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->validatedData());
    }

    /**
     * 已校验数据中该键存在且「有值」（非 null、非空字符串、非空数组）。
     */
    public function filled(string $key): bool
    {
        if (!$this->has($key)) {
            return false;
        }
        $value = $this->validated($key);

        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    /**
     * 已校验数据中不存在该键。
     */
    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    /**
     * 以 bool 读取已校验字段（兼容 1/0/"true"/"false"/"on"/"yes" 等常见入参）。
     */
    public function boolean(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->validated($key);
        if (is_bool($value)) {
            return $value;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered ?? $default;
    }

    /**
     * 以 int 读取已校验字段；无法转换时返回 $default。
     */
    public function integer(string $key, ?int $default = null): ?int
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->validated($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * 以 string 读取已校验字段；null 返回 $default。
     */
    public function string(string $key, ?string $default = null): ?string
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->validated($key);
        if ($value === null) {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * 原始请求入参（query + body 合并结果)
     *
     * 需框架已调用 {@see setRequestInput()}；单测若未注入请勿调用。
     *
     * @return ($key is null ? array : mixed)
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $this->getRequestInput()->input($key, $default);
    }

    /**
     * 验证通过后的数据，已转好字段的类型
     * @return array<string, mixed>
     */
    public function validatedData(): array
    {
        $data = $this->toArray();
        unset($data['requestInput']);

        var_dump($data);

        return $data;
    }
}
