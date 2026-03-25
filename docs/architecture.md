# Architecture Overview

AI Tamer is built on several key architectural pillars to ensure robust protection and high performance.

## 1. Selective Triage System
Unlike binary blocking systems, AI Tamer distinguishes between:
- **Search Agents**: Bots that provide citations and traffic (Perplexity, SearchGPT).
- **Training Agents**: Bots that ingest data for model training (GPTBot, Google-Extended).

## 2. Multi-Layer Defense
We don't rely solely on `robots.txt`. The defense is implemented at three levels:
1. **Robots.txt Level**: Standard signals for well-behaved bots.
2. **HTTP Header Level**: Hard signals sent before the content is even parsed.
3. **HTML Meta Level**: `noai`, `noimageai` tags injected globally.

## 3. Visibility and Transparency
To value content, we need to see the consumption. AI Tamer implements a lightweight logging system to track:
- Which AI companies are visiting.
- Which specific articles are most targeted.
- Frequency of access.
- *Requirement*: Enable users to take data-driven decisions on who to block.

## 4. Resource and Infrastructure Protection
To prevent server saturation and bandwidth overconsumption, AI Tamer implements:
- **Rate Limiting**: Control the number of pages an AI can read per minute.
- **Resource Budgeting**: Limit total daily consumption per bot.
- **Guardrail**: Humans are never slowed down by these limits.

## 5. Content Content Isolation
We use a "signal-to-noise" approach to protect server resources:
- Differentiate between editorial content and site "noise" (menus, ads).
- Ensure bots only consume what is explicitly allowed (selective visibility).
- **Guardrail**: No impact on visual experience for real users.

## 6. Dynamic Detection
The plugin uses a centralizable, updatable "blacklist" to handle "long-tail" AI agents that don't identify themselves honestly through standard User-Agents.

## 7. Audit and Evidence
For legal and compliance needs, the plugin maintains a trail of:
- Date-stamped access logs.
- Technical signatures of the agents.
- Downloadable reports for manual auditing or legal evidence.
