<?php
declare(strict_types=1);
require_once __DIR__ . '/../paths.php';
require_once FAMILIADA_PRIVATE_DIR . '/src/lib/config.php';
require_once FAMILIADA_PRIVATE_DIR . '/src/lib/auth.php';

$target = $_GET['target'] ?? 'index.php';
$allowedTargets = ['index.php', 'administrator.php'];
if (!in_array($target, $allowedTargets, true)) {
    $target = 'index.php';
}

if (auth_is_logged_in()) {
    header('Location: ' . $target);
    exit;
}

$expired = isset($_GET['expired']);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie — Familiada</title>
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/tokens.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/cockpit.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/footer.css'), ENT_QUOTES) ?>">
</head>
<body class="cockpit">
<div class="login-wrap">
  <form class="login-card" id="loginForm" novalidate data-target="<?= htmlspecialchars($target, ENT_QUOTES) ?>">
    <div class="wordmark">FAMILIADA</div>
    <div class="subtitle">Panel sterowania</div>

    <?php if ($expired): ?>
    <div class="login-notice" id="expiredNotice">Sesja wygasła. Zaloguj się ponownie, aby wrócić do panelu.</div>
    <?php endif; ?>

    <div class="field-group" id="fieldGroup">
      <input type="password" id="password" name="password" autocomplete="current-password" autofocus placeholder="Hasło">
      <button type="button" class="toggle-visibility" id="toggleVisibility">Pokaż</button>
    </div>
    <div class="error-line" id="errorLine"></div>

    <button type="submit" class="btn btn-primary is-empty" id="submitBtn" disabled>Zaloguj</button>

    <div class="login-footer">Familiada — dostęp tylko dla prowadzącego.</div>
  </form>
</div>
<?php $footerAssetPrefix = '../'; include __DIR__ . '/../partials/footer.php'; ?>
<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/api.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/login.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
