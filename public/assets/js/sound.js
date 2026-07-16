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

  function playCue(cue) {
    const url = urls[cue] || `/assets/sounds/default/${cue}.wav`;
    try {
      // A fresh Audio instance per trigger so overlapping cues (e.g. rapid reveals) don't cut each other off.
      const instance = new Audio(url);
      instance.play().catch(() => {
        /* Autoplay can be blocked until first user gesture — ignored, this is a live control room. */
      });
    } catch (e) {
      // Never let a missing/broken audio file break the game.
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

    if (next.phase === 'round' && prev.phase !== 'round' && prev.phase !== 'lobby') {
      // New round started (advanced from round_end).
      if (prev.round_number !== next.round_number) {
        playCue('round_start');
      }
    }

    const prevRevealed = (prev.answers || []).filter((a) => a.revealed).length;
    const nextRevealed = (next.answers || []).filter((a) => a.revealed).length;
    if (nextRevealed > prevRevealed && prev.question && next.question && prev.question.id === next.question.id) {
      playCue('reveal');
      playCue('correct');
    }

    if (next.strikes > prev.strikes) {
      playCue('strike');
    }

    if (next.phase === 'finished' && prev.phase !== 'finished') {
      playCue('end_game');
    }
  }

  return { setUrls, playCue, detectAndPlay };
})();
