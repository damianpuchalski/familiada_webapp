// Plansza — polls /api/state.php and re-renders. Public, read-only. Spec §3.1, §5.1.

(() => {
  const stage = document.getElementById('stage');
  const content = document.getElementById('content');
  const gameName = document.getElementById('boardGameName');
  const pollMs = parseInt(stage.dataset.pollMs, 10) || 1000;

  function renderGameName(state) {
    const name = state.game && state.game.name ? state.game.name : '';
    gameName.textContent = name;
    gameName.hidden = name === '';
  }

  // The board is a passive, never-clicked display — browsers block Audio().play()
  // here until a real user gesture happens on this tab. One click on this overlay
  // (played through a real cue, muted, so it counts as genuine audio playback)
  // unlocks it for the rest of the session; poll-triggered cues play normally after.
  const soundUnlockOverlay = document.getElementById('soundUnlockOverlay');
  const soundUnlockBtn = document.getElementById('soundUnlockBtn');
  if (soundUnlockBtn) {
    soundUnlockBtn.addEventListener('click', () => {
      SoundCues.unlock();
      soundUnlockOverlay.hidden = true;
    });
  }

  let prevState = null;
  let knownRevealedIds = new Set();
  // Last server cue_seq we've played. null until the first poll so a board that
  // joins mid-game never replays the cue that's already on the wire.
  let lastCueSeq = null;

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  }

  function render(state) {
    renderGameName(state);

    if (!state.game) {
      content.innerHTML = '<p class="lobby-message">Oczekiwanie na start gry…</p>';
      return;
    }

    if (state.phase === 'finished') {
      const blue = state.teams.blue.score;
      const red = state.teams.red.score;
      // Server is authoritative on the winner (GameActions::getBoardState, Spec §4.4) —
      // never re-derive it client-side.
      const winner = state.winner;
      const winnerLabel = winner === 'blue' ? 'NIEBIESCY' : (winner === 'red' ? 'CZERWONI' : 'REMIS');
      const winnerText = winner ? `Wygrywają: ${winnerLabel}` : winnerLabel;
      content.innerHTML = `
        <div class="game-over-screen">
          <div>KONIEC GRY</div>
          <div class="winner ${winner ? 'team-' + winner : ''}">${esc(winnerText)}</div>
          <div class="final-score">NIEBIESCY ${blue} · CZERWONI ${red}</div>
        </div>`;
      return;
    }

    if (state.phase === 'lobby') {
      content.innerHTML = '<p class="lobby-message">Oczekiwanie na rozpoczęcie gry</p>';
      return;
    }

    if (!state.question) {
      content.innerHTML = '<p class="lobby-message">Przygotowanie pytania…</p>';
      return;
    }

    const answers = state.answers || [];
    const nowRevealedIds = new Set(answers.filter((a) => a.revealed).map((a) => a.id));
    const rows = answers.map((a, i) => {
      const revealed = a.revealed;
      const justRevealed = revealed && !knownRevealedIds.has(a.id);
      const cls = ['answer-line'];
      if (revealed) cls.push('revealed');
      if (justRevealed) cls.push('just-revealed');
      return `<div class="${cls.join(' ')}">
        <span class="idx">${i + 1}</span>
        <span class="txt">${revealed ? esc((a.text || '').toUpperCase()) : ''}</span>
        <span class="dots"></span>
        <span class="pts">${revealed ? a.points : ''}</span>
      </div>`;
    }).join('');

    // A strike is a CSS-drawn X (two crossed bars); it's invisible until lit.
    const glyph = (extraCls) => `<div class="strike-x${extraCls ? ' ' + extraCls : ''}"><span class="bar a"></span><span class="bar b"></span></div>`;

    const strikesFor = (team) => {
      // Steal: the stealing team (active_team during steal) shows ONE big X,
      // dim until the steal fails, when it ignites full red. The team that
      // struck out (starting_team) keeps its three lit X's on display — the
      // three strikes that triggered the steal stay visible, not hidden.
      if (state.phase === 'steal') {
        if (team === state.active_team) {
          const failed = state.steal_result === 'failed';
          const justFailed = failed && !(prevState && prevState.steal_result === 'failed');
          const cls = 'big' + (failed ? ' lit' : '') + (justFailed ? ' just-struck' : '');
          return `<div class="strikes-col">${glyph(cls)}</div>`;
        }
        return `<div class="strikes-col">${glyph('lit') + glyph('lit') + glyph('lit')}</div>`;
      }
      // Normal round: strikes belong to the starting team's side.
      const isStartingTeamSide = team === state.starting_team;
      const active = state.strikes;
      const prevActive = (prevState && prevState.phase !== 'steal' && prevState.starting_team === state.starting_team)
        ? prevState.strikes : 0;
      let boxes = '';
      for (let i = 0; i < 3; i++) {
        const lit = isStartingTeamSide && i < active;
        const justStruck = lit && i >= prevActive; // newly lit this poll → ignite once
        boxes += glyph((lit ? 'lit' : '') + (justStruck ? ' just-struck' : ''));
      }
      return `<div class="strikes-col">${boxes}</div>`;
    };

    const pulseTotal = prevState && prevState.round_pot !== state.round_pot;
    // Before the presenter reveals it, show only the round number — the answer
    // count/slots and scores stay visible so contestants see the board shape.
    const questionBanner = state.question_revealed ? state.question.text : `Pytanie ${state.round_number ?? '-'}`;

    const stealSide = state.phase === 'steal' ? state.active_team : null;
    const stealLabel = stealSide === 'blue' ? 'NIEBIESCY' : 'CZERWONI';

    content.innerHTML = `
      <div class="question-banner">${esc(questionBanner)}</div>
      ${stealSide ? `<div class="steal-banner team-${stealSide}">KRADZIEŻ — ${stealLabel}</div>` : ''}
      <div class="board-row">
        <div class="score-box team-red${state.active_team === 'red' ? ' on-clock' : ''}">
          <div class="score-value">${state.teams.red.score}</div>
        </div>
        ${strikesFor('red')}
        <div class="answer-panel-wrap">
          <div class="round-total-chip${pulseTotal ? ' pulse' : ''}">${state.round_pot}</div>
          <div class="answer-panel">
            ${rows}
            <div class="panel-sum">SUMA ${state.round_pot}</div>
          </div>
        </div>
        ${strikesFor('blue')}
        <div class="score-box team-blue${state.active_team === 'blue' ? ' on-clock' : ''}">
          <div class="score-value">${state.teams.blue.score}</div>
        </div>
      </div>
    `;

    knownRevealedIds = nowRevealedIds;
  }

  async function poll() {
    try {
      const state = await Api.getJson('api/state.php');
      SoundCues.setUrls(state.sound_urls);

      // Server-authoritative cues: play `cue` when cue_seq advances past the last
      // one we played. We always resync lastCueSeq to the server's value, so a
      // non-increase (game restart / switching to another game, which start their
      // seq lower) self-corrects with no spurious playback — only a strict
      // increase fires a sound.
      const advanced = lastCueSeq !== null && state.cue && state.cue_seq > lastCueSeq;

      // game_start / round_start must be *heard before* the board visually reveals
      // the new frame (the cue leads the reveal, not trails it). Gate the render on
      // the board's own audio finishing — but only when the cue coincides with the
      // matching phase transition, so a manually-triggered game_start mid-round
      // just plays without freezing the board.
      const gate = advanced && (
        (state.cue === 'game_start' && prevState && prevState.phase === 'lobby' && state.phase === 'round') ||
        (state.cue === 'round_start' && prevState && prevState.phase === 'round_end' && state.phase === 'round')
      );

      lastCueSeq = state.cue_seq;

      if (gate) {
        // Keep the previous frame on screen until the cue has actually finished.
        await SoundCues.playCue(state.cue);
      } else if (advanced) {
        SoundCues.playCue(state.cue); // fire-and-forget
      }

      render(state);
      prevState = state;
    } catch (e) {
      // Transient network hiccup — keep the last good frame, try again next tick.
    } finally {
      setTimeout(poll, pollMs);
    }
  }

  poll();
})();
