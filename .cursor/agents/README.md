# Canonical agent pack (Development Control)

**Source of truth:** `development/development-control/templates/agents/`  
On project **setup / uplift**, copy or sync these into **that project’s** `.cursor/agents/` (never leave agents only in another product repo).

**Everyday agent:** `composer-2.5-fast` (review on every starter run).

**Model policy (C4):** `../rules/model-selection-policy.md` — Composer-pool only; **never Auto**; **cheapest model for every role**.

**Roster policy:** `../rules/development/agent-roster.mdc` — vanilla starter = basic agents only; **`pm` creates extras** under `.cursor/agents/` after Lee confirms.

Pear Drops–style: **PM coordinates**, **specialists implement** (when present), PM **auto-advances stages** that pass (brief note only), **Lee** for milestone completion + failures.

## Basic pack (vanilla default)

| Agent role | Selected model | Pool | Fallback | Justification |
|------------|----------------|------|----------|---------------|
| Project Manager (`pm`) | `composer-2.5-fast` | Composer pool | `cursor-grok-4.5-high-fast` | Cheapest Composer-pool model |
| Secretary (`secretary`) | `composer-2.5-fast` | Composer pool | `cursor-grok-4.5-high-fast` | Cheapest Composer-pool model |

**API-pool / Auto:** none in the default pack.

- **Operates entirely within Composer-pool allowance:** yes.  
- **If a Composer-pool model becomes unavailable:** switch to its fallback immediately; do not use Auto or API-pool substitutes.

**Never use Auto** unless Lee explicitly requests it.

### Rosters

| Roster | Agents |
|--------|--------|
| **Basic** (default) | `secretary`, `pm` |
| **Extended** | Basic + specialists **`pm` created** after Lee confirmed for this project |

Product-specific agents (e.g. Report Hub builders) live in that product repo — **not** in the vanilla starter pack.

### Sync rules

1. Compare project `.cursor/agents/*.md` to this folder for **basic** agents (names + `model:` frontmatter + Model allocation block).
2. Missing basic agents → copy from here into the **project’s** `.cursor/agents/`.
3. Existing agents that differ → **ask Lee** before overwrite (show diff summary).
4. Additional specialists → **`pm` proposes**, Lee confirms, then **`pm` writes** `.cursor/agents/*.md` (see `agent-roster.mdc`).
5. Enforce model-selection policy: prefer included; no external without fallback; no on-demand spend.
6. If a `model:` slug looks unavailable → **ask Lee**; do not invent slugs or paid routes.
7. After sync, ensure project orchestration rule matches the installed roster.
8. Update the project’s `.cursor/agents/README.md` with the allocation table.

### Flow (set in stone)

0. Project readiness + **env health check** (`new-project-start.md` / `dev-environment-health-check.md`)
1. Rough notes → secretary → questions if unsure → stop for Lee
2. Lee approves brief → PM → full plan → Lee agrees plan
3. Build → review → gate (PM advances stages that pass; short “stage passed” note) → on failure stop for Lee → milestone complete → Lee sign-off **triggers next M#** (C8)
4. Teams **after** a unit finishes (`complete` / `sign_off` / `failure`) — not before, not for stage passes or mid-work questions (C13)
5. Env/Git/OneDrive issues → suggest or run health check (PM)
