<?php
/**
 * pricing.php — Public-facing pricing page.
 * No auth required. Loads plans from DB.
 */
require_once __DIR__ . '/includes/auth.php';
startSecureSession();

$logged_in    = isset($_SESSION['user_id']) && isset($_SESSION['farm_id']);
$current_plan = '';
if ($logged_in) {
    require_once __DIR__ . '/includes/subscription_engine.php';
    require_once __DIR__ . '/includes/farm_guard.php';
    $current_plan = currentPlanName();
}

try {
    $db    = getDB();
    $plans = $db->query("SELECT * FROM plans WHERE is_active=1 ORDER BY price_monthly ASC")->fetchAll();
} catch (\Throwable $e) {
    $plans = [];
}

function findPlan(array $plans, string $name): ?array {
    foreach ($plans as $p) { if ($p['name'] === $name) return $p; }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing — <?= e(APP_NAME) ?></title>
    <meta name="description" content="Simple, transparent pricing for every farm size. Start free, upgrade when you need more.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --green:   #2D6A4F;
            --green-d: #1B4332;
            --purple:  #7C3AED;
            --purp-l:  #F5F3FF;
            --dark:    #111827;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 14px;
            color: #111827;
            background: #F8FAFC;
            line-height: 1.55;
        }

        /* ── Nav ──────────────────────────────────────────────── */
        .top-nav {
            position: sticky; top: 0; z-index: 200;
            background: rgba(255,255,255,.96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #E5E7EB;
            padding: .7rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        }
        .nav-brand {
            font-size: .95rem; font-weight: 800; color: var(--green-d);
            text-decoration: none; display: flex; align-items: center; gap: .4rem;
        }
        .nav-links { display: flex; gap: .4rem; align-items: center; }
        .nav-link  { font-size: .82rem; font-weight: 600; color: #374151; text-decoration: none; padding: .35rem .75rem; border-radius: 6px; }
        .nav-link:hover { background: #F3F4F6; }
        .nav-btn   { font-size: .82rem; font-weight: 700; padding: .4rem 1rem; border-radius: 8px; text-decoration: none; transition: opacity .15s; }
        .nav-btn:hover { opacity: .85; }
        .nav-outline { border: 1.5px solid var(--green); color: var(--green); }
        .nav-fill    { background: var(--green); color: #fff; }
        @media (max-width: 640px) { .nav-link { display: none; } }

        /* ── Hero ─────────────────────────────────────────────── */
        .hero {
            text-align: center; padding: 4.5rem 1.5rem 2rem;
            max-width: 660px; margin: 0 auto;
        }
        .hero-pill {
            display: inline-flex; align-items: center; gap: .35rem;
            background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46;
            font-size: .7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; padding: .25rem .8rem; border-radius: 50px;
            margin-bottom: 1.25rem;
        }
        .hero h1 {
            font-size: clamp(1.8rem, 4.5vw, 2.6rem);
            font-weight: 900; line-height: 1.12; color: var(--dark); margin-bottom: .9rem;
        }
        .hero h1 em { font-style: normal; color: var(--purple); }
        .hero-sub { font-size: .95rem; color: #6B7280; max-width: 460px; margin: 0 auto 2rem; }

        /* ── Billing toggle ──────────────────────────────────── */
        .toggle-wrap {
            display: flex; align-items: center; justify-content: center;
            gap: .75rem; margin-bottom: 3rem; font-size: .85rem; font-weight: 600;
        }
        .toggle-label { color: #374151; }
        .toggle-label.active { color: var(--dark); }
        .toggle-switch {
            position: relative; width: 46px; height: 26px; cursor: pointer;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-track {
            position: absolute; inset: 0;
            background: #D1D5DB; border-radius: 50px; transition: .25s;
        }
        .toggle-switch input:checked + .toggle-track { background: var(--purple); }
        .toggle-thumb {
            position: absolute; top: 3px; left: 3px;
            width: 20px; height: 20px; background: #fff; border-radius: 50%;
            transition: .25s; box-shadow: 0 1px 4px rgba(0,0,0,.2);
        }
        .toggle-switch input:checked ~ .toggle-thumb { transform: translateX(20px); }
        .save-pill {
            background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;
            font-size: .68rem; font-weight: 800; letter-spacing: .04em;
            padding: .18rem .55rem; border-radius: 50px; text-transform: uppercase;
        }

        /* ── Cards grid ───────────────────────────────────────── */
        .cards-wrap {
            max-width: 1100px; margin: 0 auto 5rem; padding: 0 1.25rem;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem; align-items: start;
        }
        @media (max-width: 1040px) { .cards-wrap { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 600px)  { .cards-wrap { grid-template-columns: 1fr; max-width: 400px; } }

        /* Base card */
        .card {
            background: #fff;
            border: 1.5px solid #E5E7EB;
            border-radius: 18px;
            padding: 1.75rem 1.5rem 1.5rem;
            display: flex; flex-direction: column;
            position: relative; transition: box-shadow .2s;
        }
        .card:hover { box-shadow: 0 6px 28px rgba(0,0,0,.09); }

        /* Pro card — highlighted */
        .card-pro {
            background: linear-gradient(160deg, #F5F3FF 0%, #EDE9FE 100%);
            border-color: #C4B5FD;
            box-shadow: 0 8px 36px rgba(124,58,237,.18);
            transform: translateY(-6px);
        }
        .card-pro:hover { box-shadow: 0 14px 44px rgba(124,58,237,.26); }

        /* Enterprise card — dark premium */
        .card-enterprise {
            background: linear-gradient(160deg, #111827 0%, #1F2937 100%);
            border-color: #374151;
            color: #F9FAFB;
        }
        .card-enterprise:hover { box-shadow: 0 8px 32px rgba(0,0,0,.35); }

        /* Most Popular ribbon */
        .ribbon {
            position: absolute; top: -1px; left: 50%; transform: translateX(-50%);
            background: var(--purple); color: #fff;
            font-size: .6rem; font-weight: 800; letter-spacing: .07em;
            text-transform: uppercase; padding: .22rem 1rem;
            border-radius: 0 0 10px 10px; white-space: nowrap;
        }

        /* Plan name */
        .plan-name {
            font-size: .65rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: .12em; margin-bottom: .35rem;
        }
        .plan-tagline { font-size: .78rem; margin-bottom: 1rem; min-height: 1.6em; }
        .card-enterprise .plan-tagline { color: #9CA3AF; }

        /* Price */
        .price-row { margin-bottom: 1.4rem; }
        .price-orig { font-size: .78rem; text-decoration: line-through; color: #9CA3AF; min-height: 1.1em; }
        .price-amt  { font-size: 2.35rem; font-weight: 900; line-height: 1.05; }
        .price-cycle { font-size: .78rem; font-weight: 500; }
        .card-enterprise .price-cycle { color: #9CA3AF; }
        .price-yearly-note {
            display: none; /* shown by JS when yearly active */
            font-size: .72rem; font-weight: 700; color: #059669;
            margin-top: .15rem;
        }
        .card-enterprise .price-yearly-note { color: #6EE7B7; }

        /* Limits pill row */
        .limits {
            display: flex; flex-direction: column; gap: .28rem;
            padding: .75rem .85rem; border-radius: 10px;
            background: rgba(0,0,0,.04); margin-bottom: 1.25rem;
            font-size: .8rem;
        }
        .card-pro .limits       { background: rgba(124,58,237,.08); }
        .card-enterprise .limits { background: rgba(255,255,255,.07); }
        .limit-row { display: flex; justify-content: space-between; }
        .limit-lbl { color: #6B7280; }
        .limit-val { font-weight: 700; }
        .card-enterprise .limit-lbl { color: #9CA3AF; }
        .card-enterprise .limit-val { color: #F9FAFB; }
        .lim-unlimited { color: #059669; }
        .card-enterprise .lim-unlimited { color: #6EE7B7; }

        /* Features */
        .features { list-style: none; flex: 1; margin-bottom: 1.5rem; font-size: .82rem; display: flex; flex-direction: column; gap: .42rem; }
        .feature-row { display: flex; align-items: flex-start; gap: .45rem; line-height: 1.4; }
        .f-check  { color: #059669; font-weight: 800; flex-shrink: 0; margin-top: .05em; }
        .f-cross  { color: #D1D5DB; flex-shrink: 0; margin-top: .05em; }
        .f-dim    { color: #9CA3AF; }
        .card-pro .f-check  { color: var(--purple); }
        .card-pro .f-cross  { color: #C4B5FD; }
        .card-pro .f-dim    { color: #A78BFA; }
        .card-enterprise .f-check { color: #6EE7B7; }
        .card-enterprise .f-cross { color: #374151; }
        .card-enterprise .f-dim   { color: #6B7280; }

        /* CTA button */
        .cta {
            display: block; text-align: center; padding: .7rem 1rem;
            border-radius: 10px; font-size: .88rem; font-weight: 700;
            text-decoration: none; transition: all .15s; border: 2px solid transparent;
        }
        .cta:hover { transform: translateY(-1px); opacity: .9; }
        .cta-gray    { background: #F3F4F6; color: #374151; }
        .cta-gray:hover { background: #E5E7EB; }
        .cta-blue    { background: #0284C7; color: #fff; }
        .cta-purple  { background: var(--purple); color: #fff; box-shadow: 0 4px 18px rgba(124,58,237,.35); }
        .cta-white   { background: #fff; color: #111827; }
        .cta-white:hover { background: #F9FAFB; }
        .cta-current { background: transparent; border-color: currentColor; cursor: default; }
        .cta-current:hover { transform: none; opacity: 1; }

        /* ── Comparison table ─────────────────────────────────── */
        .compare-wrap {
            max-width: 1100px; margin: 0 auto 5rem; padding: 0 1.25rem;
        }
        .section-title {
            text-align: center; font-size: 1.2rem; font-weight: 800;
            color: var(--dark); margin-bottom: 1.5rem;
        }
        .compare-table {
            width: 100%; border-collapse: collapse;
            background: #fff; border-radius: 14px; overflow: hidden;
            box-shadow: 0 2px 14px rgba(0,0,0,.06); font-size: .82rem;
        }
        .compare-table thead th {
            padding: 1rem .75rem; font-size: .7rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: .07em; border-bottom: 2px solid #F3F4F6;
        }
        .compare-table thead th:first-child { text-align: left; padding-left: 1.25rem; color: #374151; }
        .compare-table thead th:not(:first-child) { text-align: center; min-width: 100px; }
        .compare-table thead th.th-pro { background: var(--purp-l); color: var(--purple); border-bottom-color: #C4B5FD; }
        .compare-table thead th.th-ent { background: #111827; color: #F9FAFB; border-bottom-color: #374151; }
        .cat-row td { padding: .45rem .75rem .35rem 1.25rem; background: #F9FAFB; border-top: 1px solid #F3F4F6; font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: #9CA3AF; }
        .compare-table tbody tr:not(.cat-row) td { padding: .55rem .75rem; border-bottom: 1px solid #F8F9FA; }
        .compare-table tbody tr:not(.cat-row) td:first-child { padding-left: 1.25rem; color: #374151; }
        .compare-table tbody tr:not(.cat-row) td:not(:first-child) { text-align: center; }
        .compare-table tbody tr:not(.cat-row):hover td { background: #FAFAFA; }
        .compare-table .c-check { color: #059669; font-size: 1rem; font-weight: 800; }
        .compare-table .c-pro-check { color: var(--purple); font-size: 1rem; font-weight: 800; }
        .compare-table .c-cross { color: #E5E7EB; }
        .compare-table .c-val { font-weight: 700; color: #111827; }
        .compare-table .c-unl { font-weight: 700; color: #059669; font-size: .75rem; }

        /* ── CTA banner ───────────────────────────────────────── */
        .cta-banner {
            background: linear-gradient(135deg, #1B4332, #2D6A4F);
            color: #fff; text-align: center; padding: 3.5rem 1.5rem;
        }
        .cta-banner h2 { font-size: 1.6rem; font-weight: 900; margin-bottom: .6rem; }
        .cta-banner p  { font-size: .92rem; color: rgba(255,255,255,.72); margin-bottom: 1.75rem; max-width: 440px; margin-left: auto; margin-right: auto; }
        .cta-btns { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .cta-btn  { padding: .7rem 1.6rem; border-radius: 10px; font-weight: 700; font-size: .9rem; text-decoration: none; }
        .cta-btn-w { background: #fff; color: var(--green-d); }
        .cta-btn-g { background: rgba(255,255,255,.12); color: #fff; border: 1.5px solid rgba(255,255,255,.28); }
        .cta-btn:hover { opacity: .88; }
        .pay-methods { margin-top: 1.25rem; font-size: .76rem; color: rgba(255,255,255,.45); }

        /* ── Footer ───────────────────────────────────────────── */
        footer { text-align: center; padding: 1.5rem; font-size: .77rem; color: #9CA3AF; border-top: 1px solid #F3F4F6; }
        footer a { color: inherit; }
    </style>
</head>
<body>

<!-- Nav -->
<nav class="top-nav">
    <a href="/" class="nav-brand">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <?= e(APP_NAME) ?>
    </a>
    <div class="nav-links">
        <a href="/" class="nav-link">Home</a>
        <?php if ($logged_in): ?>
        <a href="/dashboard.php" class="nav-btn nav-fill">Dashboard</a>
        <?php else: ?>
        <a href="/login.php"    class="nav-btn nav-outline">Sign In</a>
        <a href="/register.php" class="nav-btn nav-fill" style="background:var(--green);color:#fff">Get Started Free</a>
        <?php endif; ?>
    </div>
</nav>

<!-- Hero -->
<section class="hero">
    <div class="hero-pill">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        Simple, Transparent Pricing
    </div>
    <h1>Plans that grow with<br><em>your farm</em></h1>
    <p class="hero-sub">Start free, scale up when you're ready. No hidden fees. Cancel anytime.</p>

    <!-- Billing toggle -->
    <div class="toggle-wrap">
        <span class="toggle-label active" id="lbl-monthly">Monthly</span>
        <label class="toggle-switch">
            <input type="checkbox" id="billing-toggle" onchange="switchBilling(this.checked)">
            <span class="toggle-track"></span>
            <span class="toggle-thumb"></span>
        </label>
        <span class="toggle-label" id="lbl-yearly">
            Yearly &nbsp;<span class="save-pill">Save 20%</span>
        </span>
    </div>
</section>

<!-- Plan Cards -->
<div class="cards-wrap">
<?php
$plan_defs = [
    'Free' => [
        'color'   => '#6B7280',
        'tagline' => 'Try it free, no card needed.',
        'cta_text'=> 'Get Started',
        'cta_cls' => 'cta-gray',
        'ribbon'  => null,
        'card_cls'=> '',
        'name_color' => '#6B7280',
        'features'=> [
            [true,  'Basic Farm Management'],
            [true,  'Finance Module'],
            [true,  'Feed & Medicine Tracking'],
            [true,  'Export Data'],
            [false, 'Reports Dashboard'],
            [false, 'Milk Analytics'],
            [false, 'Payroll Management'],
        ],
    ],
    'Starter' => [
        'color'   => '#0284C7',
        'tagline' => 'For small farms getting organised.',
        'cta_text'=> 'Choose Starter',
        'cta_cls' => 'cta-blue',
        'ribbon'  => null,
        'card_cls'=> '',
        'name_color' => '#0284C7',
        'features'=> [
            [true,  'Everything in Free'],
            [true,  'Reports Dashboard'],
            [true,  'Task Management'],
            [true,  'Inventory Management'],
            [true,  'Manager Role'],
            [false, 'Milk Analytics'],
            [false, 'Payroll Management'],
        ],
    ],
    'Pro' => [
        'color'   => '#7C3AED',
        'tagline' => 'Best for growing farm businesses.',
        'cta_text'=> 'Upgrade Now',
        'cta_cls' => 'cta-purple',
        'ribbon'  => '⭐ Most Popular',
        'card_cls'=> 'card-pro',
        'name_color' => '#7C3AED',
        'features'=> [
            [true,  'Everything in Starter'],
            [true,  'Milk Analytics'],
            [true,  'Payroll Management'],
            [true,  'Breeding Management'],
            [true,  'Accountant & Vet Roles'],
            [true,  'Advanced Analytics'],
            [true,  'Financial Reports'],
        ],
    ],
    'Enterprise' => [
        'color'   => '#6EE7B7',
        'tagline' => 'For large farms & commercial operations.',
        'cta_text'=> 'Contact Sales',
        'cta_cls' => 'cta-white',
        'ribbon'  => null,
        'card_cls'=> 'card-enterprise',
        'name_color' => '#6EE7B7',
        'features'=> [
            [true,  'Everything in Pro'],
            [true,  'Unlimited Everything'],
            [true,  'Priority Support'],
            [true,  'Multi-Branch Management'],
            [true,  'Advanced Reports'],
            [true,  'API Access'],
            [true,  'Custom Branding'],
        ],
    ],
];

foreach ($plan_defs as $pname => $def):
    $p = findPlan($plans, $pname);
    if (!$p) continue;
    $is_current = ($current_plan === $pname);
    $is_free    = (float)$p['price_monthly'] === 0.0;
    $has_offer  = !empty($p['offer_active']) && !empty($p['offer_price']) && (float)$p['offer_price'] > 0;
    $disp       = $has_offer ? (float)$p['offer_price'] : (float)$p['price_monthly'];
    $yearly_mo  = round($disp * 0.8);
    $yearly_total = $yearly_mo * 12;
    $savings_yr   = round($disp * 12 - $yearly_total);
?>
<div class="card <?= $def['card_cls'] ?>" data-plan="<?= e($pname) ?>">

    <?php if ($def['ribbon']): ?>
    <div class="ribbon"><?= e($def['ribbon']) ?></div>
    <?php endif; ?>

    <div class="plan-name" style="color:<?= $def['name_color'] ?>"><?= e($pname) ?></div>
    <div class="plan-tagline"><?= e($def['tagline']) ?></div>

    <!-- Price -->
    <div class="price-row">
        <?php if ($is_free): ?>
        <div class="price-orig">&nbsp;</div>
        <div class="price-amt" style="color:<?= $def['color'] ?>">৳0</div>
        <div class="price-cycle">Forever free</div>
        <div class="price-yearly-note js-yearly-note">&nbsp;</div>

        <?php else: ?>
        <?php if ($has_offer): ?>
        <div class="price-orig">৳<?= number_format((float)$p['price_monthly']) ?>/mo</div>
        <?php else: ?>
        <div class="price-orig js-price-orig" data-monthly="<?= $disp ?>" data-yearly="<?= $yearly_mo ?>"></div>
        <?php endif; ?>
        <div class="price-amt" style="color:<?= $def['color'] ?>">
            <span class="js-price" data-monthly="<?= $disp ?>" data-yearly="<?= $yearly_mo ?>">৳<?= number_format($disp) ?></span>
        </div>
        <div class="price-cycle">
            <span class="js-cycle" data-monthly="/month" data-yearly="/month (billed yearly)">/month</span>
        </div>
        <div class="price-yearly-note js-yearly-note" data-savings="Save ৳<?= number_format($savings_yr) ?>/year">
        </div>
        <?php endif; ?>
    </div>

    <!-- Limits -->
    <div class="limits">
        <?php
        $lim_rows = [
            ['🐄', 'Cows',      $p['cows_limit']],
            ['👷', 'Workers',   $p['workers_limit']],
            ['⚙️', 'Equipment', $p['equipment_limit']],
        ];
        foreach ($lim_rows as [$ico, $lbl, $lim]):
        ?>
        <div class="limit-row">
            <span class="limit-lbl"><?= $ico ?> <?= $lbl ?></span>
            <span class="limit-val <?= $lim === null ? 'lim-unlimited' : '' ?>">
                <?= $lim === null ? '∞ Unlimited' : number_format($lim) ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Features -->
    <ul class="features">
        <?php foreach ($def['features'] as [$on, $feat]): ?>
        <li class="feature-row">
            <span class="<?= $on ? 'f-check' : 'f-cross' ?>"><?= $on ? '✓' : '✗' ?></span>
            <span class="<?= !$on ? 'f-dim' : '' ?>"><?= e($feat) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- CTA -->
    <?php if ($is_current): ?>
    <span class="cta cta-current" style="color:<?= $def['color'] ?>;border-color:<?= $def['color'] ?>">✓ Current Plan</span>
    <?php elseif ($is_free): ?>
    <a href="<?= $logged_in ? '/dashboard.php' : '/register.php' ?>" class="cta <?= $def['cta_cls'] ?>"><?= e($def['cta_text']) ?></a>
    <?php elseif ($pname === 'Enterprise'): ?>
    <a href="mailto:support@abit.com.bd?subject=Enterprise Plan — <?= e(APP_NAME) ?>" class="cta <?= $def['cta_cls'] ?>"><?= e($def['cta_text']) ?></a>
    <?php elseif ($logged_in): ?>
    <a href="/modules/subscription/index.php" class="cta <?= $def['cta_cls'] ?>"><?= e($def['cta_text']) ?></a>
    <?php else: ?>
    <a href="/register.php" class="cta <?= $def['cta_cls'] ?>"><?= e($def['cta_text']) ?></a>
    <?php endif; ?>

</div>
<?php endforeach; ?>
</div>

<!-- Comparison Table -->
<div class="compare-wrap">
    <h2 class="section-title">Everything included, side by side</h2>
    <div style="overflow-x:auto">
    <table class="compare-table">
        <thead>
            <tr>
                <th>Feature</th>
                <th style="color:#6B7280">Free</th>
                <th style="color:#0284C7">Starter</th>
                <th class="th-pro">Pro</th>
                <th class="th-ent">Enterprise</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $table = [
            ['cat' => 'Limits'],
            ['label'=>'Cows',       'Free'=>'20',  'Starter'=>'50', 'Pro'=>'200',       'Enterprise'=>'∞'],
            ['label'=>'Workers',    'Free'=>'3',   'Starter'=>'10', 'Pro'=>'50',        'Enterprise'=>'∞'],
            ['label'=>'Equipment',  'Free'=>'5',   'Starter'=>'25', 'Pro'=>'100',       'Enterprise'=>'∞'],
            ['label'=>'Users',      'Free'=>'3',   'Starter'=>'10', 'Pro'=>'Unlimited', 'Enterprise'=>'∞'],

            ['cat' => 'Core'],
            ['label'=>'Farm Management',     'Free'=>true, 'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Finance Module',      'Free'=>true, 'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Feed & Medicine',     'Free'=>true, 'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Export Data',         'Free'=>true, 'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Task Management',     'Free'=>false,'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Inventory Management','Free'=>false,'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],

            ['cat' => 'Analytics & Reports'],
            ['label'=>'Reports Dashboard',   'Free'=>false,'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Milk Analytics',      'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Advanced Analytics',  'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Financial Reports',   'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Advanced Reports',    'Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],
            ['label'=>'Business Intelligence','Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],

            ['cat' => 'Team & Roles'],
            ['label'=>'Manager Role',        'Free'=>false,'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Accountant Role',     'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Veterinarian Role',   'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Multi-User Access',   'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Advanced RBAC',       'Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],

            ['cat' => 'Advanced Modules'],
            ['label'=>'Breeding Management', 'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Payroll Management',  'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Multi-Branch Mgmt',   'Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],
            ['label'=>'Custom Branding',     'Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],
            ['label'=>'API Access',          'Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],

            ['cat' => 'Support'],
            ['label'=>'Email Support',       'Free'=>true, 'Starter'=>true, 'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Priority Support',    'Free'=>false,'Starter'=>false,'Pro'=>true, 'Enterprise'=>true],
            ['label'=>'Dedicated Account Mgr','Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],
            ['label'=>'SLA Guarantee',       'Free'=>false,'Starter'=>false,'Pro'=>false,'Enterprise'=>true],
        ];
        foreach ($table as $row):
            if (isset($row['cat'])): ?>
            <tr class="cat-row"><td colspan="5"><?= e($row['cat']) ?></td></tr>
            <?php else: ?>
            <tr>
                <td><?= e($row['label']) ?></td>
                <?php foreach (['Free','Starter','Pro','Enterprise'] as $col):
                    $val = $row[$col] ?? false;
                    $is_pro = ($col === 'Pro'); $is_ent = ($col === 'Enterprise');
                ?>
                <td>
                    <?php if (is_bool($val)): ?>
                        <?php if ($val): ?>
                        <span class="<?= $is_pro ? 'c-pro-check' : 'c-check' ?>">✓</span>
                        <?php else: ?>
                        <span class="c-cross">—</span>
                        <?php endif; ?>
                    <?php elseif ($val === '∞'): ?>
                        <span class="c-unl">∞</span>
                    <?php else: ?>
                        <span class="c-val"><?= e($val) ?></span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endif;
        endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- CTA Banner -->
<div class="cta-banner">
    <h2>Ready to run a smarter farm?</h2>
    <p>Join farmers already using <?= e(APP_NAME) ?>. Start free — no credit card required.</p>
    <div class="cta-btns">
        <?php if ($logged_in): ?>
        <a href="/modules/subscription/index.php" class="cta-btn cta-btn-w">Manage Subscription</a>
        <a href="/dashboard.php"                  class="cta-btn cta-btn-g">Go to Dashboard</a>
        <?php else: ?>
        <a href="/register.php"  class="cta-btn cta-btn-w">Get Started Free</a>
        <a href="mailto:support@abit.com.bd?subject=Enterprise Plan Enquiry" class="cta-btn cta-btn-g">Contact Sales</a>
        <?php endif; ?>
    </div>
    <p class="pay-methods">Payment via bKash · Nagad · Rocket · Bank Transfer &nbsp;·&nbsp; support@abit.com.bd</p>
</div>

<footer>
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &nbsp;·&nbsp;
    <a href="/">Home</a> &nbsp;·&nbsp;
    <a href="/login.php">Sign In</a> &nbsp;·&nbsp;
    <a href="/register.php">Register Free</a>
</footer>

<script>
var _isYearly = false;

function switchBilling(yearly) {
    _isYearly = yearly;

    // Toggle label active class
    document.getElementById('lbl-monthly').classList.toggle('active', !yearly);
    document.getElementById('lbl-yearly').classList.toggle('active',  yearly);

    // Update each price
    document.querySelectorAll('.js-price').forEach(function(el) {
        var mo = parseFloat(el.dataset.monthly);
        var yr = parseFloat(el.dataset.yearly);
        if (!mo) return;
        el.textContent = '৳' + Math.round(yearly ? yr : mo).toLocaleString('en-BD');
    });

    // Strikethrough (monthly price shown when yearly active)
    document.querySelectorAll('.js-price-orig').forEach(function(el) {
        var mo = parseFloat(el.dataset.monthly);
        if (!mo) return;
        el.textContent = yearly ? '৳' + Math.round(mo).toLocaleString('en-BD') + '/mo' : '';
    });

    // Cycle label
    document.querySelectorAll('.js-cycle').forEach(function(el) {
        el.textContent = yearly ? el.dataset.yearly : el.dataset.monthly;
    });

    // Savings note
    document.querySelectorAll('.js-yearly-note').forEach(function(el) {
        var savings = el.dataset.savings;
        el.style.display = (yearly && savings) ? 'block' : 'none';
        if (savings) el.textContent = savings;
    });
}
</script>

</body>
</html>
