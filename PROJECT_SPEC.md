# Familiada — Final Project Specification

**Status:** Ready for build. This is the single, consolidated source of truth for the Familiada (Family Feud) web game. It merges the original specification in `docs/` with the Claude Design handoff in `design/design_handoff_familiada/`, and records every place the two diverged (see [§10 Reconciliation & change log](#10-reconciliation--change-log)).

**Rule of precedence:** where this document and any older doc disagree, **this document wins** until the architect deliberately changes it. Where this document and code disagree, this document wins. The detailed `docs/*.md` files remain valid as deep-dive appendices; this file is what you read first.

---

## 1. What we are building

A browser-based version of the Polish TV show **Familiada** (Family Feud). One operator — the **host** — runs a live game from a control panel; a room of contestants watches a separate **big-screen board** (TV/projector). Two teams, **blue** and **red**, compete to guess the most popular survey answers.

**V1 ships four screens**, grouped into two deliberately different visual worlds. A fifth (Grand Finale) is **deferred to V2** — retained in schema/rules but disabled everywhere in V1 (see [§4.5](#45-grand-finale--v2--disabled-in-v1)).

| # | Screen (PL name) | World | Audience | Auth |
|---|------------------|-------|----------|------|
| 1 | **Plansza** — contestant board | Stage (theatrical) | The room / projector | Public, no login |
| 2 | **Prezenter** — host cockpit | Cockpit (calm, dense) | The host | Behind login |
| 3 | **Logowanie** — host login gate | Cockpit | The host | The gate itself |
| 4 | **Administrator** — game & sound manager | Cockpit | The host | Behind login |
| — | ~~Grand Finale — board + admin panel~~ | Stage + cockpit | Room + host | **Deferred to V2** |

> **Language:** the user interface is **Polish** (final-intent copy from the design). Code, comments, and these docs stay in English. Screen names above are the canonical UI names.

**Tech stack:** PHP 8.1+ / MySQL / HTML / CSS / vanilla JS, deployed on **standard cPanel shared hosting**. No framework, no build step required for the back end, no WebSocket server. Real-time sync is done with **~1 s AJAX polling**.

---

## 2. Users & the two visual worlds

**The host** — one person at a laptop, running a live game in front of a room, under mild time pressure. Needs speed, big unmistakable controls, and no hunting for the next action.

**The room / contestants** — watch a shared display from across a room. Legibility and drama matter; they never interact with it.

The **Plansza (board)** is the *stage*: big, theatrical, LED-matrix game-show aesthetic, built for reveal moments. Everything on it is a reaction to what the host clicks. The **Prezenter / Administrator / Logowanie** screens are the *cockpit*: dense, functional, calm, fast — a quiet instrument panel. The two worlds share a game-state vocabulary (teams, strikes, round, points) and type family so they feel related, but they are two moods, not one app in two colors.

---

## 3. Architecture

### 3.1 The one decision that matters: real-time sync

**Chosen approach: AJAX polling.** The board polls a small endpoint (`/api/state.php?game_id=X`) every **~1 s** (configurable, `poll_interval_ms`, default 1000) and re-renders from the returned JSON. The host writes state on each action via `/api/action.php`; the board picks it up on the next poll.

Why polling and not the alternatives:

- **Polling** works on *any* cPanel host, is trivial and bulletproof. For one board + one host the traffic is negligible, and the ~1 s delay is hidden behind reveal/strike animations — the animation *is* the payoff. **This is the choice.**
- **SSE** — cleaner one-way push but can collide with PHP `max_execution_time` on shared hosting. Acceptable future upgrade, not required.
- **WebSockets** — true bidirectional but needs a long-running process the shared host may not allow. Overkill. Only revisit if the game grows to many simultaneous independent boards.

**Consequence:** all live state lives in the database (`game_state`), never only in a browser. This is what makes "resume an unfinished game" almost free.

The cPanel account *can* also deploy Django/Node/Rails (which would unlock a persistent WebSocket server). We deliberately do **not** use it. That panel is the escape hatch if true push is ever needed; out of scope now.

### 3.2 Components

```
┌─────────────────┐        writes        ┌──────────────┐
│  Prezenter      │ ───────────────────► │              │
│  (host, browser)│   POST /api/action   │   MySQL      │
└─────────────────┘                      │  game_state  │
                                         │  + content   │
┌─────────────────┐   GET /api/state     │  + history   │
│ Plansza (board) │ ◄─────────────────── │              │
│  (browser, TV)  │   poll ~1s           └──────────────┘
└─────────────────┘
```

- **`public/`** (root, `index.php`) — Plansza + Grand Finale board. Read-only, polls state.
- **`public/admin/`** — Prezenter cockpit, Administrator (games + sounds), Grand Finale admin panel, and the game editor.
- **`public/api/`** — thin endpoints:
  - `state.php` — returns current board state as JSON (the poll target). **Also returns the server timestamp** (needed for the finale clock).
  - `action.php` — receives host actions (reveal, strike, finish round, set team, set-live, timer control, etc.), validates them against the state machine, updates `game_state`.
  - plus CRUD/auth endpoints (login/session, games CRUD, sound-file upload) — see §7.
- **`src/game/`** — the strike/steal state machine and scoring, kept out of the view layer so it can be unit-tested.
- **`src/lib/`** — DB connection, helpers, session/auth guard.

### 3.3 Non-negotiable quality floor

- Board legible across a room (TV/projector target).
- **Blue vs. red must stay clearly distinct and consistent across both worlds, and distinguishable for color-blind viewers** — pair color with shape/label/position, never rely on hue alone. This is the one hard visual constraint.
- Reduced-motion respected (`prefers-reduced-motion`); keyboard focus visible in the cockpit.
- All state changes go through validated server actions — **never trust the client**; recompute scores server-side.

---

## 4. Game rules (exact — the code in `src/game/` must enforce these)

The host can only trigger **legal** transitions; illegal actions are rejected/disabled. Written precisely so each clause becomes a test.

### 4.1 Terms

- **Starting team** — the team that begins a round. Strikes count against them.
- **Round pot** — sum of the points of answers revealed so far this round (raw, pre-multiplier).
- **Multiplier** — from the round (classic mode) or always ×1 (free-rounds mode).
- **Steal** — the other team's single attempt after 3 strikes.

### 4.2 Round flow (state machine)

State fields (in `game_state`): `phase`, `starting_team`, `active_team`, `strikes`, `steal_in_progress`, `round_pot`.

1. **Round start** (`phase = round`)
   - `starting_team` and `active_team` are set to the same team (host chooses via the team selector).
   - `strikes = 0`, `steal_in_progress = false`, `round_pot = 0`, all answers hidden.

2. **Reveal answer** (legal in `phase = round` or during `steal`)
   - Host clicks an unrevealed answer → it becomes `revealed`; `round_pot += answer.points`; play `correct` sound.
   - Revealing does **not** by itself change turns.

3. **Strike** (legal in `phase = round`)
   - Host clicks Strike (**BŁĄD**) — the spoken answer isn't on the board. `strikes += 1`; play `strike` sound.
   - **strikes == 1:** nothing else happens.
   - **strikes == 2:** nothing mechanical (the other team may quietly discuss). Counter only.
   - **strikes == 3:** control passes → `phase = steal`, `steal_in_progress = true`, `active_team = opposite(starting_team)`. Strikes stop accumulating.

4. **Steal attempt** (`phase = steal`, `steal_in_progress = true`)
   - The stealing team gives **exactly one** answer.
   - **Steal succeeds** (host reveals an answer): `round_pot += points` → award the **entire round pot to the stealing team**. Go to `round_end`.
   - **Steal fails** (host clicks Strike): award the **entire round pot to the starting team**. Go to `round_end`.
   - Only one steal action is allowed; after it, no more reveals/strikes this round.

5. **Round end** (`phase = round_end`)
   - Compute `awarded = round_pot * multiplier`.
   - Add `awarded` to the winning team's `teams.score`; write a `round_results` row (team, round_number, points_awarded, multiplier_applied).
   - Host clicks **ZAKOŃCZ RUNDĘ (finish round)** → advance to the next set (`phase = round` with the next `current_game_set_id`), or to `finale`/`finished` per mode.

> **Board-cleared win:** if all answers on a board are revealed before 3 strikes, the round can also end by host action (finish round) with the pot going to the currently-owning team. A cleared board is a normal win, not only strikes.

### 4.3 Turn ownership subtleties (from the design — adopted)

- **No manual "pass turn."** Steal is triggered **only** by the 3rd strike on the active team. There is no separate pass-to-other-team button. *(This supersedes the "pass-to-other-team" control mentioned in the original brief — see §10.)*
- **Team selector locks at 3 revealed answers.** The host may change which team owns the round only while **fewer than 3 answers** are revealed in the current question. Once ≥3 are revealed, the selector locks (`teamSelectLocked`) — the winning team is understood to decide who plays next, so no late re-assignment.
- Strikes are always tracked against `starting_team`, even after control visually shifts. During a steal, a wrong answer (strike) does **not** increment toward another steal — it simply ends the round in favor of `starting_team`.

### 4.4 Scoring modes

**Classic (300):** multipliers by round — **1→×1, 2→×1, 3→×1, 4→×2, 5→×3**. After each round end, check both totals. **If either team ≥ 300 → game ends** (`phase = finished`), that team wins. *(In V2, if grand finale were enabled it would run as its own phase; in V1 it is disabled and games always end at `finished` — §4.5.)*

**Free rounds:** every round ×1, any number of rounds. When the sets run out (or the host ends the game), the **higher score wins**.

### 4.5 Grand finale — V2 (disabled in V1)

> **Status: deferred to V2.** The finale is **not part of the V1 release**. Keep the supporting schema (`games.grand_finale`, the `game_state` finale anchors, `finale_answers`, the `finale_timer` sound cue) so V2 can build on it without a migration, but in V1 **the feature is off everywhere**:
> - The finale on/off toggle in the game editor is **rendered disabled** with a "Wkrótce (V2)" hint; `games.grand_finale` is forced to `0`.
> - The `finale` phase is never entered; classic/free-rounds games end at `finished`.
> - No finale board or finale admin screen ships in V1.
>
> The rules below are the **intended V2 behavior**, recorded now so nothing is lost.

Two contestants answer the **same 5 questions** in turn.

- **Contestant 1: 15 s. Contestant 2: 20 s.**
- The host types each spoken answer (`finale_answers.typed_answer`) and **manually matches** it to a scored answer (`matched_answer_id`, `points_awarded`). Host judges the match.
- A large countdown timer is the board's focal point, with states **idle / running / paused / expired**. The paused state must be obvious from across the room. Host controls: **Start / Pause / Resume / Reset**.

**Timer stored as anchors, not a decrementing counter** (avoids drift, works with polling):
`finale_timer_status`, `finale_duration`, `finale_started_at`, `finale_elapsed_before`. Every client computes remaining time from these plus the server `now`:

```
elapsed   = finale_elapsed_before + (status == running ? server_now - finale_started_at : 0)
remaining = max(0, finale_duration - elapsed)
```

- **Start:** status=running, started_at=now, elapsed_before=0.
- **Pause:** status=paused, elapsed_before += (now − started_at), started_at=null.
- **Resume:** status=running, started_at=now.
- **Reset:** status=idle, elapsed_before=0, started_at=null, duration=(15 or 20).
- **Expire:** when remaining hits 0, transition to `expired` **once**; play buzzer **once** (fire on transition, not per poll).

Use the **server clock** consistently; the host laptop and TV machine may differ. Browsers may animate smoothly via `requestAnimationFrame` but must snap to the server-anchored truth on each poll.

> **Why deferred:** the Claude Design handoff delivered the board, cockpit, login and admin — but **not** the two Grand Finale screens the brief asked for. Rather than block V1 on new design work, the finale is parked for V2. The backend and rules already support it. See §10 (Decision A).

### 4.6 Validation invariants (for the tester)

- `strikes` is always 0–3.
- `active_team` is always `blue` or `red`.
- A round awards its pot to **exactly one** team, exactly once.
- `round_pot` after finish equals the sum of that round's revealed answer points.
- `awarded` written to history == `round_pot * multiplier_applied`.
- Only **one** steal action per round; no reveals/strikes accepted after `round_end`.
- Exactly **one** game is `live` / pointed to by `active_game` at any time.
- Finale `remaining` never goes negative; pausing freezes it; resuming continues from the frozen value.

---

## 5. Screen specifications

### 5.1 Plansza — contestant board (public)

Full-bleed dark stage, LED-matrix game-show aesthetic — the one theatrical screen. Layout centers on the question, then a horizontal row of **[red score box] [strikes ×3] [answer panel] [strikes ×3] [blue score box]**. Two large decorative arcs glow top-left (red) / top-right (blue); background is a soft red/blue radial blend, dark at the edges.

- **Question banner** — centered, prominent.
- **Answer panel** — rounded card with a scanline texture, up to **6 dot-matrix answer rows**: index number, answer text (blank until revealed), dotted leader line, point value. Revealed text/points glow amber with a "LED ignite" flicker; hidden rows are **genuinely empty** (not blanked-out boxes), per authentic scoreboard behavior.
- **Round total** — small amber LED readout ("SUMA {n}") docked above the panel; pulses on each reveal.
- **Score boxes** — left = red team, right = blue team; amber LED digits; glow intensifies for the team currently on the clock.
- **Strike glyphs** — up to 3 small X's per side, dim until struck, then ignite red with a shake/flash. During **steal mode**, the non-active team's strikes collapse into one large dim X that lights fully red only the moment the steal fails.

Design both hidden and revealed answer states and the transition between them. This is where the "signature moment" lives — make the reveal land.

### 5.2 Prezenter — host cockpit (behind login)

Dense two-column grid (`1fr 340px`) under a fixed ~56 px header. The host sees *everything at once*: the full answer list with points and reveal status, strike controls, whose turn it is, both scores, the round multiplier, and the round-flow buttons. Nothing theatrical.

- **Header:** wordmark, "PANEL STEROWANIA" label, round-number chip, multiplier chip (×1/×2/×3), and a **read-only** line showing the current game's configured sound set (e.g. "ZESTAW DŹWIĘKÓW GRY · Klasyczny"). *Display-only* — the sound set is chosen in the Administrator game editor, not here (see §10, change 3).
- **Left column:** current question header + a scrollable list of **6 answer rows** — index, answer text, points, a revealed/hidden status pill, and a per-row **ODKRYJ (reveal)** button (disabled once revealed or once a steal has resolved).
- **Right sidebar:**
  - Two **score cards** (NIEBIESCY/blue, CZERWONI/red) — highlight the team on the clock.
  - **Team selector** — two toggle buttons; dimmed/disabled once ≥3 answers are revealed this question (`teamSelectLocked`). During steal, a small amber **"KRADZIEŻ AKTYWNA"** (steal active) badge appears beneath it.
  - **Strikes** — "BŁĘDY {n}/3" counter, 3 mini indicator squares, and a full-width **BŁĄD** (strike) button. During steal, this button registers the single steal-ending strike.
  - **ZAKOŃCZ RUNDĘ** (finish round) — green, primary, always available; banks the pot to the correct team and advances.
  - **DŹWIĘKI · WYZWÓL RĘCZNIE** (manual sound triggers) — a 2×3 grid of the 6 cue buttons. These are **manual overrides** for cues that otherwise fire automatically (e.g. re-cue a missed trigger), not the normal way sound plays. They call the same audio-trigger the automatic events call.

### 5.3 Logowanie — host login gate

The front door to Prezenter/Administrator. One **shared host password**, no username. Cockpit world — calm, restrained, small amber accent, never the board's red/blue theatrics. Centered card (max-width ~380 px) on a dark radial-gradient background: wordmark + "PANEL STEROWANIA" subtitle → optional session-expired notice → password field with inline show/hide toggle → inline error line (only on failure) → full-width primary button → footer disclaimer.

States to build: **Default/empty** (button fades until text typed) · **Focused/typing** (real amber keyboard-focus ring — keep it a genuine `:focus` style) · **Submitting** ("Sprawdzanie…", ~70% opacity, disabled) · **Error** (muted amber/orange border + calm inline line "Nieprawidłowe hasło. Spróbuj ponownie."; **preserve** typed text, never hint at closeness) · **Session-expired** (quiet neutral info line "Sesja wygasła…", not error-colored).

Interaction: **autofocus** the password field on mount and on session-expired; **Enter submits**; primary button is large (44px+), full-width. The two entry points (Prezenter, Administrator) both route through this gate when the session isn't authenticated, then continue to whichever was requested (`loginTarget`).

### 5.4 Administrator — game & asset manager (behind login)

Two top-level tabs — **Gry** (Games) and **Dźwięki** (Sounds). Tabs hide while the game editor is open (the editor has its own "← Wróć" back action).

**Gry tab** — list of games. Each row: colored status dot, name, meta line (mode + created date [+ last-played, + final score if finished]), a status badge (**SZKIC**/draft, **LIVE**, **W TRAKCIE**/in-progress, **ZAKOŃCZONA**/finished, **ARCHIWALNA**/archived), and contextual actions — **Edytuj** (draft only), **Wznów** (resume — in-progress only, jumps straight to Prezenter), **Ustaw jako live** (any non-live game), and **Zagraj od nowa** (restart from the start — on in-progress and finished games; confirm-gated, resets play state but keeps the game's questions). **+ Nowa gra** opens a blank editor. Setting a game live **demotes the previously-live game to `in_progress`** (held, resumable) — **only one game can be live at a time**. Full state model in §6.1.

**Game editor** (from "+ Nowa gra" or "Edytuj") — a 4-column header: game name (input), mode toggle (Klasyczny/300 vs. Wolne rundy), a finale on/off toggle **rendered disabled with a "Wkrótce (V2)" hint** (finale is deferred — §4.5), and **sound-set picker** (Klasyczny / Retro / Filmowy) — **the only place a game's sound set is chosen**. Below: repeatable question blocks, each with a question-text input, "Usuń pytanie" (remove), and **up to 8 answer rows** (text + points + remove ×), plus "+ odpowiedź" and, below all questions, "+ Dodaj pytanie". Footer: "Zapisz grę" (save, green) / "Odrzuć zmiany" (discard).

These question/answer blocks are authored **directly on the game** — the editor writes `game_question_sets`/`game_questions`/`game_answers`, not the library. Saving is only permitted while the game is a `draft`; the server (`GameContent::saveGame`) rejects edits to any started game, which is how content is frozen (see §6, §6.1). The reusable library is an **optional** starting point, not a requirement: `GameContent::importLibrarySet` can seed a draft game's rounds by copying a saved library set's questions/answers in as new rounds, after which the copy is independent of the library. There is no "run this game straight from a library set" path — a game always plays from its own `game_*` content.

**Dźwięki tab** — real per-pack sound-file management (replaces the old non-functional cockpit selector). Sub-tabs pick the pack being edited (**Klasyczny / Retro / Filmowy** — same three names as the editor picker; **must stay in sync**). For the selected pack, one row per cue slot (Poprawna odpowiedź, Strike/Buzzer, Start rundy, Odkrycie odpowiedzi, Zegar finału, Koniec gry) showing either "Brak przypisanego pliku — odtwarzany będzie dźwięk domyślny" or the uploaded filename, with **▶ Odsłuchaj** (play) and **Usuń** (remove) once a file exists, plus a **Wybierz plik / Zamień plik** upload button (`<input type="file" accept="audio/*">`) always present. The real build needs **actual server-side storage** (upload + persisted path per pack+cue) so an assigned sound survives reload and is retrievable both by the automatic cue player and by Prezenter's manual overrides.

### 5.5 Grand Finale screens — V2 (not in V1)

Deferred to V2 and **not built or shown in V1**. Recorded here for when they are designed:

- **Grand Finale board** (contestant view) — 5 questions, one player at a time; a large **countdown timer** (15 s / 20 s) as the focal point, with obvious idle/running/paused/expired states; a running finale point total. Belongs to the **stage** world.
- **Grand Finale admin panel** (host) — per question, a field where the host types what the contestant said, plus the correct answers with points to check against manually; **timer controls Start / Pause·Resume / Reset** (Pause/Resume is first-class). Belongs to the **cockpit** world.

Both still need design work (the handoff never delivered them). Do not build them for V1.

---

## 6. Data model

Three groups of tables. Full DDL in `db/schema.sql` (InnoDB, utf8mb4). Architect owns schema changes; keep schema and this section in sync.

**Content library (reusable, freely editable):** `lib_question_sets`, `lib_questions`, `lib_answers` (text + points + sort_order). A game never reads the library at play time — its content lives in its own `game_*` tables (optionally seeded from the library via import, below), so editing the library never affects any game, started or not.

**Games + per-game content (frozen by status):** `games` (name, mode `classic_300`|`free_rounds`, grand_finale bool, sound_set_id, status, timestamps), `game_question_sets` (round_number, multiplier, name), `game_questions`, `game_answers` (text, points, sort_order, `revealed`). **Freeze-by-status decision (RESOLVED — see §10, change 9):** a game's questions/answers are authored **directly** in the `game_*` tables by the Administrator game editor while the game is a `draft`. There is **no library-copy step at start**; the content becomes read-only the moment the game leaves `draft` (i.e. once started). The "freeze" is enforced by **status, server-side**, not by a copy — `GameContent::saveGame`/`deleteGame`/`importLibrarySet` reject any game whose status is not `draft`. This keeps history truthful: a started game's content can never change. The reusable library stays useful through an **optional** "import from library" convenience (`GameContent::importLibrarySet`) that copies a library set's questions/answers into a *draft* game as new rounds; after import the game's copy is fully independent of the library.

**Teams:** `teams` — two rows per game (`blue`, `red`), running `score`, unique on (game_id, color).

**Live state (poll target):** `game_state` — exactly one row per game: `phase` (`lobby`|`round`|`steal`|`round_end`|`finale`|`finished`), `current_game_set_id`, `active_team`, `starting_team`, `strikes`, `steal_in_progress`, `round_pot` (raw sum; multiplier applied at finish), the finale timer anchors, `updated_at`. Revealed answers live on `game_answers.revealed` so the board renders the grid directly.

**Single live game:** `active_game` — one-row pointer (`id = 1`, nullable `game_id`). The board reads it to know which game to show. Setting a game live updates this pointer **and** demotes the previously-live game, in one transaction (see §10, change 5).

**History:** `round_results` — one row per completed round (game_set_id, round_number, team_color, points_awarded, multiplier_applied, created_at).

**Finale records:** `finale_answers` — (finale_player 1|2, question_index, typed_answer, matched_answer_id nullable, points_awarded).

**Sounds:** `sound_sets` (id, name) and `sounds` (sound_set_id, cue `correct`|`strike`|`round_start`|`reveal`|`finale_timer`|`end_game`, file_path). **Seed the three named packs** — Klasyczny, Retro, Filmowy — so the editor picker and the Dźwięki sub-tabs map onto real rows (see §10, change 4).

**Implementer notes:** all timestamps use the **server** clock, and `state.php` returns server `now`. Never trust client-supplied scores/reveal state. Index `game_state.game_id`, `game_answers.game_question_id`, `round_results.game_id` (schema already does).

### 6.1 Game lifecycle: statuses, hold/resume & restart

**Status meanings** (`games.status`):

| Status | Polish label | Meaning | Editable? | Live-able? |
|--------|--------------|---------|-----------|-----------|
| `draft` | SZKIC | Being created/edited; **never started**. Content authored directly on the game (`game_*` tables); mutable, not yet frozen. | Yes, fully | Yes → becomes `live`/`in_progress` on first start |
| `live` | LIVE | The one game currently pointed to by `active_game` — the board shows it. **Exactly one at a time.** Overlays an `in_progress` game (a just-started `draft` becomes live+in_progress). | No | It already is |
| `in_progress` | W TRAKCIE | **Started but not finished.** Frozen content exists, play state is in `game_state`. May be currently live, or **held** (not live) while another game is live. | No (frozen, read-only) | Yes → resume as live |
| `finished` | ZAKOŃCZONA | Completed. Read-only summary with final scores. | No | Not normally; can be **restarted** (below) |
| `archived` | ARCHIWALNA | Hidden from the main list. | No | No |
| `paused` | — | Optional internal marker for an explicitly paused game; treated like `in_progress` for hold/resume. Not surfaced as a distinct action in V1. | No | Yes |

**Starting a game (set a `draft` live).** The game already carries its own `game_*` content (authored in the editor while `draft`), so starting **copies nothing** — it simply **freezes that content by status** as the game leaves `draft`, after which the content is read-only. On start: create the two `teams` rows (score 0), initialise the `game_state` row, point `active_game` at it, and set status to `live` (it is now also conceptually `in_progress` — started and unfinished).

**Hold & resume (the core of Decision 5).** Only **one** game is `live` at a time. When the host sets a **different** game live:

- The previously-live game — which by definition had already started — is **demoted to `in_progress`** (held), **not** to `draft`. Its frozen content, scores, and `game_state` are preserved untouched.
- The newly-selected game becomes `live`. If it was a `draft`, it starts fresh (freeze-by-status + init as above); if it was `in_progress`, it simply **resumes** — its saved `game_state` re-renders the game exactly where it left off.
- All of this happens in **one transaction** (flip `active_game` + demote the old live game + promote the new one).

So a host can hold game A (→ in_progress), run game B, then later set game A live again and continue it. A `draft` is only ever "creation in progress"; a started game never falls back to `draft` via hold.

**Restart from the start.** Offered on **`in_progress`** and **`finished`** games (an explicit "Zagraj od nowa" / restart action, with a confirm — it is destructive to play state). Restart **keeps the frozen content** (`game_question_sets`/`game_questions`/`game_answers` rows stay, so the same game replays identically) and **resets all play state**, in one transaction:

- `teams.score` → 0 for both teams.
- `game_answers.revealed` → 0 for every answer.
- Delete this game's `round_results` (and any `finale_answers`).
- Reset `game_state` to the initial round: `phase = round` (or `lobby`), first `current_game_set_id`, `strikes = 0`, `steal_in_progress = 0`, `round_pot = 0`, finale anchors idle, `starting_team`/`active_team` cleared for a fresh team pick.
- Set status to **`in_progress`** (fresh, unfinished). If the restarted game happened to be the live one, it stays live and the board reflects the reset on its next poll.

**History note:** restart discards the *play* history (round_results) of that game instance because it is being replayed from scratch. If long-term audit of past playthroughs is ever needed, that is a V2 concern (e.g. snapshot round_results before wiping) — out of scope for V1.

---

## 7. Auth (new — required before public use)

The design added a **shared-password login gate** (Logowanie) protecting Prezenter and Administrator; **Plansza stays public**. This was only a "TODO" in the original deployment notes and is now a first-class feature.

- One shared host password, verified **server-side** via a **PHP session**. No user accounts, no username.
- Store the password as a **hash** (e.g. `password_hash`) in `config.php` (never commit it) — not hardcoded in JS. The design's client-side string comparison is a mock to be **replaced**.
- Endpoints needed: a login/verify endpoint that sets the session on success, a logout, and a session-check used to guard `admin/` routes. On an unauthenticated hit to Prezenter/Administrator, redirect to Logowanie, then continue to the originally requested screen after success. Once authenticated, switching between Prezenter and Administrator does **not** re-prompt.
- Keep the login screen's visual states (default, focused, submitting, error, session-expired). Never reveal whether a wrong password was "close."

> The schema/config do not yet carry the password field — add it to `config.php` (hashed) as the first auth task.

---

## 8. Sounds

HTML5 `<audio>` in the browser. A configurable **sound set per game**, chosen in the Administrator game editor. Three packs ship (seeded in `db/schema.sql`): **Klasyczny / Retro / Filmowy**. Cues: correct answer (ding), strike/buzzer, round start, answer reveal, finale timer *(V2)*, end-of-game. If a cue has no uploaded file, the **default sound** at `assets/sounds/default/<cue>.wav` plays. Starter WAV cues ship for `default/` and the Klasyczny pack so the app makes sound out of the box (see `assets/sounds/README.md`); Retro and Filmowy start empty and fall back to default.

Cues fire on **state transitions** (e.g. play buzzer once when the strike count increases), **not** on every poll. Prezenter's manual cue grid re-triggers the same audio path for cases where an automatic cue was missed.

---

## 9. Deployment (cPanel shared hosting)

**Before committing to a host, verify:** PHP ≥ 8.1 (cPanel → Select PHP Version); frequent AJAX polling allowed (no `mod_security` rule blocking repeated same-URL requests — if it does, whitelist the endpoint or slow the poll to ~1.5 s); a MySQL database can be created.

**Install:** create the DB + user; import `db/schema.sql` (phpMyAdmin → Import); copy `config.example.php` → `config.php` and fill DB creds **and the hashed host password** (do not commit `config.php`); point the domain/subdomain docroot at `public/` — if you can't change docroot, put `public/`'s contents at the desired public path (e.g. an unlinked subfolder like `/familiada`) and keep `src/`, `db/`, `config.php` in a **separate, non-web-reachable location** (a sibling folder outside every site's docroot, not just an `.htaccess`-denied subfolder of one — see `docs/DEPLOYMENT.md`); upload sound files.

**Game day, two screens:** host opens `public/admin/` (e.g. `/familiada/admin`) on their laptop; the board opens `public/` root (e.g. `/familiada`) full-screen on the machine driving the TV/projector. Both reach the same server; the board follows whatever game is set "live".

**Security:** keep `config.php`, `src/`, `db/` out of the web root; the login gate (see §7) protects the cockpit. Serve over HTTPS.

---

## 10. Reconciliation & change log

Where the original `docs/` spec and the Claude Design handoff diverged, this is what was decided and why. **Design generally wins** (it is the newer, high-fidelity, final-intent artifact), except where a data-integrity concern favors the original architecture — flagged below.

**1 — No manual "pass turn."** *Original:* the cockpit had a "pass-to-other-team" button. *Design:* removed it; steal is triggered **only** by the 3rd strike. **Decision:** adopt the design — no pass button. Simpler and matches the show. (§4.3)

**2 — Team selector locks at 3 reveals.** *New in design:* the team selector locks (`teamSelectLocked`) once ≥3 answers are revealed in a question. *Original:* no such lock. **Decision:** adopt. The winning team decides who plays next, so late re-assignment is disallowed. (§4.3, §5.2)

**3 — Sound-set choice moved to the game editor.** *Original:* sound controls lived in the cockpit. *Design:* the game's sound set is chosen **once**, in the Administrator game editor; Prezenter only **displays** it (read-only) and offers manual per-cue override triggers. **Decision:** adopt the design. (§5.2, §5.4, §8)

**4 — Three fixed, named sound packs.** *Design:* Klasyczny / Retro / Filmowy, kept in sync between the editor picker and the Dźwięki sub-tabs, with real per-cue file upload/storage. *Original schema:* generic `sound_sets`. **Decision:** seed the three named packs into `sound_sets`; build real upload/list/delete endpoints backing `sounds.file_path`. Missing files fall back to a default sound. (§5.4, §6, §8)

**5 — "Set live" demotion target — RESOLVED.** *Original architecture/data-model:* setting a new game live flips the previously-live game to **`in_progress`**. *Design handoff:* said it demotes it to **`draft`**. **Decision (confirmed):** demote to **`in_progress`** — `draft` means "creation in progress" and a started game must never fall back to it. A held game stays resumable; the host can set it live again later and continue. Additionally, a **restart-from-start** action is offered on `in_progress` and `finished` games (resets play state, keeps questions). Full state model in §6.1. (§5.4, §6.1)

**6 — Polish UI, added login gate.** *Design:* the UI is Polish (Plansza / Prezenter / Logowanie / Administrator and Polish button copy), and a shared-password login gate was added protecting the cockpit (not the board). *Original:* English/neutral copy; auth was only a deployment TODO. **Decision:** adopt Polish UI copy (code/docs stay English) and treat auth as a required feature (§7).

**7 — Answer counts.** *Design:* the editor allows **up to 8** answers per question; the board/cockpit render **6** rows in the mock. **Decision:** author up to 8; the board renders however many the question has (commonly ≤6). No schema change needed.

**8 — `paused` status retained.** The design's status list doesn't surface `paused`, but the schema includes it and it is useful for resume. **Decision:** keep `draft`/`live`/`in_progress`/`paused`/`finished`/`archived` in the data model; surface Polish labels for the ones the UI shows.

**9 — Content freeze is by status, not by a library-copy at start — RESOLVED.** *Original data-model wording:* a game froze a **chosen library set** by *copying* it into the `game_*` tables when it *started*. *Design handoff / editor UI:* the game editor authors question/answer blocks **directly on the game**, with no "pick a library set" control. These read as contradictory. **Decision (confirmed, shipped in V1):** the game editor writes `game_*` content **directly** while the game is a `draft`; the "freeze" is enforced by **status** (content goes read-only the moment the game leaves `draft`), **server-side** — `GameContent::saveGame`/`deleteGame`/`importLibrarySet` reject non-`draft` games. Starting a game copies **nothing**. The reusable library stays meaningful via an **optional** import convenience (`GameContent::importLibrarySet`) that copies a library set into a draft game as new rounds. This keeps history truthful (a started game's content can never change) without contradicting the editor UI. Verified by the tester; no bug results. (§5.4, §6, §6.1)

### Resolutions & remaining build items

- **Decision A — Grand Finale deferred to V2. ✅ resolved.** The handoff never delivered the finale screens, so the feature is parked for V2: schema/rules retained, but the toggle is disabled, `grand_finale` forced to `0`, the `finale` phase never entered, and no finale screens ship in V1 (§4.5, §5.5).
- **Decision B — Auth storage. ✅ addressed.** `config.example.php` now carries `auth_password_hash` (bcrypt via `password_hash`) plus `session_lifetime`. Still to build: the session verify/logout endpoints and route guards; replace the design's mock client-side check (§7).
- **Decision C — "Set live" demotion. ✅ resolved** → demote to `in_progress`; added restart-from-start (§6.1, change 5).
- **Decision D — Sound storage. ✅ scaffolded.** `assets/sounds/` now has `default/` + three pack folders, with starter WAV cues for `default` and `klasyczny`, and the schema seeds the three `sound_sets` and the Klasyczny `sounds` rows. Still to build: the real per-pack/per-cue upload endpoint (persist path in `sounds`) and the default-fallback player; the design's `URL.createObjectURL` playback is a prototype stand-in (§5.4, §8).

---

*This document consolidates: `docs/REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/GAME_RULES.md`, `docs/DATA_MODEL.md`, `docs/WORKFLOW.md`, `docs/DEPLOYMENT.md`, `design/DESIGN_BRIEF.md`, `design/LOGIN_BRIEF.md`, and `design/design_handoff_familiada/README.md`. Those files remain valid as detailed appendices. Start here.*
