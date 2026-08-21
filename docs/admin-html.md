# Swoolefy Cron Admin HTML 拆分方案

## 1. 背景

当前 Swoolefy Cron Web Admin 的 HTML 页面集中在一个文件中。

随着后续增加任务管理、执行记录、执行日志、节点管理等页面，单文件会持续膨胀，最终导致：

- HTML 结构难以维护
- JavaScript 持续堆积
- CSS 相互影响
- 修改一个功能容易影响其他页面
- 后续增加页面时复用困难

因此建议在当前阶段进行适度拆分。

---

## 2. 设计原则

本次拆分遵循以下原则：

1. **保持轻量**
   - 暂不引入 Vue、React 等前端框架。
   - 保持现有静态 HTML + CSS + JavaScript 方案。

2. **按页面职责拆分**
   - 一个主要业务页面对应一个 HTML。
   - 页面之间保持独立。

3. **公共代码复用**
   - 公共 API、Modal、Toast、状态格式化等放入公共 JS。
   - 公共布局和样式放入公共资源。

4. **不过度组件化**
   - 不拆分 button、input、table 等细粒度组件。
   - 暂时不建立复杂模板/组件系统。

5. **保持现有 UI**
   - 本次主要解决代码组织问题，不要求重新设计 UI。

---

## 3. 推荐目录结构

```text
cron-admin/
├── index.html                 # 任务列表
├── task.html                  # 创建/编辑任务
├── execution.html             # 执行记录
├── log.html                   # 执行日志
│
├── assets/
│   ├── css/
│   │   ├── common.css         # 公共样式
│   │   ├── task.css           # 任务页面样式
│   │   ├── execution.css      # 执行记录页面样式
│   │   └── log.css            # 日志页面样式
│   │
│   └── js/
│       ├── common.js          # 公共 JS
│       ├── task.js            # 任务管理
│       ├── execution.js       # 执行记录
│       └── log.js             # 日志查看
│
└── components/
    ├── header.html            # 公共头部（需要模板复用时使用）
    ├── sidebar.html           # 公共侧边栏
    └── modal.html             # 公共弹窗
```

如果当前 Admin 仍然是纯静态 Demo，`components/` 可以暂时不使用。

---

## 4. 页面职责

### 4.1 index.html

任务列表页。

负责：

- 任务列表
- 搜索
- 状态筛选
- 执行类型筛选
- 创建任务
- 编辑任务
- 启用/禁用任务
- 立即执行
- 删除任务
- 查看执行记录

页面只负责任务列表相关 UI 和交互。

---

### 4.2 task.html

创建/编辑任务页面。

负责：

- 创建任务
- 编辑任务
- 任务名称
- Cron 表达式
- 执行类型
- Shell/PHP/Python 命令
- HTTP 配置
- 并发策略
- 任务描述
- 任务状态

创建和编辑复用同一个页面：

```text
/task.html
```

创建任务。

```text
/task.html?id=123
```

编辑任务。

避免维护两个高度相似的页面。

---

### 4.3 execution.html

任务执行记录页面。

负责：

- 执行历史
- 执行状态
- PID
- 开始时间
- 结束时间
- 执行耗时
- Exit Code
- 查看执行日志

页面关系：

```text
任务
  ↓
执行记录
  ↓
某一次执行
  ↓
stdout / stderr
```

---

### 4.4 log.html

单次执行日志页面。

负责：

- stdout
- stderr
- 执行结果
- Exit Code
- 错误信息
- 日志大小
- 日志查看
- 日志下载（后续需要时再增加）

stdout/stderr 不建议逐行写入数据库。

推荐：

```text
数据库：
    执行状态
    exit_code
    日志文件信息

文件：
    stdout
    stderr
```

这样长期运行并且频繁 `echo` 的 PHP/Python/Shell 脚本不会产生大量数据库日志记录。

---

## 5. JavaScript 拆分

### 5.1 common.js

放公共能力：

```text
API 请求
Toast
Modal
Confirm
时间格式化
状态格式化
分页
公共工具函数
```

例如：

```javascript
api.get()
api.post()
api.put()
api.delete()

showToast()
showConfirm()

formatDate()
formatDuration()
formatStatus()
```

---

### 5.2 task.js

只处理任务管理：

```text
loadTasks()
createTask()
updateTask()
deleteTask()
enableTask()
disableTask()
runTask()
```

---

### 5.3 execution.js

只处理执行记录：

```text
loadExecutions()
filterExecutions()
viewExecutionLog()
```

---

### 5.4 log.js

只处理执行日志：

```text
loadStdout()
loadStderr()
refreshLog()
downloadLog()
```

如果后续增加实时日志，只在这里增加轮询/刷新逻辑。

---

## 6. CSS 拆分

