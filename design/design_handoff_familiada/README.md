# Handoff: Familiada Web App (Board · Prezenter · Login · Admin)

## Overview
Polish-language "Family Feud" (Familiada) game app with four screens: a public
contestant scoreboard (**Plansza**), a host control panel (**Prezenter**), a
password gate in front of the host tools (**Logowanie**), and a game/asset
manager (**Administrator**). This package supersedes any earlier handoff notes
in this project — the flow has changed materially since (login gate added,
admin fleshed out, sound library redesigned). Treat this README as current.

## About the design files
`Familiada.dc.html` is a **design reference**, built as a single-file
prototype (streaming template + a small state/logic class), not production
code to lift verbatim. It has no real backend: no server, no persistence, no
actual authentication, no audio pipeline. The task is to **recreate this
design and its behavior in the target codebase's real stack** (React, Vue,
PHP + templating, etc., per what the project already uses), using that stack's
own component/state patterns — not to embed or transpile this file.

## Fidelity
**High-fidelity.** Colors, typography, spacing, layout, copy (Polish), and
interaction states are final-intent, not placeholders. Recreate pixel-close
using the values in "Design Tokens" below. The one deliberately-fake piece is
authentication (see Auth Flow) — that logic is illustrative only, meant to be
replaced by the real PHP session check.

## Screen inventory & flow

```
Plansza (public, no login) ──────────────────────────────────
Prezenter ──┐
            ├──(not authed)──► Logowanie ──(success)──► [target screen]
Administrator ┘                    │
                                (failure) ──► Logowanie (error state, retry)
```

- **Plansza** is public — no gate. It's the only screen meant for a projector/TV.
- **Prezenter** and **Administrator** both route through **Logowanie** first if
  the session isn't authenticated yet. Once authenticated in this session,
  switching between Prezenter and Administrator does not re-prompt.
- In the real app, `Plansza` likely lives on a public URL/route; `Prezenter`
  and `Administrator` on a route behind the PHP session check backing this
  gate.

### 1. Plansza — contestant board
Purpose: what the room/projector sees. Read-only, no controls, LED-matrix
game-show aesthetic (this screen is intentionally the "theatrical" outlier —
Prezenter/Administrator/Logowanie are all restrained "cockpit" style).

Layout: full-bleed dark stage. Center: question text, then a horizontal row of
[red score box] [strike glyphs ×3] [answer panel] [strike glyphs ×3] [blue
score box]. Two large decorative arcs glow in the top-left (red) and top-right
(blue) corners; background is a soft radial blend of red/blue toward the
center, dark at the edges.

Components:
- **Question banner** — centered, `Space Grotesk` 600, `clamp(22px,2.6vw,34px)`, off-white (`oklch(0.96 0.01 260)`).
- **Answer panel** — rounded card (`border-radius:26px`), scanline-textured dark background (repeating-linear-gradient), 6 dot-matrix answer rows: index number, answer text (blank until revealed), dotted leader line, point value. Revealed text/points glow amber (`oklch(0.78 0.17 85)`) with a "LED ignite" flicker-on keyframe; hidden rows show nothing (not blanked-out boxes — genuinely empty, per authentic scoreboard behavior).
- **Round total display** — small amber LED readout ("SUMA {n}") docked above the panel in a rounded chip, pulses on each reveal.
- **Score boxes** (left = red team, right = blue team) — dark LED-display boxes, amber digits, glow intensifies when that team is "on the clock."
- **Strike glyphs** — normally up to 3 small X's per side (dim until struck, ignite red with a shake/flash keyframe on strike). During **steal mode**, the non-active team's strikes collapse into one large dim X that only lights fully red the moment the steal fails.

Game rule state (for dev reference, not this screen's job to enforce — logic
lives in Prezenter):
- 3 strikes on the active team → steal mode: turn flips to the other team, strikes reset, one big steal glyph shown.
- Team selector is only usable while <3 answers are revealed in the current question; it locks after that (the winning team decides who plays next, no separate "pass" action).
- During steal: one strike = failed steal; revealing any answer = successful steal. Neither auto-advances score — host must press "ZAKOŃCZ RUNDĘ" (finish round) to bank points and load the next question.

