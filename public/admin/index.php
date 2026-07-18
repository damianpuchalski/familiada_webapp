<?php
declare(strict_types=1);
require_once __DIR__ . '/../paths.php';
require_once FAMILIADA_PRIVATE_DIR . '/src/lib/config.php';
require_once FAMILIADA_PRIVATE_DIR . '/src/lib/auth.php';
auth_require_web('index.php');

$cfg = familiada_config();
$pollMs = (int) ($cfg['poll_interval_ms'] ?? 1000);

$active = 'prezenter';
$headerRoundChip = null;
$headerMultiplierChip = null;
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prezenter — Familiada</title>
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/tokens.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/cockpit.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/footer.css'), ENT_QUOTES) ?>">
</head>
<body class="cockpit">
<?php include __DIR__ . '/_header.php'; ?>

<div id="prezenter-root" data-poll-ms="<?= $pollMs ?>">
  <p style="padding:24px;color:var(--cockpit-text-muted);">Wczytywanie…</p>
</div>

<?php $footerAssetPrefix = '../'; include __DIR__ . '/../partials/footer.php'; ?>

<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/api.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/sound.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/cockpit.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
