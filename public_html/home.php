<?php
require_once __DIR__ . '/includes/auth.php';
startSecureSession();

if (isLoggedIn()) {
    redirect(getRoleRedirect(currentRole()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Cow Management — Home</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Public layout ── */
        body { background: var(--bg-base); }

        .pub-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(27,67,50,.97);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .85rem 2rem;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }
        .pub-nav-brand {
            display: flex;
            align-items: center;
            gap: .6rem;
            color: #fff;
            font-weight: 700;
            font-size: 1.05rem;
            text-decoration: none;
        }
        .pub-nav-brand:hover { text-decoration: none; }
        .pub-nav-links { display: flex; align-items: center; gap: .5rem; }
        .pub-nav-link {
            color: rgba(255,255,255,.82);
            font-size: .88rem;
            font-weight: 500;
            padding: .42rem .85rem;
            border-radius: var(--radius);
            transition: var(--transition);
            text-decoration: none;
        }
        .pub-nav-link:hover { color: #fff; background: rgba(255,255,255,.12); text-decoration: none; }
        .pub-nav-cta {
            background: var(--accent);
            color: #fff !important;
            font-weight: 600;
        }
        .pub-nav-cta:hover { background: var(--accent-dark); }

        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, #1B4332 0%, #2D6A4F 55%, #40916C 100%);
            color: #fff;
            padding: 5rem 2rem 4.5rem;
            text-align: center;
        }
        .hero-eyebrow {
            display: inline-block;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 50px;
            padding: .3rem 1rem;
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: 1.5rem;
        }
        .hero h1 {
            font-size: clamp(2rem, 5vw, 3rem);
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 1.25rem;
            max-width: 720px;
            margin-left: auto;
            margin-right: auto;
        }
        .hero-sub {
            font-size: 1.1rem;
            color: rgba(255,255,255,.82);
            max-width: 560px;
            margin: 0 auto 2.5rem;
            line-height: 1.7;
        }
        .hero-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
        .hero-btn {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .85rem 2rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: .95rem;
            transition: var(--transition);
            text-decoration: none;
        }
        .hero-btn-primary {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 4px 14px rgba(212,160,23,.35);
        }
        .hero-btn-primary:hover { background: var(--accent-dark); text-decoration: none; transform: translateY(-1px); }
        .hero-btn-outline {
            background: rgba(255,255,255,.12);
            color: #fff;
            border: 1.5px solid rgba(255,255,255,.35);
        }
        .hero-btn-outline:hover { background: rgba(255,255,255,.22); text-decoration: none; }
        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin-top: 3.5rem;
            flex-wrap: wrap;
        }
        .hero-stat-num { font-size: 1.8rem; font-weight: 800; }
        .hero-stat-lbl { font-size: .78rem; color: rgba(255,255,255,.7); margin-top: .2rem; }

        /* ── Section common ── */
        .section { padding: 5rem 2rem; }
        .section-alt { background: #fff; }
        .section-center { text-align: center; max-width: 680px; margin: 0 auto 3.5rem; }
        .section-eyebrow {
            color: var(--primary);
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            margin-bottom: .6rem;
        }
        .section-title { font-size: clamp(1.5rem, 3vw, 2rem); font-weight: 800; margin-bottom: .85rem; color: var(--text-primary); }
        .section-desc { color: var(--text-secondary); line-height: 1.7; }

        /* ── Feature grid ── */
        .feat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            max-width: 1100px;
            margin: 0 auto;
        }
        .feat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            transition: var(--transition);
        }
        .section-alt .feat-card { background: var(--bg-base); }
        .feat-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
        .feat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            background: var(--primary-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 1.1rem;
        }
        .feat-title { font-size: 1rem; font-weight: 700; margin-bottom: .45rem; color: var(--text-primary); }
        .feat-desc { font-size: .875rem; color: var(--text-secondary); line-height: 1.65; }

        /* ── How it works ── */
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 2rem;
            max-width: 1000px;
            margin: 0 auto;
            position: relative;
        }
        .step-item { text-align: center; }
        .step-num {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            font-weight: 800;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto .9rem;
            box-shadow: 0 4px 12px rgba(45,106,79,.3);
        }
        .step-title { font-weight: 700; font-size: .95rem; margin-bottom: .4rem; }
        .step-desc { font-size: .83rem; color: var(--text-secondary); line-height: 1.6; }

        /* ── CTA Banner ── */
        .cta-banner {
            background: linear-gradient(135deg, #1B4332, #2D6A4F);
            color: #fff;
            text-align: center;
            padding: 4rem 2rem;
        }
        .cta-banner h2 { font-size: 1.8rem; font-weight: 800; margin-bottom: .75rem; }
        .cta-banner p { color: rgba(255,255,255,.8); margin-bottom: 2rem; }

        /* ── Footer ── */
        .pub-footer {
            background: #0F2D1F;
            color: rgba(255,255,255,.55);
            text-align: center;
            padding: 1.75rem 2rem;
            font-size: .83rem;
        }
        .pub-footer a { color: rgba(255,255,255,.7); }
        .pub-footer a:hover { color: #fff; }

        /* ── Mobile nav toggle ── */
        .pub-nav-toggle { display: none; background: none; border: none; color: #fff; cursor: pointer; }
        @media (max-width: 600px) {
            .pub-nav-links { gap: .25rem; }
            .pub-nav-link { padding: .38rem .6rem; font-size: .82rem; }
            .hero { padding: 3.5rem 1.25rem 3rem; }
            .hero-stats { gap: 2rem; }
            .section { padding: 3.5rem 1.25rem; }
        }
    </style>
</head>
<body>

<!-- ── Navigation ── -->
<nav class="pub-nav">
    <a href="/home.php" class="pub-nav-brand">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="30" height="30">
            <ellipse cx="32" cy="38" rx="22" ry="16" fill="rgba(255,255,255,.2)" stroke="#fff" stroke-width="2"/>
            <circle cx="20" cy="24" r="8" fill="rgba(255,255,255,.2)" stroke="#fff" stroke-width="2"/>
            <circle cx="44" cy="24" r="8" fill="rgba(255,255,255,.2)" stroke="#fff" stroke-width="2"/>
            <circle cx="26" cy="40" r="3" fill="#fff"/>
            <circle cx="38" cy="40" r="3" fill="#fff"/>
            <ellipse cx="32" cy="46" rx="5" ry="3" fill="var(--accent)"/>
            <line x1="14" y1="18" x2="10" y2="10" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
            <line x1="20" y1="16" x2="18" y2="8"  stroke="#fff" stroke-width="2" stroke-linecap="round"/>
            <line x1="50" y1="18" x2="54" y2="10" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
            <line x1="44" y1="16" x2="46" y2="8"  stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        CowMgmt System
    </a>
    <div class="pub-nav-links">
        <a href="/home.php"   class="pub-nav-link">Home</a>
        <a href="/index.php"  class="pub-nav-link">Login</a>
        <a href="/register.php" class="pub-nav-link pub-nav-cta">Register Now</a>
    </div>
</nav>

<!-- ── Hero ── -->
<section class="hero">
    <div class="hero-eyebrow">Smart Farm Management</div>
    <h1>Manage Your Dairy Farm<br>with Confidence</h1>
    <p class="hero-sub">
        A complete cow management and diagnosis system — track health, milk production,
        breeding, finances, and more from one simple dashboard.
    </p>
    <div class="hero-actions">
        <a href="/register.php" class="hero-btn hero-btn-primary">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
                <line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
            </svg>
            Register for Free
        </a>
        <a href="/index.php" class="hero-btn hero-btn-outline">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/>
                <line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Sign In
        </a>
    </div>
    <div class="hero-stats">
        <div>
            <div class="hero-stat-num">14+</div>
            <div class="hero-stat-lbl">Modules</div>
        </div>
        <div>
            <div class="hero-stat-num">24</div>
            <div class="hero-stat-lbl">Data Tables</div>
        </div>
        <div>
            <div class="hero-stat-num">100%</div>
            <div class="hero-stat-lbl">Secure</div>
        </div>
        <div>
            <div class="hero-stat-num">Real-Time</div>
            <div class="hero-stat-lbl">Analytics</div>
        </div>
    </div>
</section>

<!-- ── Features ── -->
<section class="section">
    <div class="section-center">
        <div class="section-eyebrow">What You Get</div>
        <h2 class="section-title">Everything Your Farm Needs</h2>
        <p class="section-desc">
            From individual cow health records to farm-wide financial reports — all in one place.
        </p>
    </div>
    <div class="feat-grid">
        <div class="feat-card">
            <div class="feat-icon">🐄</div>
            <div class="feat-title">Cow Management</div>
            <div class="feat-desc">Track each cow's breed, weight, health status, and full history. Soft-archive cows safely when records exist.</div>
        </div>
        <div class="feat-card">
            <div class="feat-icon">🥛</div>
            <div class="feat-title">Milk Production</div>
            <div class="feat-desc">Record daily milk output per cow, track fat percentage and quality flags, and monitor revenue trends over time.</div>
        </div>
        <div class="feat-card">
            <div class="feat-icon">💊</div>
            <div class="feat-title">Health & Treatments</div>
            <div class="feat-desc">Log vet diagnoses, prescribe treatments, and keep a complete medical history for every animal on the farm.</div>
        </div>
        <div class="feat-card">
            <div class="feat-icon">💰</div>
            <div class="feat-title">Finance Tracking</div>
            <div class="feat-desc">Record income and expenses by category, compare month-over-month performance, and view profit/loss at a glance.</div>
        </div>
        <div class="feat-card">
            <div class="feat-icon">🌾</div>
            <div class="feat-title">Feed & Medicine Stock</div>
            <div class="feat-desc">Manage inventory for feeds and medicines with auto low-stock alerts, supplier tracking, and purchase history.</div>
        </div>
        <div class="feat-card">
            <div class="feat-icon">📊</div>
            <div class="feat-title">Smart Dashboard</div>
            <div class="feat-desc">Real-time KPI cards with live AJAX updates, Chart.js visualizations, and role-based access for every team member.</div>
        </div>
    </div>
</section>

<!-- ── How It Works ── -->
<section class="section section-alt">
    <div class="section-center">
        <div class="section-eyebrow">Getting Started</div>
        <h2 class="section-title">Up and Running in Minutes</h2>
        <p class="section-desc">Five simple steps from sign-up to full farm oversight.</p>
    </div>
    <div class="steps-grid">
        <div class="step-item">
            <div class="step-num">1</div>
            <div class="step-title">Register Your Account</div>
            <div class="step-desc">Sign up with your name, email, and optional farm details in under a minute.</div>
        </div>
        <div class="step-item">
            <div class="step-num">2</div>
            <div class="step-title">Add Your Cows</div>
            <div class="step-desc">Input each cow's tag number, breed, birth date, weight, and health status.</div>
        </div>
        <div class="step-item">
            <div class="step-num">3</div>
            <div class="step-title">Log Daily Activities</div>
            <div class="step-desc">Record milk output, treatments, feed usage, and any financial transactions.</div>
        </div>
        <div class="step-item">
            <div class="step-num">4</div>
            <div class="step-title">Monitor Alerts</div>
            <div class="step-desc">Get notified about low stock, sick animals, and upcoming breeding events.</div>
        </div>
        <div class="step-item">
            <div class="step-num">5</div>
            <div class="step-title">Grow Your Farm</div>
            <div class="step-desc">Use analytics and reports to make data-driven decisions and increase profitability.</div>
        </div>
    </div>
</section>

<!-- ── CTA Banner ── -->
<section class="cta-banner">
    <h2>Ready to Modernise Your Farm?</h2>
    <p>Join farmers already managing their herd smarter. Registration is free.</p>
    <a href="/register.php" class="hero-btn hero-btn-primary" style="display:inline-flex">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
            <line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
        </svg>
        Create Your Free Account
    </a>
</section>

<!-- ── Footer ── -->
<footer class="pub-footer">
    <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &nbsp;·&nbsp;
       <a href="/index.php">Login</a> &nbsp;·&nbsp;
       <a href="/register.php">Register</a>
    </p>
</footer>

</body>
</html>
