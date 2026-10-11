# Bin

这里放 sidecar 的可执行文件，不在部署机器上编译。源码在独立仓库 [swoolefy-go-sidecar](https://github.com/bingcool/swoolefy-go-sidecar)，编译产物复制到本目录。发布包在该仓库的 GitHub Release。

Swoole 启动时按当前系统选择其中一个，用 `exec` 拉起为本机进程。PHP 通过 Unix socket 调用它，自己不连接 RocketMQ。当前这个进程只提供 RocketMQ 的发送、拉取和确认。

| 文件 | 系统 | 说明 |
| --- | --- | --- |
| `go-sidecar-mac` | macOS arm64 | 未压缩。不要用 UPX，压缩后的 Mach-O 无法执行 |
| `go-sidecar-linux` | Linux amd64 | 静态链接，构建时已用 UPX 压缩。运行机器不需要安装 UPX |

`SystemSidecar.php` 用 `PHP_OS_FAMILY` 选择文件：`Darwin` 用 `go-sidecar-mac`，其他用 `go-sidecar-linux`。文件名不要改。

版本号在 Go 仓库的 `internal/version/version.go`。换了新二进制之后要重启 Swoole，正在运行的进程才会加载新文件。PHP 可调用 `sidecar.version` 读到这个版本。
