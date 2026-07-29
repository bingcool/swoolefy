---
name: weather-ops
description: How to use weather tools (get_date / get_weather) and interpret mock results
---

# Weather Ops

When the user asks about weather for a city:

1. If the date is unclear, call `get_date` first (Asia/Shanghai, YYYY-MM-DD).
2. Call `get_weather` with `location` (city) and `date`.
3. Answer from tool JSON only (`weather`, `temperature`, `source`). Do not invent data.
4. Reply in the same language as the user.

Known demo cities: 深圳/Shenzhen, 杭州/Hangzhou, 广州/Guangzhou, 北京/Beijing, 上海/Shanghai.
