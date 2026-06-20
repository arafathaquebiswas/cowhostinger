<?php
require_once __DIR__ . '/db.php';

// ── CSRF ──────────────────────────────────────────────────────────────────────

function generateCsrfToken(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION[CSRF_TOKEN_NAME])
        && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generateCsrfToken() . '">';
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ── AUDIT LOG ─────────────────────────────────────────────────────────────────

function auditLog(
    int    $userId,
    string $action,
    string $tableName,
    ?int   $recordId  = null,
    mixed  $oldValue  = null,
    mixed  $newValue  = null
): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'INSERT INTO audit_log
             (user_id, action, table_name, record_id, old_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            strtoupper($action),
            $tableName,
            $recordId,
            $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        error_log('[AUDIT] ' . $e->getMessage());
    }
}

// ── MODULE SETTINGS ───────────────────────────────────────────────────────────

function isModuleEnabled(string $moduleName): bool {
    static $cache = null;
    if ($cache === null) {
        try {
            $db    = getDB();
            $rows  = $db->query('SELECT module_name, is_enabled FROM module_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = $rows ?: [];
        } catch (Exception $e) {
            return true;
        }
    }
    // Unknown modules default to enabled (safe for new modules not yet in DB)
    return isset($cache[$moduleName]) ? (bool)$cache[$moduleName] : true;
}

// ── FLASH MESSAGES ────────────────────────────────────────────────────────────

function flashMessage(string $type, string $message): void {
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage(): ?array {
    if (isset($_SESSION['_flash'])) {
        $flash = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $flash;
    }
    return null;
}

// ── NAVIGATION ────────────────────────────────────────────────────────────────

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// ── FORMATTING ────────────────────────────────────────────────────────────────

function formatCurrency(float $amount, string $symbol = '৳'): string {
    return $symbol . ' ' . number_format($amount, 2);
}

function formatDate(?string $date): string {
    if (!$date) return '—';
    return date('d M Y', strtotime($date));
}

function formatDateTime(?string $datetime): string {
    if (!$datetime) return '—';
    return date('d M Y, H:i', strtotime($datetime));
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    return match (true) {
        $diff < 60     => 'just now',
        $diff < 3600   => floor($diff / 60) . 'm ago',
        $diff < 86400  => floor($diff / 3600) . 'h ago',
        $diff < 604800 => floor($diff / 86400) . 'd ago',
        default        => date('d M Y', strtotime($datetime)),
    };
}

// ── IMAGE UPLOAD ──────────────────────────────────────────────────────────────

function uploadImage(array $file, string $subfolder): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK)           return false;
    if ($file['size'] > UPLOAD_MAX_SIZE)             return false;

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false)                        return false;

    $ext = match ($imageInfo[2]) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if ($ext === null)                               return false;

    $uploadDir = rtrim(UPLOAD_PATH, '/') . '/' . $subfolder . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) return false;

    $filename    = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) return false;

    return '/uploads/' . $subfolder . '/' . $filename;
}

// ── INPUT ─────────────────────────────────────────────────────────────────────

function sanitize(string $value): string {
    return trim(strip_tags($value));
}

// ── API RESPONSES ─────────────────────────────────────────────────────────────

function jsonResponse(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── PAGINATION ────────────────────────────────────────────────────────────────

function paginate(int $total, int $perPage, int $currentPage): array {
    $totalPages  = (int)ceil($total / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset      = ($currentPage - 1) * $perPage;
    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
    ];
}

// ── ALERTS ────────────────────────────────────────────────────────────────────

function createAlert(string $type, string $severity, string $message, ?string $relatedTable = null, ?int $relatedId = null): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'INSERT INTO alerts (type, severity, message, related_table, related_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $severity, $message, $relatedTable, $relatedId]);
    } catch (Exception $e) {
        error_log('[ALERT] ' . $e->getMessage());
    }
}

function getUnreadAlertCount(): int {
    try {
        $db   = getDB();
        $stmt = $db->query('SELECT COUNT(*) FROM alerts WHERE is_read = 0');
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
