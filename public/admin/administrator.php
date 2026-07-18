<?php
declare(strict_types=1);
require_once __DIR__ . '/../paths.php';
require_once FAMILIADA_PRIVATE_DIR . '/src/lib/config.php';
require_once FAMILIADA_PRIVATE_DIR . '/src/lib/auth.php';
auth_require_web('administrator.php');

$active = 'administrator';
$headerRoundChip = null;
$headerMultiplierChip = null;
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administrator — Familiada</title>
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/tokens.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/cockpit.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(familiada_asset('../assets', 'css/footer.css'), ENT_QUOTES) ?>">
</head>
<body class="cockpit">
<?php
$headerRoundChip = null; $headerMultiplierChip = null;
include __DIR__ . '/_header.php';
?>
<div class="admin-shell" id="adminRoot">
  <p style="color:var(--cockpit-text-muted);">Wczytywanie…</p>
</div>

<?php $footerAssetPrefix = '../'; include __DIR__ . '/../partials/footer.php'; ?>

<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/api.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/sound.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(familiada_asset('../assets', 'js/admin.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
