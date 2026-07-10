# Outdoor — 多 Agent 并行户外骑行演示

三个 Agent 并行准备，再按天气决定是否骑自行车出发：

| Agent | 职责 |
|-------|------|
| **weather**（A） | 判断天气是否适合骑行 |
| **route**（B） | 规划地图路线 |
| **bike**（C） | 准备自行车检查清单 |

天气好 → `go_cycling`；否则 → `stay_home`。

## 流程

```
parallel_prepare (weather ‖ route ‖ bike)
        │
     decide
        ├── weatherGood ──► go_cycling
        └── default ──────► stay_home
```

## HTTP

```bash
# 好天气 → 出发
curl -X POST "http://localhost:9501/api/v1/outdoor/workflow/cycling" \
  -H "Content-Type: application/json" \
  -d '{"destination":"深圳湾公园","weatherHint":"sunny","useMock":true}'

# 雨天 → 留家
curl -X POST "http://localhost:9501/api/v1/outdoor/workflow/cycling" \
  -H "Content-Type: application/json" \
  -d '{"destination":"深圳湾公园","weatherHint":"rainy","useMock":true}'

# 查询状态
curl "http://localhost:9501/api/v1/outdoor/workflow/status?runId=..."
```

`useMock=false` 时走真实/Fake Provider Agent。

也可经通用入口：`POST /api/v1/workflow/run`，`workflowId=outdoor_cycling`。
