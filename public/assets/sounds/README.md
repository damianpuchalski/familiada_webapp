# Sound assets

Cue sounds for the game, organised by **sound pack**. A game's pack is chosen in the
Administrator game editor; cues fire automatically on state transitions (and can be
re-triggered manually from Prezenter).

## Layout

Lives under **`public/assets/sounds/`** — i.e. inside the web root — because the
board and Prezenter play these back as plain `<audio src>` URLs. Anything outside
`public/` is unreachable over HTTP once the docroot points at `public/` (Spec §9).
Configurable via `config.php`'s `sounds_path` (disk dir) / `sounds_url_base` (URL
prefix, default `/assets/sounds`, always emitted as an absolute path so it resolves
the same from the board root (`/`) and `/admin/`).

```
public/assets/sounds/
├─ default/     ← fallback cues, always present. Played when a pack has no file for a cue.
├─ klasyczny/   ← "Klasyczny" pack — seeded with starter sounds (same as default).
├─ retro/       ← "Retro" pack — empty; falls back to default until files are uploaded.
└─ modern/      ← "Modern" pack — seeded with starter sounds (same as default).
```

## Cues (six per pack)

| File (`<cue>.wav`) | `sounds.cue` | When it plays |
|---|---|---|
| `correct.wav`      | `correct`      | An answer is revealed correctly (ding). |
| `strike.wav`       | `strike`       | A strike / wrong answer (buzzer). |
| `round_start.wav`  | `round_start`  | A new round begins (fanfare). |
| `round_end.wav`    | `round_end`    | A round closes / points are banked. |
| `game_start.wav`   | `game_start`   | The game leaves the lobby for the first round. |
| `end_game.wav`     | `end_game`     | Game over (closing chord). |

## Notes

- The **starter sounds are CSS-free synthesized WAVs** included so the app makes sound out of
  the box. Replace them with production audio any time via Administrator → Dźwięki.
- Uploaded files are stored here (`sounds_path`) and the path per pack + cue is persisted in
  the `sounds` table, relative to `sounds_path` (e.g. `klasyczny/correct.wav`). A cue with no
  row / missing file falls back to `default/<cue>.wav`.
- Keep the three pack folder names (`klasyczny`, `retro`, `modern`) in sync with the
  `sound_sets.name` rows and the editor's picker.
