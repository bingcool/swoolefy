# Swoolefy 微服务 Kubernetes 部署方案

> 以 `orderService`、`productService` 为例。
>
> 目标：Swoolefy CLI HTTP 服务运行在 Kubernetes 中；服务注册到 Nacos；K8s 内部使用 ClusterIP；Local、Python、Java、Go 等 K8s 外部内网调用方通过 **Internal LoadBalancer + 内网 DNS** 访问。

## 1. 总体架构

```text
                         公司 / VPC 内网
                                │
          ┌─────────────────────┼─────────────────────┐
          │                     │                     │
          ↓                     ↓                     ↓
       Local                  Python                Java/Go
          │                     │                     │
          └─────────────────────┼─────────────────────┘
                                │
                     内网 DNS：*.dev.internal
                                │
              ┌─────────────────┴─────────────────┐
              ↓                                   ↓
 order-service.dev.internal       product-service.dev.internal
              │                                   │
              ↓                                   ↓
   Internal LoadBalancer             Internal LoadBalancer
              │                                   │
              ↓                                   ↓
      K8s Service (LB)                   K8s Service (LB)
              │                                   │
              ↓                                   ↓
      orderService Pods                  productService Pods
              │                                   │
              └─────────────────┬─────────────────┘
                                ↓
                              Nacos
```

职责分层：

| 组件 | 职责 |
|---|---|
| Nacos | 服务注册、发现、健康状态 |
| ClusterIP Service | K8s 集群内部稳定入口 |
| Internal LoadBalancer | K8s 外部内网访问入口 |
| 内网 DNS | 提供稳定、可读的服务域名 |
| Deployment | Pod 生命周期、滚动发布、扩缩容 |

---

# 2. Namespace

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: swoolefy
```

---

# 3. Nacos 配置

假设 Dev Nacos：

```text
nacos.dev.internal:8848
```

不要把 Nacos 地址写死到镜像：

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: swoolefy-config
  namespace: swoolefy
data:
  APP_ENV: "dev"
  NACOS_HOST: "nacos.dev.internal"
  NACOS_PORT: "8848"
  NACOS_NAMESPACE: "dev"
  NACOS_GROUP: "DEFAULT_GROUP"
```

Nacos 用户名密码放 Secret：

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: nacos-secret
  namespace: swoolefy
type: Opaque
stringData:
  NACOS_USERNAME: "nacos"
  NACOS_PASSWORD: "change-me"
```

真实生产环境不要把密码直接提交到 Git。

---

# 4. Swoolefy CLI HTTP 服务

容器中建议让 Swoolefy Master 成为主进程：

```bash
php server.php start order
```

不要：

```bash
php server.php start order &
```

不要让 shell 在后台运行 Swoolefy。

进程关系应该保持：

```text
Container PID 1
      ↓
Swoolefy Master
      ↓
Manager / Worker
```

---

# 5. OrderService Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: order-service
  namespace: swoolefy
  labels:
    app: order-service
spec:
  replicas: 2

  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxUnavailable: 0
      maxSurge: 1

  selector:
    matchLabels:
      app: order-service

  template:
    metadata:
      labels:
        app: order-service
        service: order-service

    spec:
      terminationGracePeriodSeconds: 30

      containers:
        - name: order-service
          image: registry.example.com/swoolefy/order-service:1.0.0
          imagePullPolicy: IfNotPresent

          command:
            - php
          args:
            - server.php
            - start
            - order

          ports:
            - name: http
              containerPort: 9501
              protocol: TCP

          envFrom:
            - configMapRef:
                name: swoolefy-config
            - secretRef:
                name: nacos-secret

          env:
            - name: SERVICE_NAME
              value: "orderService"

          readinessProbe:
            httpGet:
              path: /health
              port: http
            initialDelaySeconds: 5
            periodSeconds: 5
            timeoutSeconds: 2
            failureThreshold: 3

          livenessProbe:
            httpGet:
              path: /health
              port: http
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 2
            failureThreshold: 3

          resources:
            requests:
              cpu: "250m"
              memory: "256Mi"
            limits:
              cpu: "2"
              memory: "1Gi"

          lifecycle:
            preStop:
              exec:
                command:
                  - /bin/sh
                  - -c
                  - sleep 5
```

