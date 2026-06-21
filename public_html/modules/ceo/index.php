<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$year  = (int)date('Y');
$month = (int)date('m');

// ── Farm & subscription KPIs ──────────────────────────────────────────────────
$kpi = $db->query("
    SELECT
        COUNT(DISTINCT f.id)                                                                              AS total_farms,
        SUM(CASE WHEN f.status='active' AND s.status IN ('active','trial') AND (s.is_lifetime=0 OR s.is_lifetime IS NULL) THEN 1 ELSE 0 END) AS active_subs,
        SUM(CASE WHEN s.is_lifetime=1 THEN 1 ELSE 0 END)                                                 AS lifetime,
        SUM(CASE WHEN s.status='trial' THEN 1 ELSE 0 END)                                                AS on_trial,
        SUM(CASE WHEN s.status='expired' THEN 1 ELSE 0 END)                                              AS expired,
        SUM(CASE WHEN f.status='suspended' OR s.status='suspended' THEN 1 ELSE 0 END)                   AS suspended,
        SUM(CASE WHEN p.name='Free' AND s.status IN ('active','trial') AND s.is_lifetime=0 THEN 1 ELSE 0 END) AS free_members,
        SUM(CASE WHEN s.end_date IS NOT NULL AND s.is_lifetime=0
                  AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 10 DAY)
                  AND s.status='active' THEN 1 ELSE 0 END)                                               AS expiring_10,
        SUM(CASE WHEN s.end_date IS NOT NULL AND s.is_lifetime=0
                  AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
                  AND s.status='active' THEN 1 ELSE 0 END)                                               AS expiring_30
    FROM farms f
    LEFT JOIN subscriptions s ON s.farm_id = f.id
    LEFT JOIN plans p ON p.id = s.plan_id
")->fetch();

// ── Revenue ────────────────────────────────────────────────────────────────────
$rev_month = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND MONTH(paid_at)={$month} AND YEAR(paid_at)={$year}")->fetchColumn();
$rev_year  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND YEAR(paid_at)={$year}")->fetchColumn();
$rev_total = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed'")->fetchColumn();
$pending   = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn();

