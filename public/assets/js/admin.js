// Administrator — Gry + Dźwięki tabs, and the game editor. Spec §5.4.
// Tabs hide while the editor is open (editor has its own "← Wróć" back action).

(() => {
  const root = document.getElementById('adminRoot');

  const STATUS_LABELS = {
    draft: 'SZKIC',
    live: 'LIVE',
    in_progress: 'W TRAKCIE',
    paused: 'W TRAKCIE',
    finished: 'ZAKOŃCZONA',
    archived: 'ARCHIWALNA',
  };

  const CUE_LABELS = {
    correct: 'Odp - Popr',
    strike: 'Odp - Błąd',
    round_start: 'Runda - Start',
    round_end: 'Runda - Koniec',
    game_start: 'Gra - Start',
    end_game: 'Gra - Koniec',
  };

  let state = {
    tab: 'games',
    games: [],
    soundSetsList: [],
    editorDraft: null, // {id, name, mode, sound_set_id, question_sets:[...]}
    soundsPackId: null,
    soundsCues: [],
  };

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  }

  async function loadSoundSets() {
    const data = await Api.getJson('../api/sounds.php?action=packs');
    state.soundSetsList = data.packs;
  }

  async function loadGames() {
    const data = await Api.getJson('../api/games.php?action=list');
    state.games = data.games;
  }

  function metaLine(g) {
    const modeLabel = g.mode === 'classic_300' ? 'Klasyczny (300)' : 'Wolne rundy';
    const created = new Date(g.created_at.replace(' ', 'T')).toLocaleDateString('pl-PL');
    let line = `${modeLabel} · utworzono ${created}`;
    if (g.status === 'finished') {
      line += ` · wynik ${g.scores.blue}:${g.scores.red}`;
    }
    return line;
  }

  function renderGamesTab() {
    const rows = state.games.map((g) => {
      const status = g.is_live ? 'live' : g.status;
      const actions = [];
      // A game can be edited or deleted at any status. Editing never resets a running
      // game — use "Zagraj od nowa" for that.
      actions.push(`<button data-action="edit" data-id="${g.id}">Edytuj</button>`);
      if ((g.status === 'in_progress' || g.status === 'paused') && !g.is_live) {
        actions.push(`<button data-action="resume" data-id="${g.id}">Wznów</button>`);
      }
      if (!g.is_live && g.status !== 'archived') {
        actions.push(`<button data-action="set_live" data-id="${g.id}">Ustaw jako live</button>`);
      }
      if (['in_progress', 'paused', 'finished', 'live'].includes(g.status)) {
        actions.push(`<button data-action="restart" data-id="${g.id}">Zagraj od nowa</button>`);
      }
      actions.push(`<button class="btn-danger" data-action="delete" data-id="${g.id}">Usuń</button>`);
      return `<div class="game-row">
        <span class="status-dot ${status}"></span>
        <div>
          <div>${esc(g.name)}</div>
          <div class="game-meta">${metaLine(g)}</div>
        </div>
        <span class="status-badge">${STATUS_LABELS[status] || status}</span>
        <div class="game-actions">${actions.join('')}</div>
      </div>`;
    }).join('') || '<p style="color:var(--cockpit-text-muted);">Brak gier. Utwórz nową grę.</p>';

    root.innerHTML = `
      <div class="admin-tabs">
        <button data-tab="games" class="active">Gry</button>
        <button data-tab="sounds">Dźwięki</button>
      </div>
      <div style="margin-bottom:16px;">
        <button class="btn btn-success" id="newGameBtn">+ Nowa gra</button>
      </div>
      ${rows}
    `;

    document.getElementById('newGameBtn').addEventListener('click', createNewGame);
    root.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));
    root.querySelectorAll('[data-action]').forEach((b) => {
      b.addEventListener('click', () => handleGameAction(b.dataset.action, parseInt(b.dataset.id, 10)));
    });
  }

  async function handleGameAction(action, id) {
    try {
      if (action === 'edit') {
        const data = await Api.getJson(`../api/games.php?action=get&id=${id}`);
        openEditor(data.game);
        return;
      }
      if (action === 'delete') {
        if (!confirm('Usunąć tę grę na stałe? Tej operacji nie można cofnąć.')) return;
        await Api.postJson('../api/games.php', { action: 'delete', id });
      }
      if (action === 'resume' || action === 'set_live') {
        await Api.postJson('../api/action.php', { action: 'set_live', game_id: id });
        if (action === 'resume') {
          window.location.href = 'index.php';
          return;
        }
      }
      if (action === 'restart') {
        if (!confirm('Zagrać od nowa? Wynik i postęp tej gry zostaną zresetowane (pytania zostają).')) return;
        await Api.postJson('../api/action.php', { action: 'restart_game', game_id: id });
      }
      await loadGames();
      renderGamesTab();
    } catch (e) {
      alert(e.message || 'Operacja nieudana.');
    }
  }

  async function createNewGame() {
    const data = await Api.postJson('../api/games.php', {
      action: 'save',
      name: 'Nowa gra',
      mode: 'classic_300',
      sound_set_id: state.soundSetsList[0] ? state.soundSetsList[0].id : null,
      question_sets: [],
    });
    openEditor(data.game);
  }

  // ---------------- Editor ----------------

  function openEditor(game) {
    state.editorDraft = {
      id: game.id,
      name: game.name,
      mode: game.mode,
      sound_set_id: game.sound_set_id,
      question_sets: (game.question_sets || []).map((qs) => ({
        name: qs.name,
        questions: (qs.questions || []).map((q) => ({
          text: q.text,
          answers: (q.answers || []).map((a) => ({ text: a.text, points: a.points })),
        })),
      })),
    };
    state.tab = 'editor';
    renderEditor();
  }

  function renderEditor() {
    const d = state.editorDraft;
    const soundOptions = state.soundSetsList.map((s) =>
      `<option value="${s.id}" ${s.id === d.sound_set_id ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    const questionBlocks = d.question_sets.map((qs, qi) => {
      const answerRows = qs.questions[0] ? qs.questions[0].answers.map((a, ai) => `
        <div class="answer-edit-row">
          <input type="text" value="${esc(a.text)}" data-qi="${qi}" data-ai="${ai}" data-field="text" placeholder="Treść odpowiedzi">
          <input type="number" value="${a.points}" min="0" data-qi="${qi}" data-ai="${ai}" data-field="points" placeholder="Punkty">
          <button class="icon-btn" data-remove-answer data-qi="${qi}" data-ai="${ai}">×</button>
        </div>`).join('') : '';

      const questionText = qs.questions[0] ? qs.questions[0].text : '';

      return `<div class="question-block" data-qi="${qi}">
        <div class="question-block-header">
          <input type="text" value="${esc(questionText)}" data-qi="${qi}" data-field="question-text" placeholder="Treść pytania">
          <button class="icon-btn" data-remove-question data-qi="${qi}">Usuń pytanie</button>
        </div>
        ${answerRows}
        <button class="btn btn-secondary" data-add-answer data-qi="${qi}" style="margin-top:6px;">+ odpowiedź</button>
      </div>`;
    }).join('');

    root.innerHTML = `
      <button class="btn btn-secondary" id="backBtn" style="margin-bottom:16px;">← Wróć</button>
      <div class="editor-header">
        <div class="field"><label>Nazwa gry</label><input type="text" id="gameName" value="${esc(d.name)}"></div>
        <div class="field"><label>Tryb</label>
          <div class="toggle-2">
            <button data-mode="classic_300" class="${d.mode === 'classic_300' ? 'active' : ''}">Klasyczny/300</button>
            <button data-mode="free_rounds" class="${d.mode === 'free_rounds' ? 'active' : ''}">Wolne rundy</button>
          </div>
        </div>
        <div class="field field-narrow"><label>Finał (Nie aktywny)</label>
          <div class="toggle-2"><button disabled>Wył</button></div>
        </div>
        <div class="field"><label>Zestaw dźwięków</label><select id="soundSetSelect">${soundOptions}</select></div>
      </div>

      <div id="questionBlocks">${questionBlocks}</div>
      <button class="btn btn-secondary" id="addQuestionBtn" style="margin-bottom:24px;">+ Dodaj pytanie</button>

      <div style="display:flex; gap:12px;">
        <button class="btn btn-success" id="saveGameBtn">Zapisz grę</button>
        <button class="btn btn-secondary" id="discardBtn">Odrzuć zmiany</button>
      </div>
    `;

    document.getElementById('backBtn').addEventListener('click', backToGames);
    document.getElementById('discardBtn').addEventListener('click', backToGames);
    document.getElementById('gameName').addEventListener('input', (e) => { d.name = e.target.value; });
    root.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => { d.mode = b.dataset.mode; renderEditor(); }));
    document.getElementById('soundSetSelect').addEventListener('change', (e) => { d.sound_set_id = parseInt(e.target.value, 10); });
    document.getElementById('addQuestionBtn').addEventListener('click', () => {
      d.question_sets.push({ name: null, questions: [{ text: '', answers: [] }] });
      renderEditor();
    });
    root.querySelectorAll('[data-remove-question]').forEach((b) => b.addEventListener('click', () => {
      d.question_sets.splice(parseInt(b.dataset.qi, 10), 1);
      renderEditor();
    }));
    root.querySelectorAll('[data-add-answer]').forEach((b) => b.addEventListener('click', () => {
      const qi = parseInt(b.dataset.qi, 10);
      const q = d.question_sets[qi].questions[0] || (d.question_sets[qi].questions[0] = { text: '', answers: [] });
      if (q.answers.length >= 8) { alert('Maksymalnie 8 odpowiedzi na pytanie.'); return; }
      q.answers.push({ text: '', points: 0 });
      renderEditor();
    }));
    root.querySelectorAll('[data-remove-answer]').forEach((b) => b.addEventListener('click', () => {
      const qi = parseInt(b.dataset.qi, 10), ai = parseInt(b.dataset.ai, 10);
      d.question_sets[qi].questions[0].answers.splice(ai, 1);
      renderEditor();
    }));
    root.querySelectorAll('[data-field="question-text"]').forEach((inp) => inp.addEventListener('input', (e) => {
      const qi = parseInt(inp.dataset.qi, 10);
      const q = d.question_sets[qi].questions[0] || (d.question_sets[qi].questions[0] = { text: '', answers: [] });
      q.text = e.target.value;
    }));
    root.querySelectorAll('input[data-field="text"], input[data-field="points"]').forEach((inp) => inp.addEventListener('input', (e) => {
      const qi = parseInt(inp.dataset.qi, 10), ai = parseInt(inp.dataset.ai, 10);
      const a = d.question_sets[qi].questions[0].answers[ai];
      if (inp.dataset.field === 'text') a.text = e.target.value;
      else a.points = parseInt(e.target.value, 10) || 0;
    }));
    document.getElementById('saveGameBtn').addEventListener('click', saveEditor);
  }

  async function saveEditor() {
    const d = state.editorDraft;
    try {
      await Api.postJson('../api/games.php', {
        action: 'save',
        id: d.id,
        name: d.name,
        mode: d.mode,
        sound_set_id: d.sound_set_id,
        question_sets: d.question_sets,
      });
      backToGames();
    } catch (e) {
      alert(e.message || 'Nie udało się zapisać gry.');
    }
  }

  async function backToGames() {
    state.tab = 'games';
    await loadGames();
    renderGamesTab();
  }

  // ---------------- Sounds (Dźwięki) ----------------

  async function switchTab(tab) {
    state.tab = tab;
    if (tab === 'games') {
      await loadGames();
      renderGamesTab();
    } else if (tab === 'sounds') {
      if (!state.soundsPackId && state.soundSetsList[0]) {
        state.soundsPackId = state.soundSetsList[0].id;
      }
      await loadSoundStatus();
      renderSoundsTab();
    }
  }

  async function loadSoundStatus() {
    if (!state.soundsPackId) return;
    const data = await Api.getJson(`../api/sounds.php?action=status&sound_set_id=${state.soundsPackId}`);
    state.soundsCues = data.cues;
  }

  function renderSoundsTab() {
    const packButtons = state.soundSetsList.map((p) =>
      `<button class="${p.id === state.soundsPackId ? 'active' : ''}" data-pack="${p.id}">${esc(p.name)}</button>`).join('');

    const cueRows = state.soundsCues.map((c) => `
      <div class="cue-row">
        <span>${CUE_LABELS[c.cue] || c.cue}</span>
        <span class="file-state${c.has_file ? ' has-file' : ''}">
          ${c.has_file ? esc(c.file_path.split('/').pop()) : 'Brak przypisanego pliku — odtwarzany będzie dźwięk domyślny'}
        </span>
        <button class="btn btn-secondary" data-play="${c.url}">▶ Odsłuchaj</button>
        ${c.has_file ? `<button class="btn btn-secondary" data-remove-cue="${c.cue}">Usuń</button>` : '<span></span>'}
        <label class="btn btn-secondary" style="cursor:pointer;">
          ${c.has_file ? 'Zamień plik' : 'Wybierz plik'}
          <input type="file" accept="audio/*" data-upload-cue="${c.cue}" style="display:none;">
        </label>
      </div>`).join('');

    root.innerHTML = `
      <div class="admin-tabs">
        <button data-tab="games">Gry</button>
        <button data-tab="sounds" class="active">Dźwięki</button>
      </div>
      <div class="sounds-subtabs">${packButtons}</div>
      <div>${cueRows}</div>
    `;

    root.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));
    root.querySelectorAll('[data-pack]').forEach((b) => b.addEventListener('click', async () => {
      state.soundsPackId = parseInt(b.dataset.pack, 10);
      await loadSoundStatus();
      renderSoundsTab();
    }));
    root.querySelectorAll('[data-play]').forEach((b) => b.addEventListener('click', () => {
      new Audio(b.dataset.play).play().catch(() => {});
    }));
    root.querySelectorAll('[data-remove-cue]').forEach((b) => b.addEventListener('click', async () => {
      await Api.postJson('../api/sounds.php', { action: 'delete', sound_set_id: state.soundsPackId, cue: b.dataset.removeCue });
      await loadSoundStatus();
      renderSoundsTab();
    }));
    root.querySelectorAll('[data-upload-cue]').forEach((input) => input.addEventListener('change', async () => {
      if (!input.files[0]) return;
      // Mirror of SoundLibrary::MAX_UPLOAD_BYTES (8 MB) — friendlier than a round-trip.
      const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
      if (input.files[0].size > MAX_UPLOAD_BYTES) {
        alert('Plik jest za duży (maks. 8 MB).');
        input.value = '';
        return;
      }
      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('sound_set_id', state.soundsPackId);
      fd.append('cue', input.dataset.uploadCue);
      fd.append('file', input.files[0]);
      try {
        await Api.postForm('../api/sounds.php', fd);
        await loadSoundStatus();
        renderSoundsTab();
      } catch (e) {
        alert(e.message || 'Przesyłanie nieudane.');
      }
    }));
  }

  (async function init() {
    await Promise.all([loadGames(), loadSoundSets()]);
    renderGamesTab();
  })();
})();
