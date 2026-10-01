---
name: linear-workflow
description: How to pick up, work on and close punchcard backlog items in Linear (team CHW). Use when the user mentions a CHW-xx issue, asks what to work on next, or when starting, finishing or reporting on a task.
---

# Linear workflow

- Workspace team: **chioua** (key `CHW`). Projects: **punchcard · App** and **punchcard · Marketing website**.
- Milestones follow the roadmap in `docs/spec.md`: Phase 0 hardware spike, Phase 1 MVP core, Phase 2 wallet + push, Phase 3 insights + retention, Phase 4 monetise + scale. Marketing site has its own milestones.
- Labels: area (`backend`, `frontend`, `pwa`, `nfc`, `wallet`, `infra`, `design`, `marketing`, `security`), `spike` for time-boxed investigations, the workspace defaults (`Feature`, `Bug`, `Improvement`), and `side` on every punchcard issue (side-income work).
- Issue numbers: Setup CHW-10..14 + CHW-136, Phase 0 CHW-15..20, Phase 1 CHW-21..37 + CHW-58, 131, 132, Phase 2 CHW-38..42 + CHW-135, Phase 3 CHW-44..50, Phase 4 CHW-51..57 + CHW-43, 59, 133, 134, 137, marketing site CHW-60..74. New issues continue the sequence (check Linear for the latest number).

## Starting an issue

1. Read the issue with the Linear MCP tools (description, acceptance criteria, linked screen IDs such as C2 or B6).
2. Read the matching section of `docs/spec.md` and any skill the issue names.
3. Move it to **In Progress** and create the branch from the issue's `gitBranchName` (e.g. `ahmedchioua/chw-17-port-sun-verification-...`). Linear's GitHub integration links the branch and PR because the name contains the issue key.
4. Respect blockers: if the issue has open "blocked by" relations, say so and suggest the blocking issue instead.
5. Plan first (plan mode) when the issue touches stamps, rewards, auth, billing or tenancy.

## Finishing

1. Tests and checks green locally (`php artisan test`, `npm run check`, `npm run types:check`).
2. Open a PR with `Fixes CHW-<number>` in the description; include screenshots for UI (mobile-safari viewport, light + dark).
3. Comment on the Linear issue with the PR link and anything deferred. Create a new issue for deferred work instead of leaving TODOs in code.
4. Do not mark an issue Done yourself; merging the PR does that through the integration.

## Creating issues

Title in imperative form, description with: context (1–2 lines), acceptance criteria as a checklist, screen IDs / endpoints from `docs/spec.md`, and test notes. Assign the milestone and labels. Keep issues small enough for one PR.