### 2. Logowanie — host login gate
Purpose: the front door to Prezenter/Administrator. One shared host password,
no username. Belongs to the calm "cockpit" visual world — same font family,
neutral palette, small amber accent — never the board's red/blue theatrics.

Layout: centered card (max-width 380px) on a dark, subtly radial-gradient
background. Card: wordmark + "PANEL STEROWANIA" subtitle → optional
session-expired notice → password field with inline show/hide toggle → inline
error line (only on failure) → full-width primary button → footer disclaimer
line.

States:
- **Default/empty** — button visually present but effectively inert until text is typed (opacity fades when empty).
- **Focused/typing** — input gets a visible amber focus ring (`box-shadow: 0 0 0 3px oklch(0.78 0.17 85 / 0.18)`, border turns amber) — this is the keyboard-focus indicator, keep it as a real focus style in the recreation, not a click-triggered one.
- **Submitting** — button label becomes "Sprawdzanie…", ~70% opacity, disabled; ~700ms simulated delay in this mock stands in for the real request.
- **Error (wrong password)** — input border turns a muted amber/orange (`oklch(0.68 0.14 40)`), a calm inline line appears below the field ("Nieprawidłowe hasło. Spróbuj ponownie."). Password text is preserved, not cleared, so the host can just retry. No hint of how close the guess was.
- **Session-expired variant** — same screen, an extra dismissible-feeling info line above the field ("Sesja wygasła. Zaloguj się ponownie, aby wrócić do panelu."), styled as a quiet neutral notice (not red/error-colored).

Interaction rules: password input autofocuses on mount (see `componentDidUpdate`
refocus-on-view-change) and on session-expired; Enter key submits (`onKeyDown`
checks `e.key === 'Enter'`); primary button is large (44px+ tap target),
full-width, high-contrast amber.

Real-world wiring: this screen has zero real auth — `submitLogin()` compares
against a hardcoded string as a stand-in. The dev must replace this with a real
call to the PHP session endpoint, keep the same visual states (default,
focused, submitting, error, session-expired), and make sure the two entry
points (Prezenter nav button, Administrator nav button) both redirect through
this gate when the session is not authenticated, then continue to whichever
one was originally requested (`loginTarget` in this mock).

### 3. Prezenter — host cockpit
Purpose: the host's live-game control panel while running Familiada in front
of a room. Dense, functional, two-column grid (`1fr 340px`) under a fixed
56px header.

Header: wordmark, "PANEL STEROWANIA" label, round-number chip, multiplier chip
(×1/×2/×3), and a read-only line showing which sound set the *current game* is
configured to use (e.g. "ZESTAW DŹWIĘKÓW GRY · Klasyczny") — **this is
display-only now**; the host cannot change the game's sound set from here (see
change log — that control moved to Administrator's game editor).

Left column: current question header, then a scrollable list of 6 answer rows
— index, answer text, points, a revealed/hidden status pill, and a per-row
"ODKRYJ" (reveal) button (disabled once revealed, or once a steal has already
resolved).

Right column (sidebar): 
- Two score cards (NIEBIESCY/blue, CZERWONI/red) — highlights whichever team is currently on the clock.
- Team selector — two toggle buttons, disabled/dimmed once ≥3 answers are revealed this question (`teamSelectLocked`). During steal, a small amber "KRADZIEŻ AKTYWNA" (steal active) badge appears beneath it.
- Strikes — "BŁĘDY {n}/3" counter, 3 mini indicator squares, and a full-width "BŁĄD" (strike) button. During steal this button's label/behavior changes to register the single steal-ending strike.
- "ZAKOŃCZ RUNDĘ" (finish round) — green, primary, always available; banks the round's points to the correct team per the rules above and advances to the next question.
- "DŹWIĘKI · WYZWÓL RĘCZNIE" (sounds — manual trigger only) — a 2-column grid of 6 cue buttons (Poprawna odpowiedź / Strike·Buzzer / Start rundy / Odkrycie odpowiedzi / Zegar finału / Koniec gry). Each flashes amber briefly on click. **These are manual overrides for cues that should otherwise fire automatically** (e.g. re-cue a missed automatic trigger) — they are not how sound is normally invoked; see Administrator's Dźwięki tab for asset management. In this mock they're inert (no audio wired); the real build should have them call whatever audio-trigger the automatic events call.

