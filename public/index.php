<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/lib/config.php';
$cfg = familiada_config();
$pollMs = (int) ($cfg['poll_interval_ms'] ?? 1000);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plansza — Familiada</title>
<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/board.css">
</head>
<body class="board">
<div class="board-stage" id="stage" data-poll-ms="<?= $pollMs ?>">
  <div class="arc-glow red" aria-hidden="true"></div>
  <div class="arc-glow blue" aria-hidden="true"></div>

  <div id="content">
    <p class="lobby-message">Oczekiwanie na start gry…</p>
  </div>
</div>

<script src="assets/js/api.js"></script>
<script src="assets/js/sound.js"></script>
<script src="assets/js/board.js"></script>
</body>
</html>
