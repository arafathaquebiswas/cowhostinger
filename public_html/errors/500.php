<?php
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Server Error — Cow Management</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:#F5F3EE;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:3rem 2.5rem;text-align:center;max-width:420px;width:100%}
.code{font-size:5rem;font-weight:800;color:#D97706;line-height:1}
h1{font-size:1.4rem;font-weight:700;color:#1B4332;margin:.75rem 0 .5rem}
p{color:#6B7280;font-size:.95rem;line-height:1.6}
.btn{display:inline-block;margin-top:1.5rem;padding:.65rem 1.4rem;background:#2D6A4F;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem}
</style>
</head>
<body>
<div class="card">
    <div class="code">500</div>
    <h1>Something Went Wrong</h1>
    <p>A server error occurred. Our team has been notified. Please try again in a moment.</p>
    <a href="/dashboard.php" class="btn">Back to Dashboard</a>
</div>
</body>
</html>