### 4. Administrator — game & asset manager
Purpose: manage games (create/edit/list/set-live) and manage each sound
pack's audio files. Two top-level tabs: **Gry** (Games) and **Dźwięki**
(Sounds); tabs hide while the game editor is open (editor has its own "← Wróć"
back action instead).

**Gry tab** — list of games, each row: colored status dot, name, meta line
(mode + created date [+ last-played, + final score if finished]), a status
badge (SZKIC/draft, LIVE, W TRAKCIE/in-progress, ZAKOŃCZONA/finished,
ARCHIWALNA/archived), and contextual actions: "Edytuj" (draft only), "Wznów"
(resume — in-progress only, jumps straight to Prezenter), "Ustaw jako live"
(any non-live game; setting one live demotes whichever game was previously
live back to draft — only one game can be "live" at a time). "+ Nowa gra"
opens a blank editor.

**Game editor** (opened from "+ Nowa gra" or "Edytuj"): a 4-column header row
— game name (text input), mode toggle (Klasyczny/300pt vs. Wolne rundy/free
rounds), finale on/off toggle, and **sound set picker** (Klasyczny/Retro/
Filmowy) — this is the *only* place a game's sound set is chosen (moved off
Prezenter). Below: repeatable question blocks, each with a question-text
input, "Usuń pytanie" (remove), and up to 8 answer rows (text + points number
input + remove ×), plus "+ odpowiedź" (add answer) and, below all questions,
"+ Dodaj pytanie" (add question). Footer: "Zapisz grę" (save, primary green) /
"Odrzuć zmiany" (discard, secondary).

**Dźwięki tab** — real file-upload sound management, replacing the old
non-functional Prezenter sound-set selector. Sub-tabs let the admin pick which
pack they're editing (Klasyczny / Retro / Filmowy — same three names as the
game editor's picker, and must stay in sync with it). For the selected pack:
one row per cue slot (Poprawna odpowiedź, Strike/Buzzer, Start rundy,
Odkrycie odpowiedzi, Zegar finału, Koniec gry) showing either "Brak
przypisanego pliku — odtwarzany będzie dźwięk domyślny" (no file — default
sound plays) or the uploaded filename, with a "▶ Odsłuchaj" (play) and "Usuń"
(remove) action once a file exists, and a "Wybierz plik"/"Zamień plik" (choose/
replace file) upload button (`<input type="file" accept="audio/*">`) always
present.

In the mock, uploads are handled client-side with `URL.createObjectURL` and
played back with `new Audio(url).play()` — purely for prototype
demonstration. The real build needs actual file storage (server upload +
persisted URL/path per pack+cue) so the assigned sound survives reload and is
retrievable by whatever plays cues automatically during a live game, as well
as by Prezenter's manual override buttons.

## Interactions & behavior — summary of rules to preserve
- Steal only triggers via 3 strikes on the active team (no manual "pass turn").
- Team selector locks at 3 revealed answers per question.
- Steal resolves via either one strike (failed) or one reveal (success); round must be explicitly finished by the host to bank points/advance.
- Login gate blocks Prezenter and Administrator, not Plansza; a successful login remembers the session so switching between the two later doesn't re-prompt.
- Wrong password: never indicate closeness; keep typed text; simple retry.
- A game's sound set is chosen once, in its editor, in Administrator — Prezenter only displays it and offers manual per-cue trigger overrides. Sound *files* are managed per-pack in Administrator → Dźwięki, not per-game.
- Only one game may hold "live" status at a time.

