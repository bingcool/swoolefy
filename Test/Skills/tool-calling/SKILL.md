---
name: tool-calling
description: Prefer calling tools before answering when factual or procedural data is needed
---

# Tool Calling

- Prefer tools over guessing when the answer depends on external or structured data.
- Call tools that match the user intent; pass required arguments explicitly.
- After a tool result, ground the final answer in that result.
- If a skill_* tool exists for a procedure, call it once, then follow its steps.
