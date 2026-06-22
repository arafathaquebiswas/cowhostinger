<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
requireRole(['superadmin']);
header('Location: /modules/ceo/index.php', true, 301);
exit;