## State management (reference — mirror this shape, not this exact code)
- `view`: `'board' | 'cockpit' | 'login' | 'admin'` — top-level screen switch.
- Game-in-progress state: `question`, `answers[]` (`text`, `points`, `revealed`), `strikes`, `currentTeam`, `isSteal`, `stealOutcome`, `scores{blue,red}`, `multiplier`, `qIndex`.
- Login: `authed` (session flag), `loginPw`, `loginShow` (show/hide), `loginError`, `loginSubmitting`, `loginTarget` (which screen to continue to on success).
- Admin: `adminTab` (`'games'|'editor'|'sounds'`), `games[]` (id, name, mode, finale, soundSet, status, created, lastPlayed, scores, questions), `editingGameId`, `editorDraft` (mirrors a game's editable shape), `soundLibrary` (keyed by pack name → array of `{key, label, fileName, url}`), `adminSoundSetView` (which pack tab is open).
- Data fetching: none in the mock (all in-memory mock arrays). Real build needs: session check endpoint, games CRUD endpoint, per-pack sound-file upload/list/delete endpoints, and whatever mechanism drives Plansza + Prezenter's live shared game state (likely polling or websockets if Plansza and Prezenter run on separate devices/tabs, which the earlier open question about separate URLs implied).

## Design tokens

**Typography**
- Display/numeric/UI-label font: `Orbitron` (600/700/800) — wordmark, chips, LED-adjacent numerics.
- Body/UI font: `Space Grotesk` (400–700) — everything else (buttons, labels, body text).
- Monospace/LED font: `Share Tech Mono` — all LED-style readouts (answers, points, score boxes, login password field).

**Colors** (OKLCH)
- Background base (cockpit screens): `oklch(0.13 0.02 260)` page / `oklch(0.17 0.02 260)` header bars / `oklch(0.15–0.16 0.02 260)` panels.
- Board background: radial blend `oklch(0.16 0.05 300)` (center) → `oklch(0.1 0.03 275)` → `oklch(0.06 0.01 260)` (edge), plus red/blue corner glows.
- Amber accent (primary action / LED glow): `oklch(0.78 0.17 85)`.
- Blue team: `oklch(0.62 0.19 250)`. Red team: `oklch(0.60 0.22 18)`.
- Success/green (finish round, live badge, save): `oklch(0.68 0.17 145)`.
- Error/warning (login error border+text): `oklch(0.68 0.14 40)` border / `oklch(0.72 0.12 40)` text.
- Neutral text: `oklch(0.94 0.01 260)` primary / `oklch(0.55–0.6 0.02 260)` secondary/labels.
- Borders: `oklch(0.26–0.34 0.03 260)` throughout cockpit screens.

**Radius/spacing**
- Cards/panels: 8–14px radius. Board answer panel: 26px. Pills/badges: 999px (full).
- Buttons: 6–9px radius, 8–14px vertical padding, 12–18px horizontal.
- Cockpit sidebar padding: 16–22px. Admin content padding: 28px 40px.

**Motion**
- `ledIgnite`/`ledIgniteRed` — brightness-flash-in keyframe on reveal/strike, ~0.5–0.6s.
- `glowPulse` — text-shadow pulse on round-total update.
- `stealBlink` — steal banner opacity pulse, 1.2s loop.
- `cueFlash` — box-shadow ring expand-fade on manual cue trigger, 0.4s.
- All animations are gated behind a `reducedMotion` flag — recreate with `prefers-reduced-motion` support.

## Assets
No external image/icon assets — everything is CSS-drawn (LED glyphs, arcs,
score boxes). Fonts loaded from Google Fonts (Orbitron, Share Tech Mono,
Space Grotesk). No brand/logo files to migrate.

## Files
- `Familiada.dc.html` — the full design reference (all four screens, all states, mock data and interaction logic).
- `support.js` — internal runtime harness the prototype tool needs to render `Familiada.dc.html` in a browser; not something to port into the production codebase.
