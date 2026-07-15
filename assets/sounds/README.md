# Sound assets

Cue sounds for the game, organised by **sound pack**. A game's pack is chosen in the
Administrator game editor; cues fire automatically on state transitions (and can be
re-triggered manually from Prezenter).

## Layout

```
assets/sounds/
├─ default/     ← fallback cues, always present. Played when a pack has no file for a cue.
├─ klasyczny/   ← "Klasyczny" pack — seeded with starter sounds (same as default).
├─ retro/       ← "Retro" pack — empty; falls back to default until files are uploaded.
└─ filmowy/     ← "Filmowy" pack — empty; falls back to default until files are uploaded.
```

## Cues (six per pack)

| File (`<cue>.wav`) | `sounds.cue` | When it plays |
|---|---|---|
| `correct.wav`      | `correct`      | An answer is revealed correctly (ding). |
| `strike.wav`       | `strike`       | A strike / wrong answer (buzzer). |
| `round_start.wav`  | `round_start`  | A new round begins (fanfare). |
| `reveal.wav`       | `reveal`       | Answer slot lights up on the board (blip). |
| `finale_timer.wav` | `finale_timer` | Grand-finale countdown beep. **(V2 — finale disabled in V1.)** |
| `end_game.wav`     | `end_game`     | Game over (closing chord). |

## Notes

- The **starter sounds are CSS-free synthesized WAVs** included so the app makes sound out of
  the box. Replace them with production audio any time via Administrator → Dźwięki.
- The real build stores uploaded files here (or a configured uploads path) and persists the
  path per pack + cue in the `sounds` table. A cue with no row / missing file falls back to
  `default/<cue>.wav`.
- Keep the three pack folder names (`klasyczny`, `retro`, `filmowy`) in sync with the
  `sound_sets.name` rows and the editor's picker.
