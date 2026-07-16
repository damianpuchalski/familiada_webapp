<?php
/** @var string $active */
declare(strict_types=1);
?>
<header class="cockpit-header">
  <span class="wordmark">FAMILIADA</span>
  <span class="panel-label">Panel sterowania</span>
  <span class="chip" id="headerRoundChip" hidden></span>
  <span class="chip chip-amber" id="headerMultiplierChip" hidden></span>
  <span class="spacer"></span>
  <span class="sound-line" id="headerSoundLine"></span>
  <a class="nav-link<?= $active === 'prezenter' ? ' active' : '' ?>" href="index.php">Prezenter</a>
  <a class="nav-link<?= $active === 'administrator' ? ' active' : '' ?>" href="administrator.php">Administrator</a>
  <a class="nav-link" href="logout.php">Wyloguj</a>
</header>
