// Cue playback. Cues fire on state transitions (detected by diffing consecutive
// poll snapshots), not on every poll — Spec §8. Prezenter's manual override
// grid calls the same playCue() path. The server already resolves each cue to
// an absolute /assets/sounds/... URL (falling back to /assets/sounds/default/
// <cue>.wav) in state.php's sound_urls — this module never guesses a relative
// path itself, since that breaks depending on page nesting (/board/ vs /admin/).

const SoundCues = (() => {
  let urls = {};

  function setUrls(newUrls) {
    urls = newUrls || {};
  }

  /**
   * Plays a cue and returns a Promise that resolves once playback finishes (or
   * immediately if it errors/is blocked) — callers that need to sequence the
   * next step after a sound finishes (e.g. cockpit.js's START GAME / next-round
   * flow) await this. Callers that just want fire-and-forget can ignore the
   * returned promise entirely.
   */
  function playCue(cue) {
    const url = urls[cue] || `/assets/sounds/default/${cue}.wav`;
    return new Promise((resolve) => {
      try {
        // A fresh Audio instance per trigger so overlapping cues (e.g. rapid reveals) don't cut each other off.
        const instance = new Audio(url);
        let settled = false;
        let safetyTimer = null;
        const done = () => {
          if (settled) return;
          settled = true;
          clearTimeout(safetyTimer);
          resolve();
        };
        const arm = (ms) => {
          clearTimeout(safetyTimer);
          safetyTimer = setTimeout(done, ms);
        };
        instance.addEventListener('ended', done, { once: true });
        instance.addEventListener('error', done, { once: true });
        // Some cues (a full musical sting, not a short beep) genuinely run 20+
        // seconds — a fixed guess here previously cut the wait short and fired
        // the next step (and the board's own copy of the sound) mid-playback.
        // Re-arm the safety net once we know the real length, with headroom for
        // network/decode stalls; keep a generous flat guess until then.
        instance.addEventListener('loadedmetadata', () => {
          if (isFinite(instance.duration) && instance.duration > 0) {
            arm(instance.duration * 1000 + 4000);
          }
        }, { once: true });
        instance.play().catch(done);
        arm(20000);
      } catch (e) {
        // Never let a missing/broken audio file break the game.
        resolve();
      }
    });
  }

  // A tiny embedded silent WAV — used purely to register one real audio-playback
  // call inside a genuine click handler, so the browser grants this tab lasting
  // permission to play audio later without a gesture (needed on the board, which
  // is a passive display that never otherwise gets clicked). See board.js.
  const SILENT_WAV = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=';

  function unlock() {
    try {
      new Audio(SILENT_WAV).play().catch(() => {});
    } catch (e) {
      // ignore
    }
  }

  /**
   * Compares previous and next board-state snapshots and fires the right cues
   * exactly once per transition. Call this after every successful poll.
   */
  function detectAndPlay(prev, next) {
    if (!next) return;
    setUrls(next.sound_urls);
    if (!prev) return;

    if (next.phase === 'round' && prev.phase === 'lobby') {
      // Game left the lobby for the first round.
      playCue('game_start');
    } else if (next.phase === 'round' && prev.phase !== 'round') {
      // New round started (advanced from round_end).
      if (prev.round_number !== next.round_number) {
        playCue('round_start');
      }
    }

    if (next.phase === 'round_end' && prev.phase !== 'round_end') {
      // Round closed / points banked.
      playCue('round_end');
    }

    const prevRevealed = (prev.answers || []).filter((a) => a.revealed).length;
    const nextRevealed = (next.answers || []).filter((a) => a.revealed).length;
    if (nextRevealed > prevRevealed && prev.question && next.question && prev.question.id === next.question.id) {
      playCue('correct');
    }

    if (next.strikes > prev.strikes) {
      playCue('strike');
    }

    if (next.steal_result === 'failed' && prev.steal_result !== 'failed') {
      // A resolved-but-not-yet-banked failed steal (Spec: manual finish flow) —
      // the strikes counter itself doesn't move here, so this is the only signal.
      playCue('strike');
    }

    if (next.phase === 'finished' && prev.phase !== 'finished') {
      playCue('end_game');
    }
  }

  return { setUrls, playCue, detectAndPlay, unlock };
})();
