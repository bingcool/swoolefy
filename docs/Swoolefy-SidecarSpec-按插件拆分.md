# SidecarSpec 按插件拆分

本文只改配置校验。不实现 MQTT 客户端，也不改 Go 进程。

目标：一项配置只携带一个插件的字段。`sidecar_plugins=rocketmq` 的项只认识 `sidecar_rocketmq_*`。其它插件的键出现在这一项里，启动失败。RocketMQ 的空字段不再写进别的进程环境。

## 1. 现在的问题

`SidecarSpec` 同时做两件事：

- 进程声明：名字、二进制、socket、帧大小、超时、环境变量。`SidecarSupervisor`、`SidecarRegistry`、`SidecarClient` 用的是这个对象。
- 字段白名单：`KEYS` 里公共键和 `sidecar_rocketmq_*` 写在一起。

`fromRow()` 对每一项都调用 `applyDefaults()`。缺省会补上 `sidecar_plugins=rocketmq`，以及四个 RocketMQ 字符串（端点、密钥、允许的 Topic）。然后 `encodeRow()` 把这些非空值编成 `SIDECAR_*`。

结果是：

- 新插件的 `sidecar_mqtt5_*` 不在 `KEYS` 里，报 `unknown sidecar config key`。
- 就算某项写成 `sidecar_plugins=mqtt5`，缺省逻辑仍会带上 RocketMQ 键。非空的会进入该进程的环境变量。

`SidecarSupervisor::spawn()` 和 socket 路径不用改。一项启用配置仍然 `exec` 一次。

## 2. 类怎么拆

`SidecarSpec` 留下，继续表示「一个已经校验过的进程」。构造参数和 `fromRow()` 的调用方不变。`SidecarSupervisor` 仍写 `SidecarSpec::fromRow($row, $runtimeDir)`。

每个插件一个字段类，只负责自己的键：

```text
src/Core/Sidecar/SidecarSpec.php
src/Core/Sidecar/Plugin/SidecarPluginSpec.php
src/Core/Sidecar/Plugin/SidecarPluginRegistry.php
src/Core/Sidecar/Plugin/SidecarRocketMqSpec.php
```

`SidecarPluginSpec` 是接口：

```php
interface SidecarPluginSpec
{
    public static function name(): string;

    /** @return list<string> 仅本插件的 sidecar_* 键 */
    public static function keys(): array;

    /**
     * 校验并返回要写回配置项的字段。只含本插件的键。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fields(array $row): array;
}
```

`SidecarRocketMqSpec::name()` 返回 `rocketmq`。`keys()` 是现在的四项：

- `sidecar_rocketmq_proxy_endpoint`
- `sidecar_rocketmq_access_key`
- `sidecar_rocketmq_secret_key`
- `sidecar_rocketmq_allowed_topics`

`fields()` 承接现在的 `requireString`、`requireTopics`、`assertEndpoint`。插件名是 `rocketmq` 时，端点必须是 `host:port`。允许的 Topic 仍是空、`*`，或逗号分隔的具体名字。密钥仍是字符串，允许空。空字符串随后不 `putenv`，这条规则留在 `encodeRow()`。

`SidecarPluginRegistry` 按插件名返回类：

```php
final class SidecarPluginRegistry
{
    public static function get(string $name): string
    {
        return match ($name) {
            'rocketmq' => SidecarRocketMqSpec::class,
            default => throw new SidecarException('unknown sidecar plugin: ' . $name),
        };
    }
}
```

以后加 MQTT 时，新增 `SidecarMqtt5Spec.php`，并在这个 `match` 里登记 `mqtt5`。本项不添加该类。

## 3. fromRow 的顺序

公共键固定为：

- `sidecar_name`
- `sidecar_enable`
- `sidecar_exec_binary`
- `sidecar_args`
- `sidecar_socket_path`
- `sidecar_plugins`
- `sidecar_max_frame_bytes`
- `sidecar_default_timeout_ms`
- `sidecar_max_timeout_ms`
- `sidecar_max_inflight`
- `sidecar_shutdown_grace_ms`

