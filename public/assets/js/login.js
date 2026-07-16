// Logowanie — Spec §5.3. States: default/empty, focused, submitting, error, session-expired.

(() => {
  const form = document.getElementById('loginForm');
  const password = document.getElementById('password');
  const submitBtn = document.getElementById('submitBtn');
  const errorLine = document.getElementById('errorLine');
  const fieldGroup = document.getElementById('fieldGroup');
  const toggleBtn = document.getElementById('toggleVisibility');
  const target = form.dataset.target || 'index.php';

  function updateButtonState() {
    const empty = password.value.trim() === '';
    submitBtn.disabled = empty;
    submitBtn.classList.toggle('is-empty', empty);
  }

  password.addEventListener('input', updateButtonState);
  updateButtonState();
  password.focus();

  toggleBtn.addEventListener('click', () => {
    const showing = password.type === 'text';
    password.type = showing ? 'password' : 'text';
    toggleBtn.textContent = showing ? 'Pokaż' : 'Ukryj';
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submitBtn.disabled) return;

    fieldGroup.classList.remove('error');
    errorLine.textContent = '';
    submitBtn.disabled = true;
    submitBtn.classList.add('is-submitting');
    submitBtn.textContent = 'Sprawdzanie…';

    try {
      await Api.postJson('../api/auth.php', { action: 'login', password: password.value });
      window.location.href = target;
    } catch (err) {
      fieldGroup.classList.add('error');
      // Never hint at closeness — same message regardless of the failure reason.
      errorLine.textContent = 'Nieprawidłowe hasło. Spróbuj ponownie.';
      submitBtn.classList.remove('is-submitting');
      submitBtn.textContent = 'Zaloguj';
      updateButtonState();
      password.focus();
    }
  });
})();
