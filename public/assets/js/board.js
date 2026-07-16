// Plansza — polls /api/state.php and re-renders. Public, read-only. Spec §3.1, §5.1.

(() => {
  const stage = document.getElementById('stage');
  const content = document.getElementById('content');
  const pollMs = parseInt(stage.dataset.pollMs, 10) || 1000;

  let prevState = null;
  let knownRevealedIds = new Set();

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  }

  function render(state) {
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
      content.innerHTML = `
        <div class="game-over-screen">
          <div>KONIEC GRY</div>
          <div class="winner ${winner ? 'team-' + winner : ''}">${esc(winnerLabel)}</div>
          <div class="round-total" style="margin-top:20px;">NIEBIESCY ${blue} · CZERWONI ${red}</div>
        </div>`;
      return;
    }

    if (state.phase === 'lobby' || !state.question) {
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
        <span class="txt">${revealed ? esc(a.text) : ''}</span>
        <span class="pts">${revealed ? a.points : ''}</span>
      </div>`;
    }).join('');

    const strikesFor = (team) => {
      const isStartingTeamSide = team === state.starting_team;
      const active = state.strikes;
      if (state.phase === 'steal' && !isStartingTeamSide) {
        // Non-active side during steal collapses to one big glyph (Spec §5.1).
        return `<div class="strikes-col"><div class="strike-x steal-collapsed">X</div></div>`;
      }
      let boxes = '';
      for (let i = 0; i < 3; i++) {
        const lit = isStartingTeamSide && i < active;
        boxes += `<div class="strike-x${lit ? ' lit' : ''}">X</div>`;
      }
      return `<div class="strikes-col">${boxes}</div>`;
    };

    const pulseTotal = prevState && prevState.round_pot !== state.round_pot;

    content.innerHTML = `
      <div class="question-banner">${esc(state.question.text)}</div>
      <div class="round-total${pulseTotal ? ' pulse' : ''}">SUMA ${state.round_pot}</div>
      <div class="board-row">
        <div class="score-box team-red${state.active_team === 'red' ? ' on-clock' : ''}">
          <div class="team-label"><span class="team-shape"></span>CZERWONI</div>
          <div class="score-value">${state.teams.red.score}</div>
        </div>
        ${strikesFor('red')}
        <div class="answer-panel">${rows}</div>
        ${strikesFor('blue')}
        <div class="score-box team-blue${state.active_team === 'blue' ? ' on-clock' : ''}">
          <div class="team-label"><span class="team-shape"></span>NIEBIESCY</div>
          <div class="score-value">${state.teams.blue.score}</div>
        </div>
      </div>
      ${state.phase === 'steal' ? '<div class="steal-banner">KRADZIEŻ</div>' : ''}
    `;

    knownRevealedIds = nowRevealedIds;
  }

  async function poll() {
    try {
      const state = await Api.getJson('api/state.php');
      SoundCues.detectAndPlay(prevState, state);
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
