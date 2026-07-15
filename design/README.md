# Design

**Drop the design proposal from Claude Design into this folder.**

The design brief that was written for Claude Design is included here as [`DESIGN_BRIEF.md`](DESIGN_BRIEF.md) — it's the prompt used to generate the visual proposal. When Claude Design produces the screens (HTML/CSS mockups, component files, style tokens, screenshots), save them here, e.g.:

```
design/
├─ DESIGN_BRIEF.md        ← the brief given to Claude Design (already here)
├─ tokens.css             ← color/type/spacing tokens (once designed)
├─ board/                 ← contestant board mockups
├─ admin/                 ← admin cockpit mockups
└─ screenshots/           ← reference renders
```

## For the developer agent
Do not start building the front-end views until a design proposal is present here. Until then, work on the back end (schema, CRUD, API, game logic) and use plausible mock state for any view scaffolding. Once the design lands, implement the views in `public/` from these files, extracting shared tokens (colors, type, spacing) into a single stylesheet.

The one hard visual constraint from the brief: **blue vs. red team colors stay distinct, consistent across both the board and the cockpit, and distinguishable for color-blind viewers** (pair color with shape/label/position, not hue alone).
