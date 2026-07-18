<?php
declare(strict_types=1);
require_once __DIR__ . '/paths.php';
require_once FAMILIADA_PRIVATE_DIR . '/src/lib/config.php';
$cfg = familiada_config();
$pollMs = (int) ($cfg['poll_interval_ms'] ?? 1000);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plansza — Familiada</title>
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('assets', 'css/tokens.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('assets', 'css/board.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('assets', 'css/footer.css'), ENT_QUOTES) ?>">
</head>
<body class="board">
<div class="board-stage" id="stage" data-poll-ms="<?= $pollMs ?>">
  <div class="corner-glow" aria-hidden="true"></div>
  <div class="arc red-outer" aria-hidden="true"></div>
  <div class="arc red-inner" aria-hidden="true"></div>
  <div class="arc blue-outer" aria-hidden="true"></div>
  <div class="arc blue-inner" aria-hidden="true"></div>

  <div class="board-game-name" id="boardGameName" hidden></div>

  <div id="content">
    <p class="lobby-message">Oczekiwanie na start gry…</p>
  </div>

  <div class="sound-unlock-overlay" id="soundUnlockOverlay">
    <button type="button" id="soundUnlockBtn">Kliknij, aby włączyć dźwięk planszy</button>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script src="<?= htmlspecialchars(familiada_asset('assets', 'js/api.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(familiada_asset('assets', 'js/sound.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(familiada_asset('assets', 'js/board.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
