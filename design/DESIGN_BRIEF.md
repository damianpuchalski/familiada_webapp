# Design Brief — Familiada (Family Feud) Web App

A prompt / starting point for Claude Design to build the **views** (front-end only). Back-end is PHP + MySQL with ~1s AJAX polling; you are designing the screens, not the server. Where you need live data, mock it with plausible placeholder state.

---

## What this is

A browser-based version of the Polish TV game show **Familiada** (Family Feud). Two teams — **blue** and **red** — compete to guess the most popular survey answers. One operator (the **admin/host**) drives the whole game from a control panel; the players and the room watch a separate **big-screen board**. Everything the players see is a reaction to what the admin clicks.

The product is **two very different screens sharing one game state**, plus a set of **admin management/design screens**. Design all of them, but treat them as two distinct visual worlds (see below).

## Who uses it

- **The admin/host** — one person, sitting at a laptop, running a live game in front of a room. Under mild pressure, glancing between the room and the screen. Needs speed, big unambiguous controls, and zero hunting for the next action.
- **The room / contestants** — watching a shared display (often a TV or projector). They read the board from across a room. Legibility and drama matter; interaction does not — they never touch it.

## The two visual worlds

**These should not look like the same app in two colors. They are two moods.**

1. **Contestant Board (the stage).** This is the show. Big, theatrical, readable at distance, built for reveal moments. The classic Familiada board is the anchor reference: a grid of answer slots that start hidden and flip/light up to reveal answer text + points when the host reveals them, a central round-points total, and three big **strike (X)** indicators. This screen is mostly ambient until something happens, then it's a moment — a slot lighting up, the total ticking up, an X slamming in. Lean into that. This is where you spend your boldness.

2. **Admin Cockpit (the control room).** Dense, functional, calm, fast. The host sees *everything at once*: the full answer list **with points and which are already revealed**, the strike controls, whose turn it is, both team scores, the round multiplier, and the round-flow buttons. Nothing here should be theatrical — it should be a confident, quiet instrument panel. Information density is a feature. This is the restraint half of the design.

The two share a game-state vocabulary (teams, strikes, round, points) so they should feel *related* — same type family for data, same team colors — but the cockpit is the mixing desk and the board is the stage.

## Screens to design

### 1. Contestant Board (live game view)
- Grid of answer slots for the current question. Hidden state vs. revealed state (revealed shows answer text + point value). Design both states and the transition between them.
- Central **round points** total, prominent, animates when it changes.
- **Strike display**: up to 3 big X's. Design the moment an X appears.
- Current-team indicator (blue vs red) — whose turn it is right now, including the "steal" moment when control passes to the other team.
- The **question text** itself.
- Must read from across a room. Assume it's on a TV.

### 2. Grand Finale Board (contestant view, finale phase)
- 5 questions, one player at a time. A large **countdown timer** (15s for player 1, 20s for player 2) that is the focal point.
- Timer has states: idle / running / **paused** / expired. The paused state must be visually obvious from across the room (the host can pause the clock).
- Shows the running finale point total.

### 3. Admin Cockpit (live control panel)
- The current question + the **full answer list with points**, clearly marking which answers are already revealed vs. still hidden. One click reveals an answer to the board.
- **Strike button** (marks an answer as not on the board) and a visible strike counter for the round.
- **Team selector** — which team (blue/red) the current answers are being credited to.
- Turn/steal state readout: whose turn, whether we're in the one-shot "steal" attempt.
- Round **multiplier** display (×1 / ×2 / ×3).
- Both teams' **running totals**.
- Round-flow controls: reveal, strike, pass-to-other-team, **finish round** (adds the pot to the owning team).
- **Sound controls** — trigger/enable cues: correct answer, strike/buzzer, round start, reveal, finale timer, end-game. Show which sound set is active.

### 4. Grand Finale Admin panel
- Per question: a field where the host types what the contestant said, and the correct answers with points to check against manually.
- **Timer controls: Start, Pause/Resume, Reset.** Pause/Resume is a first-class button.

### 5. Game Design / Editor (admin, content authoring)
- Create/edit a **game**: name, mode, grand-finale on/off, sound set.
- Two game modes to configure:
  - **Classic (300):** rounds 1–3 ×1, round 4 ×2, round 5 ×3; game ends when a team reaches 300.
  - **Free rounds:** every round ×1, arbitrary number of rounds, highest score wins.
- Manage **question sets → questions → answers with points**. This is a lot of nested list editing — design it to stay clean as it grows.

### 6. Games List / History (admin, lifecycle)
- List of all designed games with **status**: draft / live / in progress / finished / archived.
- Exactly **one game can be "live"** at a time — make the live one unmistakable, and make "set as live" a clear action.
- Per game: name, mode, created/last-played dates, final scores if finished.
- Actions by status: draft → edit/start; in progress → **resume**; finished → read-only summary.

## Interaction & feel notes
- The board updates ~1s after the admin acts (polling). Design reveal/strike/score changes as **satisfying transitions**, not instant swaps — the delay is covered by the animation being the payoff.
- Admin controls should be large and hard to misclick; the host is not looking closely.
- Sounds are part of the experience — design the affordances for triggering and configuring them, even though you're not producing audio.
- Respect reduced-motion; keep keyboard focus visible in the cockpit (the host may use keys for speed).

## What I'm NOT prescribing (your call as designer)
- **The entire visual identity.** Palette, typefaces, the character of the board, the texture of the cockpit — all yours. The classic Familiada board is a *reference for structure and drama*, not a palette to copy; don't feel bound to TV-graphic clichés. Find a point of view.
- The one constraint: **blue vs. red team colors must stay clearly distinct and consistent** across both worlds, and must remain distinguishable for color-blind viewers (pair color with shape/label/position, don't rely on hue alone).
- The signature moment. Pick one thing this app is remembered by — most likely something on the board reveal — and make it land.

## Deliverable
Start with the **two hero screens**: the live **Contestant Board** and the live **Admin Cockpit**, since they define both visual worlds and carry the most state. Get those right, then extend the identity to the finale, editor, and history screens. Mock all live data with believable placeholder content (real-sounding Familiada-style questions and answers, plausible scores).