// ── Plan distribution ──────────────────────────────────────────────────────────
$plan_dist = $db->query("
    SELECT p.name, p.price_monthly, COUNT(s.id) AS cnt
    FROM subscriptions s JOIN plans p ON p.id=s.plan_id
    WHERE s.status IN ('active','trial')
    GROUP BY p.name, p.price_monthly ORDER BY p.price_monthly
")->fetchAll();

// ── Farms expiring in 10 / 30 days (split lists) ──────────────────────────────
$expiring_10 = $db->query("
    SELECT f.id AS farm_id, f.name AS farm_name, s.end_date, p.name AS plan_name,
           DATEDIFF(s.end_date, CURDATE()) AS days_left
    FROM farms f JOIN subscriptions s ON s.farm_id=f.id JOIN plans p ON p.id=s.plan_id
    WHERE s.status='active' AND s.is_lifetime=0 AND s.end_date IS NOT NULL
    AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 10 DAY)
    ORDER BY s.end_date ASC LIMIT 15
")->fetchAll();

$expiring_30 = $db->query("
    SELECT f.id AS farm_id, f.name AS farm_name, s.end_date, p.name AS plan_name,
           DATEDIFF(s.end_date, CURDATE()) AS days_left
    FROM farms f JOIN subscriptions s ON s.farm_id=f.id JOIN plans p ON p.id=s.plan_id
    WHERE s.status='active' AND s.is_lifetime=0 AND s.end_date IS NOT NULL
    AND s.end_date BETWEEN DATE_ADD(CURDATE(),INTERVAL 10 DAY) AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
    ORDER BY s.end_date ASC LIMIT 15
")->fetchAll();

// ── Recent CEO grants (upgrades / downgrades) ──────────────────────────────────
$recent_grants = $db->query("
    SELECT cg.*, u.name AS ceo_name
    FROM ceo_grants cg LEFT JOIN users u ON u.id=cg.granted_by
    WHERE cg.farm_name != 'GLOBAL PRICING'
    ORDER BY cg.created_at DESC LIMIT 12
")->fetchAll();

// ── Monthly revenue (last 6 months) for trend ─────────────────────────────────
$rev_trend = $db->query("
    SELECT DATE_FORMAT(paid_at,'%b %Y') AS mo, YEAR(paid_at) AS yr, MONTH(paid_at) AS mn,
           COALESCE(SUM(amount),0) AS total
    FROM payments WHERE status='completed' AND paid_at >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
    GROUP BY yr,mn,mo ORDER BY yr,mn
")->fetchAll();

$page_title = 'CEO Control Center';
$active_nav = 'ceo_control';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

function ৳(float $v): string { return '৳'.number_format($v,2); }
?>

<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div>
        <h2 style="display:flex;align-items:center;gap:.5rem">
            <span style="font-size:1.3rem">👑</span> CEO Control Center
        </h2>
        <p class="text-muted text-sm"><?= date('l, d F Y') ?> — Complete system oversight</p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="/modules/ceo/subscriptions.php" class="btn btn-primary btn-sm">Subscription Manager</a>
        <a href="/modules/ceo/plans.php"         class="btn btn-secondary btn-sm">Plans &amp; Pricing</a>
        <a href="/modules/ceo/audit.php"         class="btn btn-secondary btn-sm">Audit Log</a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ROW 1 — Subscription KPIs
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.85rem;margin-bottom:1.25rem">
<?php
$kpi_cards = [
    ['Total Farms',       $kpi['total_farms'],   '#0284c7', '🏠'],
    ['Active Subs',       $kpi['active_subs'],   '#16a34a', '✅'],
    ['Lifetime Members',  $kpi['lifetime'],      '#7c3aed', '♾'],
    ['Free Members',      $kpi['free_members'],  '#6b7280', '🎁'],
    ['On Trial',          $kpi['on_trial'],      '#d97706', '🔄'],
    ['Expiring 10 Days',  $kpi['expiring_10'],   '#f97316', '⏰'],
    ['Expiring 30 Days',  $kpi['expiring_30'],   '#eab308', '📅'],
    ['Expired',           $kpi['expired'],       '#dc2626', '⛔'],
    ['Suspended',         $kpi['suspended'],     '#b91c1c', '🔒'],
];
foreach ($kpi_cards as [$lbl,$val,$col,$icon]):
    $urgent = in_array($lbl,['Expiring 10 Days','Expired','Suspended']) && $val > 0;
?>
<div class="card" style="border-top:3px solid <?= $col ?>;text-align:center<?= $urgent?';box-shadow:0 0 0 2px '.e($col).'40':'' ?>">
    <div class="card-body" style="padding:.75rem .5rem">
        <div style="font-size:1.3rem"><?= $icon ?></div>
        <div style="font-size:1.55rem;font-weight:800;color:<?= $col ?>"><?= number_format($val) ?></div>
        <div style="font-size:.7rem;color:var(--text-secondary);font-weight:600;line-height:1.2"><?= $lbl ?></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     ROW 2 — Revenue KPIs
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.85rem;margin-bottom:1.5rem">
    <?php foreach ([
        ['This Month Revenue', $rev_month, '#16a34a', '💰', 'From completed payments'],
        ['This Year Revenue',  $rev_year,  '#0284c7', '📊', 'Jan – '.date('M Y')],
        ['All-Time Revenue',   $rev_total, '#7c3aed', '💎', 'Cumulative total'],
        ['Pending Payments',   $pending,   '#d97706', '⏳', 'Awaiting confirmation'],
    ] as [$lbl,$val,$col,$icon,$sub]): ?>
    <div class="card" style="border-left:4px solid <?= $col ?>">
        <div class="card-body" style="padding:.85rem 1rem;display:flex;align-items:center;gap:.85rem">
            <div style="font-size:1.75rem"><?= $icon ?></div>
            <div>
                <div style="font-size:.73rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase"><?= $lbl ?></div>
                <div style="font-size:1.25rem;font-weight:800;color:<?= $col ?>">৳<?= number_format($val, 2) ?></div>
                <div style="font-size:.72rem;color:var(--text-secondary)"><?= $sub ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     ROW 3 — Quick Actions
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:1.5rem">
    <?php foreach ([
        ['/modules/ceo/subscriptions.php',                  'Grant Free Access',     '#16a34a', '🎟️', '1M – Lifetime for any farm'],
        ['/modules/ceo/subscriptions.php?status=lifetime',  'Lifetime Members',      '#7c3aed', '♾',  'Manage lifetime holders'],
        ['/modules/ceo/subscriptions.php?status=expired',   'Reactivate Expired',    '#d97706', '🔁', 'Find & reactivate expired'],
        ['/modules/ceo/subscriptions.php?status=suspended', 'Suspended Farms',       '#dc2626', '🔒', 'View & restore suspended'],
        ['/modules/ceo/plans.php',                          'Change Pricing',        '#0284c7', '💲', 'Edit plan prices & limits'],
        ['/modules/ceo/audit.php',                          'CEO Audit Log',         '#6b7280', '📜', 'Full CEO action history'],
    ] as [$url,$lbl,$col,$icon,$desc]): ?>
    <a href="<?= e($url) ?>" style="display:block;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.9rem;text-decoration:none;transition:all .15s" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 16px rgba(0,0,0,.1)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
        <div style="font-size:1.3rem;margin-bottom:.2rem"><?= $icon ?></div>
        <div style="font-weight:700;color:<?= $col ?>;font-size:.85rem"><?= $lbl ?></div>
        <div style="font-size:.73rem;color:var(--text-secondary);margin-top:.15rem"><?= $desc ?></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     ROW 4 — Expiry Warnings + Plan Distribution
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.25rem;margin-bottom:1.5rem">

    <!-- Expiring in 10 Days -->
    <div class="card">
        <div class="card-header" style="border-left:3px solid #f97316;background:#fff7ed">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <h3 style="margin:0;color:#c2410c">⏰ Expiring in 10 Days (<?= count($expiring_10) ?>)</h3>
                <a href="/modules/ceo/subscriptions.php?status=active" class="btn btn-xs btn-secondary">Manage</a>
            </div>
        </div>
        <?php if (empty($expiring_10)): ?>
        <div class="empty-state" style="padding:1.5rem"><p style="font-size:.85rem">No subscriptions expiring in the next 10 days.</p></div>
        <?php else: ?>
        <div style="padding:.25rem 0">
        <?php foreach ($expiring_10 as $ex):
            $dl = (int)$ex['days_left'];
            $urgency_col = $dl <= 1 ? '#dc2626' : ($dl <= 3 ? '#f97316' : '#d97706');
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.55rem 1rem;border-bottom:1px solid var(--border)">
            <div>
                <div style="font-size:.85rem;font-weight:600"><?= e($ex['farm_name']) ?></div>
                <div style="font-size:.73rem;color:var(--text-secondary)"><?= e($ex['plan_name']) ?> · expires <?= e(date('d M Y', strtotime($ex['end_date']))) ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem">
                <span style="font-size:.8rem;font-weight:700;color:<?= $urgency_col ?>">
                    <?= $dl <= 0 ? 'TODAY' : ($dl === 1 ? 'Tomorrow' : "{$dl} days") ?>
                </span>
                <a href="/modules/ceo/subscriptions.php?search=<?= urlencode($ex['farm_name']) ?>" class="btn btn-xs btn-primary">Grant</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Expiring 11–30 Days + Plan Distribution -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">

        <!-- Expiring 11–30 Days -->
        <div class="card">
            <div class="card-header" style="border-left:3px solid #eab308">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <h3 style="margin:0;color:#92400e">📅 Expiring 11–30 Days (<?= count($expiring_30) ?>)</h3>
                </div>
            </div>
            <?php if (empty($expiring_30)): ?>
            <div style="padding:1rem"><p class="text-muted text-sm">None in this window.</p></div>
            <?php else: ?>
            <div style="padding:.25rem 0">
            <?php foreach (array_slice($expiring_30,0,6) as $ex): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.45rem 1rem;border-bottom:1px solid var(--border)">
                <div>
                    <div style="font-size:.83rem;font-weight:600"><?= e($ex['farm_name']) ?></div>
                    <div style="font-size:.72rem;color:var(--text-secondary)"><?= e($ex['plan_name']) ?> · <?= e(date('d M Y', strtotime($ex['end_date']))) ?></div>
                </div>
                <span style="font-size:.78rem;font-weight:600;color:#92400e"><?= $ex['days_left'] ?>d</span>
            </div>
            <?php endforeach; ?>
            <?php if (count($expiring_30) > 6): ?>
            <div style="padding:.5rem 1rem"><a href="/modules/ceo/subscriptions.php?status=active" class="text-sm" style="color:#0284c7">+<?= count($expiring_30)-6 ?> more →</a></div>
            <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Plan Distribution -->
        <div class="card">
            <div class="card-header"><h3>Plan Distribution (Active)</h3></div>
            <div class="card-body" style="padding:.6rem 1rem">
            <?php
            $total_active = array_sum(array_column($plan_dist,'cnt')) ?: 1;
            foreach ($plan_dist as $pd):
                $bc = match($pd['name']){'Free'=>'#6b7280','Basic'=>'#0284c7','Pro'=>'#7c3aed','Enterprise'=>'#d97706',default=>'#9ca3af'};
                $w  = round($pd['cnt']/$total_active*100);
            ?>
            <div style="display:flex;align-items:center;gap:.6rem;padding:.3rem 0;border-bottom:1px solid var(--border)">
                <span style="width:75px;font-size:.82rem;font-weight:600;color:<?= $bc ?>"><?= e($pd['name']) ?></span>
                <div style="flex:1;height:7px;background:var(--border);border-radius:4px">
                    <div style="height:7px;border-radius:4px;background:<?= $bc ?>;width:<?= $w ?>%"></div>
                </div>
                <span style="width:28px;text-align:right;font-size:.82rem;font-weight:700"><?= $pd['cnt'] ?></span>
                <span style="width:36px;text-align:right;font-size:.73rem;color:var(--text-secondary)"><?= $w ?>%</span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($plan_dist)): ?><p class="text-muted text-sm text-center" style="padding:.75rem">No active subscriptions yet.</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ROW 5 — Recent CEO Actions + Revenue Trend
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.25rem;margin-bottom:1.5rem">

    <!-- Recent CEO Actions -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h3>Recent CEO Actions</h3>
            <a href="/modules/ceo/audit.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <?php if (empty($recent_grants)): ?>
        <div class="empty-state" style="padding:2rem"><p>No CEO actions recorded yet.</p></div>
        <?php else: ?>
        <div style="padding:.25rem 0">
        <?php foreach ($recent_grants as $g):
            $is_lt = (bool)$g['is_lifetime'];
            $badge_bg = match($g['action_type']){
                'lifetime'=>'linear-gradient(135deg,#7c3aed,#db2777)',
                'free_access'=>'#16a34a','plan_change'=>'#0284c7',
                'suspend'=>'#dc2626','reactivate'=>'#d97706',default=>'#6b7280'
            };
        ?>
        <div style="display:flex;gap:.65rem;padding:.55rem 1rem;border-bottom:1px solid var(--border);align-items:flex-start">
            <div style="flex:1;min-width:0">
                <div style="font-size:.84rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($g['farm_name'] ?: 'Farm #'.$g['farm_id']) ?></div>
                <div style="font-size:.76rem;color:var(--text-secondary)">
                    <?= e($g['old_plan_name'] ?: '—') ?> → <strong><?= e($g['new_plan_name'] ?: '—') ?></strong>
                    <?php if ($g['duration_label']): ?> · <?= e($g['duration_label']) ?><?php endif; ?>
                </div>
                <div style="font-size:.71rem;color:var(--text-secondary)"><?= e(formatDateTime($g['created_at'])) ?></div>
            </div>
            <span style="background:<?= $badge_bg ?>;color:#fff;padding:.2rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap;flex-shrink:0">
                <?= $is_lt ? '♾ ' : '' ?><?= ucfirst(str_replace('_',' ',$g['action_type'])) ?>
            </span>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Revenue Trend (last 6 months) -->
    <div class="card">
        <div class="card-header"><h3>Revenue Trend (Last 6 Months)</h3></div>
        <?php if (empty($rev_trend)): ?>
        <div class="empty-state" style="padding:2rem"><p>No payment data recorded yet.</p></div>
        <?php else: ?>
        <div class="card-body" style="padding:.6rem 1rem">
        <?php
        $max_rev = max(array_column($rev_trend,'total')) ?: 1;
        foreach ($rev_trend as $rt):
            $w = round($rt['total']/$max_rev*100);
        ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.3rem 0;border-bottom:1px solid var(--border)">
            <span style="width:65px;font-size:.8rem;color:var(--text-secondary);flex-shrink:0"><?= e($rt['mo']) ?></span>
            <div style="flex:1;height:7px;background:var(--border);border-radius:4px">
                <div style="height:7px;border-radius:4px;background:#16a34a;width:<?= $w ?>%"></div>
            </div>
            <span style="width:80px;text-align:right;font-size:.82rem;font-weight:600;color:#16a34a">৳<?= number_format($rt['total'],2) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     Expiry Warning Rules reminder
══════════════════════════════════════════════════════════════ -->
<div class="card" style="background:#eff6ff;border-color:#bfdbfe;margin-bottom:1.25rem">
    <div class="card-body" style="padding:.9rem 1.1rem">
        <div style="font-weight:700;color:#1d4ed8;margin-bottom:.5rem;font-size:.9rem">⏰ Auto Expiry Warning System — Active</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.4rem;font-size:.8rem;color:#1e3a8a">
            <?php foreach ([
                ['10 Days', 'low',      'First warning — low priority alert'],
                ['7 Days',  'medium',   'Regular warning — medium alert'],
                ['3 Days',  'high',     'Urgent warning — high alert'],
                ['1 Day',   'critical', '"Expires tomorrow" — critical alert'],
                ['Today',   'critical', '"Expired today" — critical alert'],
            ] as [$when,$sev,$desc]): ?>
            <div style="display:flex;gap:.4rem;align-items:baseline">
                <span style="font-weight:700;color:#1d4ed8;min-width:55px"><?= $when ?></span>
                <span style="color:#64748b"><?= $desc ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-xs" style="color:#64748b;margin-top:.5rem">Alerts are automatically created once per day in the farm's notification center. Farm admin and accountant users see these in their Alerts page.</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CEO Security Rules
══════════════════════════════════════════════════════════════ -->
<div class="card" style="border-color:#c4b5fd;background:#f5f3ff">
    <div class="card-body" style="padding:.9rem 1.1rem">
        <div style="font-weight:700;color:#7c3aed;margin-bottom:.5rem;font-size:.9rem">🛡️ Active CEO Security Rules</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.35rem;font-size:.79rem;color:#5b21b6">
            <?php foreach ([
                'CEO role cannot be deleted or downgraded by anyone',
                'Only CEO can assign or revoke Lifetime plans',
                'Only CEO can change global plan pricing',
                'All CEO actions permanently logged in audit trail',
                'CEO bypasses all subscription and RBAC restrictions',
                'Lifetime members cannot be altered by Admins or Staff',
                'CEO can override any plan, expiry, or permission',
                'Admin staff cannot grant free access beyond their scope',
            ] as $rule): ?>
            <div style="display:flex;gap:.35rem;align-items:flex-start">
                <span style="color:#7c3aed;font-weight:700;flex-shrink:0">✓</span>
                <span><?= $rule ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
