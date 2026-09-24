<?php

declare(strict_types=1);

namespace __INTERFACE_API_SUPPORT_NAMESPACE__;

class BaseRequest extends ArrayDto
{
    public function validated(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->toArray();
        unset($data['requestInput']);
        if ($key === null) {
            return $data;
        }

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function only(string ...$keys): array
    {
        $data = $this->validated();
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }
}
