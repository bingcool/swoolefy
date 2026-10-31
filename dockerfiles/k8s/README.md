# swoolefy k8s 部署（Test 服务）

| 文件 | 作用 |
| --- | --- |
| `Dockerfile` | Test 服务运行镜像：从头构建 PHP 8.5 + Swoole 6.2（io_uring），不含业务代码 |
| `swoolefy-test.yaml` | Namespace + ConfigMap + Deployment + Service（`swoolefy-test-service`） |

## 前置

1. 宿主机已执行 `composer install`。`vendor/` 里的 `bingcool/library` 是 path repository 安装的，镜像内不做安装，直接由 hostPath 挂进容器。
2. 集群内有可达的 Nacos。容器启动时 `cli.php` 的 `$beforeFunc` 会调用 `NacosFactory::fetchConfigToEnv()` 拉取 `dataId=swoolefy-service / group=swoolefy-group / tenant=dev` 并生成 `Test/.env`；**Nacos 不可达时启动直接失败**（这是框架现状，没有本地兜底）。

## 构建

仓库根目录执行：

```bash
docker build -f dockerfiles/k8s/Dockerfile -t swoolefy-test:6.2.0 .
```

Dockerfile 不含 `COPY`，构建上下文用不到，可以直接指定 `dockerfiles/k8s` 作为上下文减少传输。集群是 amd64、本机是 arm64 时加 `--platform linux/amd64`，并把镜像推到集群能拉的仓库后再改 `swoolefy-test.yaml` 里的 `image`。

## 本地开发：挂载 wwwroot（不重建镜像）

`swoolefy-test.yaml` 的 Deployment 用 hostPath 把宿主机 `/Users/huangzengbing/Documents/wwwroot` 挂到容器的 `/home/wwwroot`；镜像本身只有 PHP 运行时，不含业务代码：

- 改 `src/`、`Test/`、`cli.php` 不需要重新 build，`kubectl -n swoolefy rollout restart deploy/swoolefy-test` 即生效
- `vendor` 同样来自宿主机，改依赖后在宿主机执行 `composer install`
- `Test/.env` 由 Nacos 在启动时写入宿主机仓库（`.gitignore` 已覆盖），本地再跑 Test 会读到这组值
- `Test/Storage` 仍是每个 Pod 独立的 emptyDir，多副本共享同一份代码时日志不会互相覆盖
- 清单默认 `replicas: 1`：副本共用宿主机的 `Test/.env` 与 `Test/sidecars_sock`，多副本时 sidecar 会互相清理 socket 目录
- 仓库不在该路径时改 `volumes[].hostPath.path`；Docker Desktop 会把 `/Users` 共享进节点 VM，kind / minikube 节点不一定有该路径
- hostPath 只用于本机开发；真实集群请把代码打进镜像，或改用 PVC / GitOps 同步

镜像不含业务代码，启动完全依赖挂载；`swoolefy-test.yaml` 与这个 Dockerfile 要一起使用。需要镜像自包含时，另外把代码 `COPY` 进去。

## Docker Desktop 集群模式与镜像导入

Docker Desktop 内置 k8s 有两种模式，镜像来源完全不同。先看 `kubectl get nodes -o wide` 的 `CONTAINER-RUNTIME` 和节点名判断：

| 模式 | 节点名 | runtime | 镜像怎么进 k8s |
| --- | --- | --- | --- |
| kind（默认） | `desktop-control-plane` | `containerd://` | 节点里是独立 containerd，Docker 镜像看不到，必须 `docker save` + `ctr import`（见下） |
| kubeadm | `docker-desktop` | `docker://`（cri-dockerd → dockerd） | Pod 本身就是 Docker 容器，直接用 `docker build` 的本地镜像，**不需要导入** |

kubeadm 模式 Pod 本身就是 Docker 容器。清单用 hostPath 挂载宿主机 `wwwroot` 后，改代码只需 `kubectl -n swoolefy rollout restart deploy/swoolefy-test`，不用重新 build；kind 模式按下节导入，且节点容器内不一定有 `/Users`，hostPath 不保证可用。