---

# 6. OrderService ClusterIP

K8s 内部使用：

```yaml
apiVersion: v1
kind: Service
metadata:
  name: order-service
  namespace: swoolefy
spec:
  type: ClusterIP

  selector:
    app: order-service

  ports:
    - name: http
      port: 9501
      targetPort: http
      protocol: TCP
```

K8s 内部可以访问：

```text
http://order-service:9501
```

或者：

```text
http://order-service.swoolefy.svc.cluster.local:9501
```

---

# 7. OrderService Internal LoadBalancer

```yaml
apiVersion: v1
kind: Service
metadata:
  name: order-service-internal
  namespace: swoolefy

  annotations:
    # 根据实际云厂商替换为 Internal LoadBalancer annotation。
    # 不同云厂商的 annotation 不统一。

spec:
  type: LoadBalancer

  selector:
    app: order-service

  ports:
    - name: http
      port: 80
      targetPort: http
      protocol: TCP
```

部署后：

```bash
kubectl get svc -n swoolefy
```

可能得到：

```text
NAME                    TYPE           EXTERNAL-IP
order-service           ClusterIP      10.96.10.10
order-service-internal  LoadBalancer   10.10.20.30
```

这里 `10.10.20.30` 是内网 LoadBalancer 地址。

---

# 8. ProductService Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: product-service
  namespace: swoolefy
  labels:
    app: product-service
spec:
  replicas: 2

  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxUnavailable: 0
      maxSurge: 1

  selector:
    matchLabels:
      app: product-service

  template:
    metadata:
      labels:
        app: product-service
        service: product-service

    spec:
      terminationGracePeriodSeconds: 30

      containers:
        - name: product-service
          image: registry.example.com/swoolefy/product-service:1.0.0
          imagePullPolicy: IfNotPresent

          command:
            - php
          args:
            - server.php
            - start
            - product

          ports:
            - name: http
              containerPort: 9501
              protocol: TCP

          envFrom:
            - configMapRef:
                name: swoolefy-config
            - secretRef:
                name: nacos-secret

          env:
            - name: SERVICE_NAME
              value: "productService"

          readinessProbe:
            httpGet:
              path: /health
              port: http
            initialDelaySeconds: 5
            periodSeconds: 5
            timeoutSeconds: 2
            failureThreshold: 3

          livenessProbe:
            httpGet:
              path: /health
              port: http
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 2
            failureThreshold: 3

          resources:
            requests:
              cpu: "250m"
              memory: "256Mi"
            limits:
              cpu: "2"
              memory: "1Gi"

          lifecycle:
            preStop:
              exec:
                command:
                  - /bin/sh
                  - -c
                  - sleep 5
```

---

# 9. ProductService ClusterIP

```yaml
apiVersion: v1
kind: Service
metadata:
  name: product-service
  namespace: swoolefy
spec:
  type: ClusterIP

  selector:
    app: product-service

  ports:
    - name: http
      port: 9501
      targetPort: http
      protocol: TCP
```

---

# 10. ProductService Internal LoadBalancer

```yaml
apiVersion: v1
kind: Service
metadata:
  name: product-service-internal
  namespace: swoolefy

  annotations:
    # 根据实际云厂商替换为 Internal LoadBalancer annotation。

spec:
  type: LoadBalancer

  selector:
    app: product-service

  ports:
    - name: http
      port: 80
      targetPort: http
      protocol: TCP
```

假设最终得到：

```text
order-service-internal
        ↓
10.10.20.30

product-service-internal
        ↓
10.10.20.31
```

---

# 11. 内网 DNS

公司/VPC 内部 DNS 配置：

```text
order-service.dev.internal
        ↓
10.10.20.30
```

```text
product-service.dev.internal
        ↓
10.10.20.31
```

因此外部调用方只需要知道：

```text
order-service.dev.internal
product-service.dev.internal
```

不需要知道 Pod IP。

---

# 12. 调用关系

## K8s 内部

```text
orderService
      ↓
productService
      ↓
