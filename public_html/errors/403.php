<?php
http_response_code(403);
$logged_in = !empty($_SESSION['logged_in'] ?? false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Denied — Cow Management</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:#F5F3EE;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:3rem 2.5rem;text-align:center;max-width:420px;width:100%}
.code{font-size:5rem;font-weight:800;color:#DC2626;line-height:1}
h1{font-size:1.4rem;font-weight:700;color:#1B4332;margin:.75rem 0 .5rem}
p{color:#6B7280;font-size:.95rem;line-height:1.6}
.btn{display:inline-block;margin-top:1.5rem;padding:.65rem 1.4rem;background:#2D6A4F;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem}
</style>
</head>
<body>
<div class="card">
    <div class="code">403</div>
    <h1>Access Denied</h1>
    <p>You don't have permission to access this resource.</p>
    <?php if ($logged_in): ?>
    <a href="/dashboard.php" class="btn">Go to Dashboard</a>
    <?php else: ?>
    <a href="/index.php" class="btn">Sign In</a>
    <?php endif; ?>
</div>
</body>
</html>
