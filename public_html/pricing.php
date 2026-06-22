<?php
/**
 * pricing.php — Permanently redirected to the subscription module.
 * The subscription module is the single source of truth for plan/pricing info.
 */
header('HTTP/1.1 301 Moved Permanently');
header('Location: /modules/subscription/index.php');
exit;
