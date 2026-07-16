# Familiada — Web Game (Family Feud / Familiada)

A browser-based version of the Polish TV show **Familiada** (Family Feud). One operator (the **host/admin**) runs a live game from a control panel; the room and contestants watch a separate **big-screen board**. Two teams — **blue** and **red** — compete to guess the most popular survey answers.

Built with **PHP + MySQL + HTML/CSS/JS**, designed to run on standard **cPanel shared hosting**. Real-time sync between the admin panel and the contestant board is done with ~1s **AJAX polling** (no WebSocket server required).

> **Status:** scaffolding + specification. This repo is a starting point for development with Claude Code. The design proposal (from Claude Design) will be dropped into `design/` before front-end work begins.

---

## Quick start (for the human)

1. **Read the docs, in order:**
   - [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — the full technical design (this is the source of truth).
   - [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md) — what was agreed, in plain language.
   - [`docs/DATA_MODEL.md`](docs/DATA_MODEL.md) — the database tables and why.
   - [`docs/GAME_RULES.md`](docs/GAME_RULES.md) — the strike/steal/scoring rules the code must enforce.
2. **Drop the design proposal** from Claude Design into [`design/`](design/) (see [`design/README.md`](design/README.md)).
3. **Check your host** against [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) (PHP version, polling limits).
4. **Open in VS Code with Claude Code** and use the agents (see below).

## Working with Claude Code agents

Three roles are defined in [`.claude/agents/`](.claude/agents/). See [`docs/WORKFLOW.md`](docs/WORKFLOW.md) for how to hand work between them.

| Agent | Model | Use for |
|-------|-------|---------|
| **architect** | Opus (high) | Design decisions, schema changes, resolving ambiguity, reviewing whether an approach fits the architecture. |
| **developer** | Sonnet (high) | Writing the PHP/JS/CSS, building endpoints and views, wiring the game logic. |
| **tester** | Sonnet (medium) | Writing tests, verifying game-rule edge cases, checking the strike/steal state machine and scoring. |

A typical loop: **architect** approves an approach → **developer** builds it → **tester** verifies it against the game rules → back to architect if something doesn't fit.

## Repo layout

```
familiada/
├─ README.md               ← you are here
├─ .claude/
│  ├─ agents/              ← agent role definitions (architect, developer, tester)
│  └─ settings.json        ← shared Claude Code project settings
├─ docs/                   ← the specification (source of truth)
│  ├─ ARCHITECTURE.md
│  ├─ REQUIREMENTS.md
│  ├─ DATA_MODEL.md
│  ├─ GAME_RULES.md
│  ├─ WORKFLOW.md
│  └─ DEPLOYMENT.md
├─ design/                 ← Claude Design output goes here (empty for now)
│  └─ README.md
├─ db/
│  └─ schema.sql           ← MySQL schema (starting point, matches DATA_MODEL.md)
├─ src/
│  ├─ lib/                 ← db connection, helpers
│  └─ game/                ← game logic (state machine, scoring)
├─ public/                 ← web root (point your domain / subdomain here)
│  ├─ index.php            ← contestant big-screen board (Plansza), at the root
│  ├─ admin/               ← admin cockpit + editor + history views
│  ├─ api/                 ← polling + action endpoints (state.php etc.)
│  └─ assets/
│     └─ sounds/           ← sound cues (must live under public/ — the web root)
├─ config.example.php      ← copy to config.php and fill in DB creds
└─ .gitignore
```

## What this is NOT (yet)

- No back-end code is written — only the schema and the spec. The developer agent builds from `docs/`.
- No visual design — that comes from Claude Design into `design/`.
- Not tied to any framework. Plain PHP by design, to stay cPanel-friendly.