product-service:9501
      ↓
productService Pod
```

如果 Swoolefy 已经统一通过 Nacos 做服务发现，则继续使用现有 Nacos 调用机制，不需要为了 K8s 再实现一套服务发现。

## K8s 外部内网

```text
Python / Java / Go / Local
             ↓
product-service.dev.internal
             ↓
Internal LoadBalancer
             ↓
K8s Service
             ↓
productService Pods
```

---

# 13. Local OrderService 调用 Dev ProductService

这是这个架构最重要的开发场景：

```text
Local orderService
        ↓
product-service.dev.internal
        ↓
Internal LoadBalancer
        ↓
K8s Service
        ↓
productService Pod
```

例如：

```env
PRODUCT_SERVICE_URL=http://product-service.dev.internal
```

这样 Local 的 orderService 与 Python、Java、Go 的访问方式保持一致。

---

# 14. Nacos 与 LoadBalancer 的职责边界

不要把两者混成一个东西。

### Nacos

负责：

```text
服务名称
服务实例
IP
端口
健康状态
环境
分组
```

### Kubernetes Service

负责：

```text
Pod 生命周期变化
Pod IP 变化
Pod 扩容
Pod 缩容
```

### Internal LoadBalancer

负责：

```text
K8s 外部内网调用入口
```

### DNS

负责：

```text
稳定的业务服务域名
```

例如：

```text
order-service.dev.internal
product-service.dev.internal
```

---

# 15. 为什么不能让外部系统直接访问 Nacos 返回的 Pod IP

假设 Nacos 返回：

```text
10.244.1.15:9501
```

这可能是 Pod IP。

Pod 重启后可能变成：

```text
10.244.3.21:9501
```

所以 Python 不应该保存：

```text
10.244.1.15
```

而应该：

```text
Python
  ↓
product-service.dev.internal
  ↓
Internal LoadBalancer
  ↓
K8s Service
  ↓
Pod
```

---

# 16. 健康检查

建议 Swoolefy HTTP 服务提供：

```text
GET /health
```

例如：

```json
{
  "status": "UP"
}
```

Kubernetes 流程：

```text
Pod启动
  ↓
Readiness Probe
  ↓
/health
  ↓
HTTP 200
  ↓
加入 Service Endpoints
```

`readinessProbe` 很重要，因为：

```text
进程存在 != 服务已经可以接收请求
```

---

# 17. 优雅关闭

Swoolefy 是常驻进程，Pod 被删除时应：

```text
Pod进入Terminating
       ↓
preStop
       ↓
从Service Endpoint移除
       ↓
等待现有请求
       ↓
SIGTERM
       ↓
Swoolefy优雅关闭
       ↓
进程退出
```

建议：

```yaml
terminationGracePeriodSeconds: 30
```

具体时间根据实际请求最大耗时调整。

---

# 18. PodDisruptionBudget

Order：

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: order-service-pdb
  namespace: swoolefy
spec:
  minAvailable: 1
  selector:
    matchLabels:
      app: order-service
```

Product：

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: product-service-pdb
  namespace: swoolefy
spec:
  minAvailable: 1
  selector:
    matchLabels:
      app: product-service
```

---

# 19. HPA（按需）

初期可以固定：

```yaml
replicas: 2
```

稳定运行后再启用 HPA。

Order：

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: order-service
  namespace: swoolefy
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: order-service

  minReplicas: 2
  maxReplicas: 10

  behavior:
    scaleUp:
      stabilizationWindowSeconds: 0
    scaleDown:
      stabilizationWindowSeconds: 300

  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 70
```

Product 同理。

---

# 20. 推荐 YAML 目录

不要最终把所有资源塞到一个巨大 YAML：

```text
deploy/
└── k8s/
    ├── namespace.yaml
    ├── configmap.yaml
    ├── secret.yaml
    │
    ├── order/
    │   ├── deployment.yaml
    │   ├── service.yaml
    │   ├── loadbalancer.yaml
    │   ├── pdb.yaml
    │   └── hpa.yaml
    │
    └── product/
        ├── deployment.yaml
        ├── service.yaml
        ├── loadbalancer.yaml
        ├── pdb.yaml
        └── hpa.yaml
```

