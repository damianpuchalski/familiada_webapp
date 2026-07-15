# Workflow — working with the three agents

Three Claude Code sub-agents are defined in [`../.claude/agents/`](../.claude/agents/). This describes how to hand work between them. You (the human) stay in the loop and approve steps.

## The roles

- **architect** — Opus (high). Owns `docs/` and `db/schema.sql`. Makes and records design decisions, resolves ambiguity, decides whether a proposed approach fits the architecture. Does **not** write large amounts of feature code.
- **developer** — Sonnet (high). Writes the PHP/JS/CSS from the specs in `docs/`. Builds endpoints, views, and game logic. Asks the architect when a decision is missing rather than inventing one.
- **tester** — Sonnet (medium). Writes and runs tests, focuses on the game-rule edge cases in `GAME_RULES.md`. Reports failures precisely; does not redesign.

## The loop

```
        ┌─────────────┐
        │  architect  │  decides / approves approach, updates docs
        └──────┬──────┘
               │ spec is clear
               ▼
        ┌─────────────┐
        │  developer  │  builds the feature
        └──────┬──────┘
               │ feature done
               ▼
        ┌─────────────┐
        │   tester    │  verifies against GAME_RULES.md
        └──────┬──────┘
        pass ──┘   fail → back to developer
     (design gap) → back to architect
```

## Practical sequence for this project

Follow the build order in `ARCHITECTURE.md`. Suggested first tickets:

1. **architect:** confirm `db/schema.sql` matches `DATA_MODEL.md`; lock the library-vs-frozen-copy representation.
2. **developer:** build content CRUD (question sets / questions / answers).
3. **developer:** static board + admin cockpit (mock state) — *after* the design proposal lands in `design/`.
4. **developer:** `state.php` + `action.php` + polling.
5. **developer:** implement `src/game/` state machine + scoring.
6. **tester:** write tests for every invariant in `GAME_RULES.md` ("Validation invariants").
7. **developer:** game modes → finale + timer → sounds.
8. **developer + tester:** lifecycle (statuses, single-live pointer, resume, history).

## Handoff etiquette
- The **spec is the contract.** If code and `docs/` disagree, the docs win until the architect changes them.
- Developer: when you hit an undocumented decision, stop and ask the architect; note the resolution back into the relevant doc.
- Tester: turn each clause of `GAME_RULES.md` into at least one test. Report the rule reference (e.g. "GAME_RULES §4 steal fails") with each failure.
- Keep `docs/` current — it is what makes the agents interchangeable across sessions.

## Invoking agents in Claude Code
With this repo open in VS Code, the agents are available as sub-agents (defined in `.claude/agents/*.md`). Address a role explicitly, e.g. "As the architect, review whether…" or use Claude Code's agent selection. Each definition pins the intended model; you can override per session if needed.