### common.css

只放公共样式：

```text
页面布局
顶部导航
侧边栏
按钮
表格
表单
Modal
Toast
Badge
Pagination
```

### task.css

任务页面特有样式。

### execution.css

执行记录页面特有样式。

### log.css

日志查看页面特有样式。

原则：

> 能放 common.css 的样式，不要重复写到页面 CSS 中。

---

## 7. Components 是否需要现在建立

目前不建议马上建立复杂组件系统。

例如不需要拆成：

```text
components/
├── button.html
├── input.html
├── select.html
├── table.html
├── badge.html
├── pagination.html
└── modal.html
```

这种拆分对于当前轻量 Admin 收益有限。

如果后续确实存在大量页面共享结构，再考虑：

```text
components/
├── header.html
├── sidebar.html
└── modal.html
```

即可。

---

## 8. 页面关系

推荐保持简单的页面跳转关系：

```text
                ┌──────────────┐
                │   index.html │
                │   任务列表    │
                └──────┬───────┘
                       │
          ┌────────────┼────────────┐
          ↓            ↓            ↓
   ┌────────────┐ ┌────────────┐ ┌────────────┐
   │ task.html  │ │execution   │ │ 立即执行    │
   │ 创建/编辑   │ │   .html    │ │            │
   └────────────┘ └──────┬─────┘ └────────────┘
                         │
                         ↓
                  ┌────────────┐
                  │  log.html  │
                  │ stdout/err │
                  └────────────┘
```

不需要建立复杂的前端路由系统。

---

## 9. API 与页面职责分离

前端页面不要把 API 地址散落在各处。

可以在 `common.js` 中统一：

```javascript
const API = {
    cronTask: '/api/cron/tasks',
    executions: '/api/cron/executions',
    logs: '/api/cron/logs'
};
```

页面统一使用：

```javascript
api.get('/api/cron/tasks');
api.post('/api/cron/tasks', data);
api.put('/api/cron/tasks/123', data);
api.delete('/api/cron/tasks/123');
```

页面只关注业务，不负责处理底层请求细节。

---

## 10. 暂不引入的技术

本次 HTML 拆分不建议同时引入：

- Vue
- React
- Angular
- 前端路由框架
- 状态管理框架
- Webpack/Vite
- 前端微应用
- 复杂组件系统
- 前端工程化构建体系

原因：

> 当前问题是单 HTML 文件越来越大，而不是前端技术能力不足。

先解决代码组织问题即可。

---

## 11. 后续扩展

拆分之后，未来增加功能时可以继续按照页面职责增加。

例如：

```text
node.html
node.js
node.css
```

负责：

- 节点列表
- 节点状态
- 节点信息

如果以后增加 Cron 配置/运行统计，也可以独立：

```text
dashboard.html
dashboard.js
dashboard.css
```

不会继续把所有内容堆进 `index.html`。

---

## 12. 与 Cron 子进程日志设计保持一致

Admin 页面拆分还需要配合 Cron 执行日志设计。

对于执行脚本：

```text
fork()
  │
  ├─ fork 失败 → FAILED
  │
  └─ fork 成功
       │
       ↓
     执行脚本
       │
       ├─ stdout/stderr → 日志文件
       │
       ↓
     waitpid()
       │
       ├─ exit 0      → SUCCESS
       ├─ exit != 0   → FAILED
       └─ signal      → KILLED
```

Admin 不需要把 stdout/stderr 设计成大量数据库记录。

数据库主要展示：

```text
执行状态
PID
开始时间
结束时间
执行耗时
Exit Code
日志文件信息
```

用户进入 `log.html` 后，再读取对应 stdout/stderr。

这样可以避免循环任务中大量：

```php
echo "processing...";
```

导致数据库产生海量日志记录。

---

## 13. 最终推荐落地结构

当前阶段推荐直接采用：

```text
cron-admin/
├── index.html
├── task.html
├── execution.html
├── log.html
│
└── assets/
    ├── css/
    │   ├── common.css
    │   ├── task.css
    │   ├── execution.css
    │   └── log.css
    │
    └── js/
        ├── common.js
        ├── task.js
        ├── execution.js
        └── log.js
```

`components/` 暂时可以不建立。

---

## 14. 核心原则

```text
一个主要业务页面
        ↓
一个 HTML
        ↓
一个主要 JS
        ↓
一个主要 CSS

公共能力
        ↓
common.js / common.css
```

最终目标不是建设一个完整的前端框架，而是：

> **在保持 Swoolefy Cron Admin 轻量的前提下，让代码能够随着功能增加继续维护。**

因此本方案只解决当前真正存在的问题：

**单 HTML 文件越来越大。**

不提前引入复杂的前端工程体系，也不为了“组件化”而组件化。
