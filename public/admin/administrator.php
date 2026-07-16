<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/lib/config.php';
require_once __DIR__ . '/../../src/lib/auth.php';
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
<link rel="stylesheet" href="../assets/css/tokens.css">
<link rel="stylesheet" href="../assets/css/cockpit.css">
</head>
<body class="cockpit">
<?php
$headerRoundChip = null; $headerMultiplierChip = null;
include __DIR__ . '/_header.php';
?>
<div class="admin-shell" id="adminRoot">
  <p style="color:var(--cockpit-text-muted);">Wczytywanie…</p>
</div>

<script src="../assets/js/api.js"></script>
<script src="../assets/js/sound.js"></script>
<script src="../assets/js/admin.js"></script>
</body>
</html>