`applyDefaults()` 只补这些公共缺省，不再写入任何 `sidecar_rocketmq_*`。`sidecar_plugins` 的缺省仍是 `rocketmq`，这样现有只写名字和开关的项行为不变。

启用项的顺序：

1. 键必须以 `sidecar_` 开头。
2. 解析 `sidecar_plugins`。必须是一个插件名，匹配 `^[a-z0-9]+$`。含逗号直接失败。一项配置对应一个插件类，也对应一个进程。
3. `SidecarPluginRegistry::get()` 取出插件类。未登记的名字失败。
4. 配置项里的每个键，要么是公共键，要么是该类的 `keys()`。否则 `unknown sidecar config key`。
5. 调用该类的 `fields($row)`，把返回的字段写回 `$row`。
6. 二进制、参数、超时、socket 仍由 `SidecarSpec` 校验。
7. `encodeRow()` 只编码 `$row` 里剩下的键。RocketMQ 项的环境变量仍是 `SIDECAR_ROCKETMQ_*`。没有那些键的项不会导出它们。

未启用项仍在检查二进制之前返回 `null`。这一步不要求插件字段已经合法，避免关闭的项因为端点没填而让整个 Server 起不来。

## 4. 行为对照

| 配置 | 结果 |
| --- | --- |
| `sidecar_plugins` 缺省，带四个 RocketMQ 键 | 与现在相同，导出 `SIDECAR_ROCKETMQ_*` |
| `sidecar_plugins=rocketmq`，端点不是 `host:port` | 仍失败 |
| `sidecar_plugins=rocketmq`，出现 `sidecar_mqtt5_broker` | `unknown sidecar config key` |
| `sidecar_plugins=mqtt5` | `unknown sidecar plugin: mqtt5`。本项故意不登记它 |
| `sidecar_plugins=rocketmq,mqtt5` | 失败。一项只能有一个插件名 |

`SystemSidecar.php` 的现有数组不用改。它已经是 `sidecar_plugins=rocketmq` 加上四个 RocketMQ 键。

`spawn()` 里为 RocketMQ 设置 `rocketmq.client.logRoot` 的代码留在 `SidecarSupervisor`。那是启动动作，不是配置白名单。本项不动。

## 5. 测试

现有 `SidecarSpecTest` 里 RocketMQ 成功路径、端点格式、禁用项不检查二进制，保持通过。

另加三项：

- RocketMQ 项带一个不属于它的 `sidecar_*` 键，期望 `unknown sidecar config key`。
- `sidecar_plugins` 为 `rocketmq,mqtt5`，期望失败。
- 启用项的 `environment()` 不含未出现在该插件 `keys()` 里的 `SIDECAR_ROCKETMQ_*`。用一个只含公共字段、插件名为 `rocketmq`、端点为空的项来断言：空端点不导出；同时断言没有多余键。端点为空时现有逻辑本来就会拒绝启用的 RocketMQ 项，所以这条断言放在 `fields()` 的单元测试里：传入空端点应抛错；传入合法端点和空密钥时，返回数组只有这四项，密钥空字符串由 `encodeRow()` 跳过。

`SidecarRocketMqSpec::fields()` 单独测 Topic 的 `*`、逗号列表和非法 `order,*`。这些规则从 `SidecarSpec` 搬过去，不改语义。

## 6. 不做的事

- 不新增 `SidecarMqtt5Spec`。
- 不改 Go 的 `pluginsFrom`。
- 不把 `SidecarSpec` 改成抽象类，也不让 `SidecarRocketMqSpec` 继承它。进程对象只有一种，插件差异只在字段校验。
- 不把 `sidecar_plugins` 继续做成逗号列表。一个进程一个插件。
