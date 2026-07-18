// Cue playback. WHAT to play is decided server-side: each action stamps a cue +
// monotonic cue_seq onto game_state (Spec §8), and board.js/cockpit.js call
// playCue() when they see cue_seq advance. This module just plays a named cue —
// it does not diff state or decide which cue fires. The server resolves each cue
// to an absolute /assets/sounds/... URL (falling back to /assets/sounds/default/
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

  return { setUrls, playCue, unlock };
})();
