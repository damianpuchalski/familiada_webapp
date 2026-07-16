<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/lib/config.php';
require_once __DIR__ . '/../../src/lib/auth.php';
auth_logout();
header('Location: login.php');