两种模式的 etcd 都在 Docker Desktop 的 VM 里（kind 在节点容器可写层，kubeadm 在 VM 的 `/var/lib/etcd`）。普通重启 Docker Desktop 会保留，但在 UI 里关掉/重开 Kubernetes、Reset、切换模式或改 VM 资源都会重置，部署对象和导入的镜像都要重来，所以"导入 → apply → 建 Secret"要能一条条重放。

### kind 模式：导入镜像

Docker Desktop 的 kind 节点里跑的是**另一个 containerd**（`/usr/local/bin/containerd`，socket 在节点自己的 mount namespace），而 Docker 用的是经典 overlay2 镜像存储，所以 `docker build` 出来的镜像 k8s 看不到，节点里也没有 `ctr`、`nerdctl`。没有可推的仓库时，直接把镜像导进去：

```bash
# 1. 宿主机导出（docker 29 出的是 OCI layout）
docker save swoolefy-test:6.2.0 -o tmp-k8s/swoolefy-test.tar

# 2. 下载 linux 版 ctr，注意架构与本机一致
curl -LO https://github.com/containerd/containerd/releases/download/v2.0.5/containerd-2.0.5-linux-arm64.tar.gz
tar xzf containerd-2.0.5-linux-arm64.tar.gz bin/ctr

# 3. 找出节点里 k8s containerd 的 PID。它和 Docker 的 /usr/bin/containerd 是两个进程
K8S_CTR_PID=$(docker run --rm --privileged --pid=host --entrypoint sh nacos/nacos-server:v2.5.2-slim \
  -c "ps -eo pid,args | awk '\$2==\"/usr/local/bin/containerd\"{print \$1}'")
echo $K8S_CTR_PID   # 对应 ps 里的 /usr/local/bin/containerd

# 4. 通过 /proc/<pid>/root 找到节点的 containerd socket，导入到 k8s.io namespace
docker run --rm --privileged -v /:/host -v "$PWD/tmp-k8s":/work --entrypoint sh nacos/nacos-server:v2.5.2-slim \
  -c "/work/bin/ctr --address /host/proc/$K8S_CTR_PID/root/run/containerd/containerd.sock \
      -n k8s.io images import --snapshotter overlayfs /work/swoolefy-test.tar"
```

要点：

- `ctr` 是动态链接的 glibc 二进制，载体镜像不能用 alpine 系（仓库里的 PHP 镜像都是 musl，会报 `not found`）。这里用 Ubuntu 系的 `nacos/nacos-server`。
- `--snapshotter overlayfs` 必须和节点 `/etc/containerd/config.toml` 里的 `snapshotter` 一致，否则 Pod 起来会找不到 snapshot。
- 导入后的名字是 `docker.io/library/swoolefy-test:6.2.0`，清单里写 `swoolefy-test:6.2.0` 即可（kubelet 会补全成同一个引用）。
- 同一个 tag 重新构建后要 `kubectl -n swoolefy rollout restart deploy/swoolefy-test`，`imagePullPolicy: IfNotPresent` 不会因为 tag 相同而重建容器。

## 部署

```bash
# 至少改这两处：ConfigMap swoolefy-test-env 的 NACOS_HOST、Deployment 的 image
kubectl apply -f dockerfiles/k8s/swoolefy-test.yaml
kubectl -n swoolefy rollout status deploy/swoolefy-test
```

依赖密码不要写进 ConfigMap，放 Secret（清单里已用 `optional: true` 引用，没这个 Secret 也能启动）：

```bash
kubectl -n swoolefy create secret generic swoolefy-test-deps --from-literal=REDIS_PASSWORD=xxx
```

验证：

```bash
kubectl -n swoolefy get pods -o wide
kubectl -n swoolefy logs deploy/swoolefy-test --tail=50
```

Service 是 `NodePort 9501:30501`，Docker Desktop 会把 NodePort 转出来，宿主机直接访问：

```bash
curl -s localhost:30501/health
curl -s localhost:30501/ready
curl -s localhost:30501/api/runtime
```

