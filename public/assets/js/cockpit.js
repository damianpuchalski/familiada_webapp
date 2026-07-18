// Prezenter — host cockpit. Spec §5.2. Dense two-column grid; every action is
// a validated POST to /api/action.php — the client never computes scores.

(() => {
  const root = document.getElementById('prezenter-root');
  const pollMs = parseInt(root.dataset.pollMs, 10) || 1000;
  const headerRoundChip = document.getElementById('headerRoundChip');
  const headerMultiplierChip = document.getElementById('headerMultiplierChip');
  const headerSoundLine = document.getElementById('headerSoundLine');

  let prevState = null;
  let gameId = null;
  let busy = false; // guards against double-submits between polls

  const CUE_LABELS = {
    correct: 'Odp - Popr',
    strike: 'Odp - Błąd',
    round_start: 'Runda - Start',
    round_end: 'Runda - Koniec',
    game_start: 'Gra - Start',
    end_game: 'Gra - Koniec',
  };

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  }

  // skipDetect: true for actions whose transition cue was already played manually
  // by the caller (see beginGame()/goNextRound()) — avoids a duplicate playback
  // on this device from detectAndPlay() re-noticing the same phase change.
  async function callAction(action, extra, skipDetect) {
    if (busy) return;
    busy = true;
    try {
      const state = await Api.postJson('../api/action.php', { action, game_id: gameId, ...extra });
      if (!skipDetect) SoundCues.detectAndPlay(prevState, state);
      renderState(state);
      prevState = state;
      return state;
    } catch (e) {
      // Surface a small inline notice rather than an alert() that blocks the host mid-game.
      const banner = document.createElement('div');
      banner.textContent = e.message || 'Akcja nieudana.';
      banner.style.cssText = 'position:fixed;top:64px;right:16px;background:#5a1f1f;color:#fff;padding:10px 16px;border-radius:8px;z-index:50;';
      document.body.appendChild(banner);
      setTimeout(() => banner.remove(), 3500);
    } finally {
      busy = false;
    }
  }

  function renderNoGame() {
    root.innerHTML = `<p style="padding:24px;color:var(--cockpit-text-muted);">
      Brak aktywnej gry. Ustaw grę jako "live" w <a href="administrator.php" style="color:var(--color-amber);">Administratorze</a>.
    </p>`;
    headerRoundChip.hidden = true;
    headerMultiplierChip.hidden = true;
    headerSoundLine.textContent = '';
  }

  function renderState(state) {
    if (!state.game) {
      renderNoGame();
      return;
    }
    gameId = state.game.id;

    headerRoundChip.hidden = state.round_number === null;
    headerRoundChip.textContent = `RUNDA ${state.round_number ?? '-'}`;
    headerMultiplierChip.hidden = false;
    headerMultiplierChip.textContent = `×${state.multiplier}`;
    headerSoundLine.textContent = `ZESTAW DŹWIĘKÓW GRY · ${state.game.sound_set_name || 'brak'}`;

    if (state.phase === 'finished') {
      const blue = state.teams.blue.score;
      const red = state.teams.red.score;
      // Server is authoritative on the winner (GameActions::getBoardState, Spec §4.4).
      const winnerLabel = state.winner === 'blue' ? 'NIEBIESCY' : (state.winner === 'red' ? 'CZERWONI' : 'Remis');
      root.innerHTML = `<div class="prezenter-grid">
        <div class="game-over-banner">KONIEC GRY — Wygrywają: ${esc(winnerLabel)}<br>
        <span style="font-size:14px;">Niebiescy ${blue} · Czerwoni ${red}</span></div>
      </div>`;
      return;
    }

    if (state.phase === 'lobby') {
      root.innerHTML = `<div class="prezenter-grid">
        <div class="panel gate-panel">
          <h2>Gra gotowa</h2>
          <p>Gra jest ustawiona jako live. Kliknij, aby rozpocząć — zabrzmi dźwięk startu gry.</p>
          <button class="btn btn-primary" id="startGameBtn">START GRY</button>
        </div>
      </div>`;
      document.getElementById('startGameBtn').addEventListener('click', beginGame);
      return;
    }

    const answers = state.answers || [];
    const locked = state.team_select_locked;
    const questionPending = state.phase === 'round' && !state.question_revealed;
    // Once a steal attempt has resolved (success or failure), nothing more can be
    // revealed/struck this round — the presenter just clicks ZAKOŃCZ RUNDĘ to bank it.
    const stealResolved = state.phase === 'steal' && state.steal_result !== 'none';
    const canReveal = (state.phase === 'round' || state.phase === 'steal' || state.phase === 'round_end') && !questionPending && !stealResolved;
    const canPlay = (state.phase === 'round' || state.phase === 'steal') && !questionPending && !stealResolved;
    const canFinish = (state.phase === 'round' && !questionPending) || (state.phase === 'steal' && stealResolved);

    const answerRows = answers.map((a, i) => `
      <div class="answer-row">
        <span class="answer-index">${i + 1}</span>
        <span>${esc(a.text)}</span>
        <span class="answer-points">${a.points}</span>
        <span class="status-pill${a.revealed ? ' revealed' : ''}">${a.revealed ? 'ODKRYTA' : 'UKRYTA'}</span>
        <button class="btn-reveal" data-answer-id="${a.id}" ${a.revealed || !canReveal ? 'disabled' : ''}>ODKRYJ</button>
      </div>`).join('');

    const strikeBoxes = [0, 1, 2].map((i) => `<div class="strike-box${i < state.strikes ? ' lit' : ''}">X</div>`).join('');

    root.innerHTML = `
      <div class="prezenter-grid">
        <section class="panel">
          <h2>${esc(state.question ? state.question.text : 'Brak pytania')}</h2>
          <div id="answerList">${answerRows}</div>
        </section>
        <aside class="sidebar">
          <div class="score-card team-blue${state.active_team === 'blue' ? ' on-clock' : ''}">
            <span class="team-chip team-blue">NIEBIESCY</span>
            <span class="score-value">${state.teams.blue.score}</span>
          </div>
          <div class="score-card team-red${state.active_team === 'red' ? ' on-clock' : ''}">
            <span class="team-chip team-red">CZERWONI</span>
            <span class="score-value">${state.teams.red.score}</span>
          </div>

          ${questionPending ? `
          <div class="panel">
            <h2>Pytanie ${state.round_number ?? '-'}</h2>
            <button class="btn btn-primary" id="revealQuestionBtn" style="width:100%;">POKAŻ PYTANIE</button>
          </div>
          ` : ''}

          <div class="panel">
            <h2>Zespół rozpoczynający</h2>
            <div class="team-select">
              <button class="team-blue${state.starting_team === 'blue' ? ' selected' : ''}" data-team="blue" ${locked || !canPlay ? 'disabled' : ''}>NIEBIESCY</button>
              <button class="team-red${state.starting_team === 'red' ? ' selected' : ''}" data-team="red" ${locked || !canPlay ? 'disabled' : ''}>CZERWONI</button>
            </div>
            ${state.phase === 'steal' && !stealResolved ? '<span class="steal-badge">KRADZIEŻ AKTYWNA</span>' : ''}
            ${state.phase === 'steal' && stealResolved ? `<span class="steal-badge">${state.steal_result === 'success' ? 'KRADZIEŻ UDANA' : 'KRADZIEŻ NIEUDANA'}</span>` : ''}
          </div>

          <div class="panel">
            <h2>Błędy ${state.strikes}/3</h2>
            <div class="strikes-row">${strikeBoxes}</div>
            <button class="btn-strike" id="strikeBtn" ${canPlay ? '' : 'disabled'}>BŁĄD</button>
          </div>

          ${state.phase === 'round_end' ? `
          <button class="btn-finish" id="nextRoundBtn">${state.is_last_round ? 'ZAKOŃCZ GRĘ' : 'NASTĘPNA RUNDA'}</button>
          ` : `
          <button class="btn-finish" id="finishBtn" ${canFinish ? '' : 'disabled'}>ZAKOŃCZ RUNDĘ</button>
          `}

          ${state.game.mode === 'free_rounds' && state.phase === 'round' ? `
          <button class="btn btn-danger" id="endGameBtn" style="width:100%;">Zakończ grę (wolne rundy)</button>
          ` : ''}

          <div class="panel">
            <h2>Dźwięki · wyzwól ręcznie</h2>
            <div class="cue-grid">
              ${Object.keys(CUE_LABELS).map((cue) => `<button class="cue-btn" data-cue="${cue}">${CUE_LABELS[cue]}</button>`).join('')}
            </div>
          </div>
        </aside>
      </div>
    `;

    root.querySelectorAll('.btn-reveal').forEach((btn) => {
      btn.addEventListener('click', () => callAction('reveal', { answer_id: parseInt(btn.dataset.answerId, 10) }));
    });
    root.querySelectorAll('.team-select button').forEach((btn) => {
      btn.addEventListener('click', () => callAction('set_team', { team: btn.dataset.team }));
    });
    const revealQuestionBtn = document.getElementById('revealQuestionBtn');
    if (revealQuestionBtn) revealQuestionBtn.addEventListener('click', () => callAction('reveal_question'));
    const strikeBtn = document.getElementById('strikeBtn');
    if (strikeBtn) strikeBtn.addEventListener('click', () => callAction('strike'));
    const finishBtn = document.getElementById('finishBtn');
    if (finishBtn) finishBtn.addEventListener('click', () => callAction('finish_round'));
    const nextRoundBtn = document.getElementById('nextRoundBtn');
    if (nextRoundBtn) nextRoundBtn.addEventListener('click', goNextRound);
    const endGameBtn = document.getElementById('endGameBtn');
    if (endGameBtn) endGameBtn.addEventListener('click', () => {
      if (confirm('Zakończyć grę teraz? Wygra zespół z wyższym wynikiem.')) callAction('end_game');
    });
    root.querySelectorAll('.cue-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        SoundCues.playCue(btn.dataset.cue);
        btn.classList.add('flash');
        setTimeout(() => btn.classList.remove('flash'), 400);
      });
    });
  }

  let gating = false; // separate from `busy` — covers the pre-action sound-playback window too

  // START GRY: play game_start, then transition lobby -> round only once it's finished
  // (Spec: the board must not show "Pytanie 1" until the presenter's music has ended).
  async function beginGame() {
    if (gating || busy) return;
    gating = true;
    try {
      await SoundCues.playCue('game_start');
      await callAction('begin_game', undefined, true);
    } finally {
      gating = false;
    }
  }

  // NASTĘPNA RUNDA / ZAKOŃCZ GRĘ: round_end already played the moment the round
  // actually ended (finish_round / the steal resolving — detectAndPlay caught that
  // transition). This click advances, then plays the cue for wherever it landed:
  // round_start when another round loaded, or end_game when this was the final
  // round (game over). Previously it always played round_start, which overlapped
  // the board's end_game cue on the last round.
  async function goNextRound() {
    if (gating || busy) return;
    gating = true;
    try {
      const state = await callAction('advance_round', undefined, true);
      if (state) {
        SoundCues.playCue(state.phase === 'finished' ? 'end_game' : 'round_start');
      }
    } finally {
      gating = false;
    }
  }

  async function poll() {
    try {
      const url = gameId ? `../api/state.php?game_id=${gameId}` : '../api/state.php';
      const state = await Api.getJson(url);
      if (!busy) {
        SoundCues.detectAndPlay(prevState, state);
        renderState(state);
        prevState = state;
      }
    } catch (e) {
      // keep last good frame
    } finally {
      setTimeout(poll, pollMs);
    }
  }

  poll();
})();