后面增加：

```text
user/
payment/
inventory/
coupon/
```

不会导致单文件越来越大。

---

# 21. 一次部署

```bash
kubectl apply -f deploy/k8s/namespace.yaml
kubectl apply -f deploy/k8s/configmap.yaml
kubectl apply -f deploy/k8s/secret.yaml

kubectl apply -f deploy/k8s/order/
kubectl apply -f deploy/k8s/product/
```

检查：

```bash
kubectl get pods -n swoolefy
kubectl get svc -n swoolefy
kubectl get endpoints -n swoolefy
kubectl get deployment -n swoolefy
```

---

# 22. 部署验证

Pod：

```bash
kubectl get pods -n swoolefy -o wide
```

预期：

```text
order-service-xxx       1/1   Running
order-service-yyy       1/1   Running
product-service-xxx     1/1   Running
product-service-yyy     1/1   Running
```

Service：

```bash
kubectl get svc -n swoolefy
```

预期：

```text
order-service             ClusterIP
order-service-internal    LoadBalancer
product-service           ClusterIP
product-service-internal  LoadBalancer
```

检查 LoadBalancer：

```bash
kubectl get svc order-service-internal -n swoolefy
kubectl get svc product-service-internal -n swoolefy
```

---

# 23. DNS 验证

从 Local / Python / Java / Go 所在机器：

```bash
nslookup order-service.dev.internal
```

例如：

```text
Name:    order-service.dev.internal
Address: 10.10.20.30
```

然后：

```bash
curl http://order-service.dev.internal/health
```

Product：

```bash
curl http://product-service.dev.internal/health
```

---

# 24. 故障排查链路

如果：

```text
Python
 ↓
order-service.dev.internal
```

访问失败，按以下链路排查：

```text
DNS
 ↓
LoadBalancer
 ↓
K8s Service
 ↓
Endpoints
 ↓
Pod
 ↓
Swoolefy
 ↓
HTTP Router
```

命令：

```bash
nslookup order-service.dev.internal

kubectl get svc -n swoolefy

kubectl get endpoints order-service -n swoolefy

kubectl get pods -n swoolefy

kubectl logs -f deployment/order-service -n swoolefy
```

---

# 25. 最终架构

```text
                       ┌──────────────────────┐
                       │        Nacos         │
                       │ Service Discovery    │
                       └──────────┬───────────┘
                                  │
                           Swoolefy Services
                                  │
                 ┌────────────────┴────────────────┐
                 │                                 │
                 ↓                                 ↓
            K8s 内部调用                       K8s 外部调用
                 │                                 │
                 ↓                                 ↓
        ClusterIP / Nacos                 Internal LoadBalancer
                                                   │
                                                   ↓
                                             Internal DNS
                                                   │
                                                   ↓
                                      Python / Java / Go / Local
```

最终：

```text
服务实现：
Swoolefy PHP

服务发现：
Nacos

K8s 内部网络：
ClusterIP

K8s 外部内网入口：
Internal LoadBalancer

统一服务名称：
*.dev.internal

服务生命周期：
Deployment

健康检查：
Readiness / Liveness

滚动发布：
RollingUpdate + PDB

自动扩容：
HPA（按需）
```

## 26. 核心原则

1. **每个服务一个 ClusterIP Service**，负责 K8s 内部通信。
2. **需要被 K8s 外部内网访问的核心服务配置 Internal LoadBalancer**。
3. **使用公司/VPC 内网 DNS 提供稳定业务域名**。
4. **Nacos 继续负责服务注册与发现**，不因为使用 K8s 就删除。
5. **任何业务方都不要直接访问 Pod IP**。
6. **Local / Python / Java / Go 与 K8s 内部调用采用不同网络入口**：
   - K8s → ClusterIP / Nacos
   - K8s 外部内网 → Internal LoadBalancer + DNS
7. **LoadBalancer 应配置为 Internal，而不是公网 LoadBalancer**。
8. **不同云厂商的 Internal LoadBalancer annotation 不同**，部署前只需要替换 YAML 中对应的 annotation。
