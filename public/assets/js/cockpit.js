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
    correct: 'Poprawna odpowiedź',
    strike: 'Strike / Buzzer',
    round_start: 'Start rundy',
    reveal: 'Odkrycie odpowiedzi',
    finale_timer: 'Zegar finału',
    end_game: 'Koniec gry',
  };

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  }

  async function callAction(action, extra) {
    if (busy) return;
    busy = true;
    try {
      const state = await Api.postJson('../api/action.php', { action, game_id: gameId, ...extra });
      SoundCues.detectAndPlay(prevState, state);
      renderState(state);
      prevState = state;
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

    const answers = state.answers || [];
    const locked = state.team_select_locked;
    const canReveal = state.phase === 'round' || state.phase === 'steal';

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

          <div class="panel">
            <h2>Zespół rozpoczynający</h2>
            <div class="team-select">
              <button class="team-blue${state.starting_team === 'blue' ? ' selected' : ''}" data-team="blue" ${locked ? 'disabled' : ''}>NIEBIESCY</button>
              <button class="team-red${state.starting_team === 'red' ? ' selected' : ''}" data-team="red" ${locked ? 'disabled' : ''}>CZERWONI</button>
            </div>
            ${state.phase === 'steal' ? '<span class="steal-badge">KRADZIEŻ AKTYWNA</span>' : ''}
          </div>

          <div class="panel">
            <h2>Błędy ${state.strikes}/3</h2>
            <div class="strikes-row">${strikeBoxes}</div>
            <button class="btn-strike" id="strikeBtn" ${canReveal ? '' : 'disabled'}>BŁĄD</button>
          </div>

          <button class="btn-finish" id="finishBtn">ZAKOŃCZ RUNDĘ</button>

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
    const strikeBtn = document.getElementById('strikeBtn');
    if (strikeBtn) strikeBtn.addEventListener('click', () => callAction('strike'));
    document.getElementById('finishBtn').addEventListener('click', () => callAction('finish_round'));
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