如果要严格用 `127.0.0.1:9501`，用端口转发（前台进程，关掉终端就断）：

```bash
kubectl -n swoolefy port-forward svc/swoolefy-test-service 9501:9501
```

## io_uring 与 seccomp

镜像里的 Swoole 用 `--enable-iouring` 编译。k8s / Docker 默认 seccomp 会拦 `io_uring_setup`，表现是每个 worker 起来就 `SIGABRT`：

```
ERROR Iouring::Iouring(): Failed to initialize io_uring instance; ... Operation not permitted
WARNING Worker::report_error(): worker(pid=xxx) abnormal exit, status=0, signal=6
```

然后 Manager 反复拉起 worker（日志里刷屏 `terminate called without an active exception`），HTTP 端口不响应。清单因此给 Pod 加了 `seccompProfile: Unconfined`，本地用 Docker 验证时对应 `--security-opt seccomp=dockerfiles/seccomp_profile.json`。

另外节点内核必须允许 io_uring（`kernel.io_uring_disabled=0`）。集群如果强制 `RuntimeDefault` 且无法放开，就不要用这个镜像，改用不带 `--enable-iouring` 的 Swoole 重新构建。

## 运行时行为

- 启动命令是 `php cli.php start Test --fromk8s=1`，前台运行，`tini` 做 PID 1 负责转发信号和回收孤儿进程。**不要加 `--daemon=1`**。
- Nacos 配置变更时走 k8s 分支：不写 `.env`、不拉起 `restart` 进程，直接退出服务进程，由 kubelet 按 `restartPolicy` 重建容器，新容器启动时重新拉取配置。详见 [../../src/Support/Nacos/Monitor/README.md](../../src/Support/Nacos/Monitor/README.md#k8s-部署)。
- `Terminating` 到进程退出实测约 18~20s（Swoole `max_wait_time=10`，再加各进程收尾），所以 `terminationGracePeriodSeconds` 设为 60，不要按 10 秒估算。
- Test 默认会注册 MQ / PubSub 验证进程，它们要连 Kafka / RabbitMQ / RocketMQ / Redis。清单里用 `TEST_MQ_VERIFY=0`、`TEST_PUBSUB_VERIFY=0` 关掉。
- `.env` 载入是不可变模式，ConfigMap / Secret 里同名的 `REDIS_HOST`、`REDIS_PASSWORD` 等优先于 Nacos 下发的值。
- 代码与 `vendor` 通过 hostPath 来自宿主机；`.env` 写到 `Test/.env`（宿主机仓库），`Test/Storage` 是 Pod 内 emptyDir。

## 常见问题

| 现象 | 原因 |
| --- | --- |
| `CrashLoopBackOff`，日志里有 `Nacos config fetch failed` | `NACOS_HOST` 在集群内不可达，或 Nacos 上该 dataId 没有配置 |
| worker 反复重启，日志刷 `terminate called without an active exception` | seccomp 拦了 io_uring，检查 Pod 的 `seccompProfile` 与节点的 `kernel.io_uring_disabled` |
| Pod 一直 `0/1 Running`，`/ready` 返回 503 | `/ready` 检查 `redis` 组件：地址不可达、缺 `REDIS_PASSWORD`，或依赖的 Redis 未启动 |
| `/ready` 503，日志 `Redis::connect(): Argument #1 ($host) must be of type string, null given` | Nacos 下发的变量读不到：`fetchConfigToEnv()` 之前在 `nacos.yaml` 插值时已缓存 Dotenv 仓库。当前分支已用 `SystemEnv::clearEnvRepository()` 修复，旧镜像要重建 |
| `exec format error` | 镜像架构与节点不符，按上面的 `--platform` 重新构建 |
| 日志里出现 `option "--fromk8s" does not exist` | 用了没走 `BaseCmd` 动态选项的老版本框架，升级到当前 6.2 分支 |
| 宿主机 `localhost:9501` 连接被拒绝 | Service 是 `NodePort`，出口在 30501；9501 只在集群内部可用，要本机 9501 就 `kubectl port-forward` |
