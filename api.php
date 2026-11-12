<?php
declare(strict_types=1);
require_once __DIR__ . '/retention-cleanup-lib.php';

function respond(int $status, $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(int $maxBytes = 12000): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > $maxBytes) respond(413, ['error' => 'La demande est trop volumineuse.']);
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['error' => 'La demande est illisible.']);
    return $data;
}

function clean_text($value, int $max): string
{
    if (!is_string($value)) return '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) respond(503, ['error' => 'Le site doit être configuré. Ouvre /setup dans ton navigateur.']);
    $config = require $configPath;
    try {
        $pdo = new PDO(
            'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $config['db_user'],
            $config['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (Throwable $error) {
        error_log('MG database connection failed: ' . $error->getMessage());
        respond(503, ['error' => 'La base de données est indisponible. Vérifie la configuration cPanel.']);
    }
    return $pdo;
}

function ensure_tracking_columns(PDO $pdo): void
{
    $columns = $pdo->query('SHOW COLUMNS FROM quote_requests')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tracking_ref', $columns, true)) {
        $pdo->exec('ALTER TABLE quote_requests ADD COLUMN tracking_ref CHAR(12) NULL UNIQUE');
    }
    if (!in_array('access_code_hash', $columns, true)) {
        $pdo->exec('ALTER TABLE quote_requests ADD COLUMN access_code_hash VARCHAR(255) NULL');
    }
    if (!in_array('archived_at', $columns, true)) {
        $pdo->exec('ALTER TABLE quote_requests ADD COLUMN archived_at DATETIME NULL');
    }
    $untracked = $pdo->query('SELECT id FROM quote_requests WHERE tracking_ref IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    $updateReference = $pdo->prepare('UPDATE quote_requests SET tracking_ref = ? WHERE id = ? AND tracking_ref IS NULL');
    foreach ($untracked as $id) $updateReference->execute([strtoupper(bin2hex(random_bytes(6))), (int)$id]);
}

function ensure_client_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    if (!$pdo->query("SHOW TABLES LIKE 'client_accounts'")->fetchColumn()) $pdo->exec("CREATE TABLE client_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(254) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns = $pdo->query('SHOW COLUMNS FROM quote_requests')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('client_id', $columns, true)) $pdo->exec('ALTER TABLE quote_requests ADD COLUMN client_id INT UNSIGNED NULL, ADD INDEX idx_quote_client_id (client_id)');
    $newDecisionColumn = !in_array('client_decision', $columns, true);
    $quoteColumns = [
        'quote_items' => 'LONGTEXT NULL',
        'quote_labor' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'quote_extra' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'quote_note' => 'TEXT NULL',
        'quote_total' => 'DECIMAL(12,2) NULL',
        'quote_sent_at' => 'DATETIME NULL',
        'client_decision' => "VARCHAR(20) NOT NULL DEFAULT 'En attente'",
        'quote_accepted_at' => 'DATETIME NULL',
        'quote_deposit' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'quote_payment_info' => 'TEXT NULL',
        'payment_method' => 'VARCHAR(30) NULL',
        'payment_reference' => 'VARCHAR(120) NULL',
        'payment_submitted_at' => 'DATETIME NULL',
        'payment_confirmed_at' => 'DATETIME NULL',
        'consent_at' => 'DATETIME NULL',
        'consent_version' => 'VARCHAR(20) NULL',
        'closed_at' => 'DATETIME NULL',
        'retention_until' => 'DATETIME NULL',
        'last_activity_at' => 'DATETIME NULL',
    ];
    foreach ($quoteColumns as $column => $definition) if (!in_array($column, $columns, true)) $pdo->exec("ALTER TABLE quote_requests ADD COLUMN {$column} {$definition}");
    if ($newDecisionColumn) $pdo->exec("UPDATE quote_requests SET client_decision = 'Accepté' WHERE status IN ('En cours', 'Terminée')");
    if (!$pdo->query("SHOW TABLES LIKE 'progress_photos'")->fetchColumn()) $pdo->exec("CREATE TABLE progress_photos (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, lead_id INT UNSIGNED NOT NULL, title VARCHAR(120) NOT NULL, filename VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_progress_lead_created (lead_id, created_at), CONSTRAINT fk_progress_lead FOREIGN KEY (lead_id) REFERENCES quote_requests(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    mg_ensure_retention_schema($pdo);
    if (!$pdo->query("SHOW TABLES LIKE 'password_reset_tokens'")->fetchColumn()) $pdo->exec("CREATE TABLE password_reset_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_password_reset_client (client_id), CONSTRAINT fk_password_reset_client FOREIGN KEY (client_id) REFERENCES client_accounts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($pdo->query("SHOW TABLES LIKE 'site_info'")->fetchColumn()) {
        $pdo->prepare("UPDATE site_info SET `value` = 'contact@devsitwo.ci' WHERE `key` = 'publicEmail' AND LOWER(`value`) = 'aquilaskomenan03@gmail.com'")->execute();
    }
    $ready = true;
}

function new_access_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 16; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}

function enforce_rate_limit(PDO $pdo, string $purpose, int $maximum, int $seconds): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS request_limits (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bucket CHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_request_limits_bucket_time (bucket, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $bucket = hash('sha256', $purpose . '|' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . (string)($_SERVER['HTTP_HOST'] ?? 'mg-site'));
    $pdo->prepare('DELETE FROM request_limits WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
    $count = $pdo->prepare('SELECT COUNT(*) FROM request_limits WHERE bucket = ? AND created_at >= ?');
    $count->execute([$bucket, gmdate('Y-m-d H:i:s', time() - $seconds)]);
    if ((int)$count->fetchColumn() >= $maximum) respond(429, ['error' => 'Trop de demandes. Réessaie dans quelques minutes.']);
    $pdo->prepare('INSERT INTO request_limits (bucket, created_at) VALUES (?, UTC_TIMESTAMP())')->execute([$bucket]);
}

function is_admin(): bool
{
    return isset($_SESSION['mg_admin']) && $_SESSION['mg_admin'] === true;
}

function require_admin(): void
{
    if (!is_admin()) respond(401, ['error' => 'Connecte-toi pour accéder à cet espace.']);
}

function site_info(PDO $pdo): array
{
    $info = ['serviceHours' => '', 'serviceArea' => 'Abidjan, Côte d’Ivoire', 'responseTime' => '', 'warranty' => '', 'publicEmail' => 'contact@devsitwo.ci', 'siteBaseUrl' => ''];
    foreach ($pdo->query('SELECT `key`, `value` FROM site_info') as $row) {
        if (array_key_exists($row['key'], $info)) $info[$row['key']] = $row['value'];
    }
    return $info;
}

function site_mail_from(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT `value` FROM site_info WHERE `key` = ?');
    $stmt->execute(['publicEmail']);
    $email = trim((string)$stmt->fetchColumn());
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'contact@devsitwo.ci';
    return 'From: MG Multiservice <' . $email . ">\r\n";
}

function configured_site_base_url(PDO $pdo): ?string
{
    $stmt = $pdo->prepare('SELECT `value` FROM site_info WHERE `key` = ?');
    $stmt->execute(['siteBaseUrl']);
    $value = rtrim(trim((string)$stmt->fetchColumn()), '/');
    if ($value === '') return null;
    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) return null;
    $host = strtolower($parts['host']);
    $localHttp = ($parts['scheme'] ?? '') === 'http' && in_array($host, ['localhost', '127.0.0.1'], true);
    if (($parts['scheme'] ?? '') !== 'https' && !$localHttp) return null;
    return $value;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim((string)($_GET['route'] ?? ''), '/');
$pdo = null;

if (preg_match('#^client/photos/([a-f0-9]{32}\.(?:jpg|png|webp))$#', $route, $photoMatch) && $method === 'GET') {
    if (!isset($_SESSION['mg_client_account_id']) && !isset($_SESSION['mg_client_lead_id'])) respond(401, ['error' => 'Connecte-toi pour voir les photos du chantier.']);
    $pdo = database(); ensure_client_schema($pdo);
    if (isset($_SESSION['mg_client_account_id'])) {
        $photoQuery = $pdo->prepare('SELECT p.filename FROM progress_photos p INNER JOIN quote_requests q ON q.id = p.lead_id WHERE p.filename = ? AND q.client_id = ?');
        $photoQuery->execute([$photoMatch[1], (int)$_SESSION['mg_client_account_id']]);
    } else {
        $photoQuery = $pdo->prepare('SELECT p.filename FROM progress_photos p WHERE p.filename = ? AND p.lead_id = ?');
        $photoQuery->execute([$photoMatch[1], (int)$_SESSION['mg_client_lead_id']]);
    }
    $photo = $photoQuery->fetch();
    $privatePath = __DIR__ . '/data/progress/' . $photoMatch[1];
    if (!$photo || !is_file($privatePath)) respond(404, ['error' => 'Photo introuvable.']);
    $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($privatePath, PATHINFO_EXTENSION)];
    header('Content-Type: ' . $mime); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    readfile($privatePath); exit;
}

if ($route === 'session' && $method === 'GET') respond(200, ['authenticated' => is_admin()]);
if ($route === 'login' && $method === 'POST') {
    $pdo = database(); enforce_rate_limit($pdo, 'admin-login', 8, 300);
    $now = time();
    $_SESSION['login_attempts'] = array_values(array_filter($_SESSION['login_attempts'] ?? [], fn($t) => $now - $t < 60));
    if (count($_SESSION['login_attempts']) >= 8) respond(429, ['error' => 'Trop d’essais. Réessaie dans une minute.']);
    $_SESSION['login_attempts'][] = $now;
    $data = read_json(3000);
    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) respond(503, ['error' => 'Le site doit être configuré. Ouvre /setup dans ton navigateur.']);
    $config = require $configPath;
    if (!password_verify(is_string($data['password'] ?? null) ? $data['password'] : '', $config['admin_password_hash'])) respond(401, ['error' => 'Mot de passe incorrect.']);
    session_regenerate_id(true);
    $_SESSION['mg_admin'] = true;
    $_SESSION['login_attempts'] = [];
    respond(200, ['ok' => true]);
}
if ($route === 'logout' && $method === 'POST') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'], 'domain' => $cookie['domain'], 'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
    respond(200, ['ok' => true]);
}

if ($route === 'client/login' && $method === 'POST') {
    $pdo = database(); enforce_rate_limit($pdo, 'client-login', 8, 300);
    $now = time();
    $_SESSION['client_login_attempts'] = array_values(array_filter($_SESSION['client_login_attempts'] ?? [], fn($t) => $now - $t < 60));
    if (count($_SESSION['client_login_attempts']) >= 8) respond(429, ['error' => 'Trop d’essais. Réessaie dans une minute.']);
    $_SESSION['client_login_attempts'][] = $now;
    $data = read_json(3000);
    $reference = strtoupper(preg_replace('/[^A-F0-9]/', '', clean_text($data['reference'] ?? null, 20)));
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', clean_text($data['access_code'] ?? null, 30)));
    if (strlen($reference) !== 12 || strlen($code) !== 16) respond(401, ['error' => 'Référence ou code incorrect.']);
    ensure_tracking_columns($pdo);
    $stmt = $pdo->prepare('SELECT id, access_code_hash FROM quote_requests WHERE tracking_ref = ?');
    $stmt->execute([$reference]);
    $lead = $stmt->fetch();
    if (!$lead || !$lead['access_code_hash'] || !password_verify($code, $lead['access_code_hash'])) respond(401, ['error' => 'Référence ou code incorrect.']);
    session_regenerate_id(true);
    unset($_SESSION['mg_client_account_id']);
    $_SESSION['mg_client_lead_id'] = (int)$lead['id'];
    $_SESSION['client_login_attempts'] = [];
    respond(200, ['ok' => true]);
}
if ($route === 'client/account/login' && $method === 'POST') {
    $pdo = database(); enforce_rate_limit($pdo, 'client-account-login', 8, 300); ensure_client_schema($pdo);
    $data = read_json(3000);
    $email = strtolower(clean_text($data['email'] ?? null, 254));
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $stmt = $pdo->prepare('SELECT id, password_hash FROM client_accounts WHERE email = ?'); $stmt->execute([$email]); $account = $stmt->fetch();
    if (!$account || !password_verify($password, $account['password_hash'])) respond(401, ['error' => 'Adresse e-mail ou mot de passe incorrect.']);
    session_regenerate_id(true); unset($_SESSION['mg_client_lead_id']); $_SESSION['mg_client_account_id'] = (int)$account['id'];
    respond(200, ['ok' => true]);
}
if ($route === 'client/account/password' && $method === 'PATCH') {
    if (!isset($_SESSION['mg_client_account_id'])) respond(401, ['error' => 'Connecte-toi à ton compte client.']);
    $pdo = database(); ensure_client_schema($pdo); $data = read_json(3000);
    $current = is_string($data['current_password'] ?? null) ? $data['current_password'] : '';
    $new = is_string($data['new_password'] ?? null) ? $data['new_password'] : '';
    if (strlen($new) < 12) respond(400, ['error' => 'Le nouveau mot de passe doit contenir au moins 12 caractères.']);
    $stmt = $pdo->prepare('SELECT password_hash FROM client_accounts WHERE id = ?'); $stmt->execute([(int)$_SESSION['mg_client_account_id']]); $account = $stmt->fetch();
    if (!$account || !password_verify($current, $account['password_hash'])) respond(401, ['error' => 'Le mot de passe actuel est incorrect.']);
    $pdo->prepare('UPDATE client_accounts SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['mg_client_account_id']]);
    respond(200, ['ok' => true]);
}
if ($route === 'client/password-reset/request' && $method === 'POST') {
    $pdo = database(); enforce_rate_limit($pdo, 'client-password-reset', 4, 900); ensure_client_schema($pdo);
    $data = read_json(2000); $email = strtolower(clean_text($data['email'] ?? null, 254));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM client_accounts WHERE email = ?'); $stmt->execute([$email]); $account = $stmt->fetch();
        if ($account) {
            $baseUrl = configured_site_base_url($pdo);
            if (!$baseUrl) {
                error_log('MG password reset not sent: configure the official site URL in admin settings.');
            } else {
                $token = bin2hex(random_bytes(32)); $tokenHash = hash('sha256', $token);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE client_id = ?')->execute([(int)$account['id']]);
                $pdo->prepare('INSERT INTO password_reset_tokens (client_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 30 MINUTE)')->execute([(int)$account['id'], $tokenHash]);
                $link = $baseUrl . '/client#reset=' . rawurlencode($token);
                $subject = 'Réinitialisation du mot de passe — MG Multiservice';
                $body = "Une demande de réinitialisation a été faite pour ton espace client.\n\nOuvre ce lien dans les 30 minutes :\n{$link}\n\nSi tu n’es pas à l’origine de cette demande, ignore cet e-mail.";
                if (!@mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "Content-Type: text/plain; charset=UTF-8\r\n" . site_mail_from($pdo))) error_log('MG password reset email could not be sent.');
            }
        }
    }
    respond(200, ['ok' => true, 'message' => 'Si un compte existe pour cette adresse, un lien de réinitialisation sera envoyé. Vérifie aussi les courriers indésirables.']);
}
if ($route === 'client/password-reset/complete' && $method === 'POST') {
    $pdo = database(); enforce_rate_limit($pdo, 'client-password-reset-complete', 8, 900); ensure_client_schema($pdo);
    $data = read_json(3000); $token = is_string($data['token'] ?? null) ? $data['token'] : ''; $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 12) respond(400, ['error' => 'Lien invalide ou expiré, ou mot de passe de moins de 12 caractères.']);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, client_id FROM password_reset_tokens WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() FOR UPDATE'); $stmt->execute([hash('sha256', $token)]); $reset = $stmt->fetch();
        if (!$reset) { $pdo->rollBack(); respond(400, ['error' => 'Ce lien de réinitialisation est invalide ou expiré. Demande-en un nouveau.']); }
        $pdo->prepare('UPDATE client_accounts SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['client_id']]);
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE client_id = ?')->execute([(int)$reset['client_id']]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    respond(200, ['ok' => true, 'message' => 'Mot de passe modifié. Tu peux maintenant te connecter.']);
}
if ($route === 'client/account/register' && $method === 'POST') {
    $pdo = database(); enforce_rate_limit($pdo, 'client-account-register', 5, 600); ensure_client_schema($pdo);
    $data = read_json(4000);
    $email = strtolower(clean_text($data['email'] ?? null, 254));
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $reference = strtoupper(preg_replace('/[^A-F0-9]/', '', clean_text($data['reference'] ?? null, 20)));
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', clean_text($data['access_code'] ?? null, 30)));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($reference) !== 12 || strlen($code) !== 16) respond(400, ['error' => 'Vérifie l’adresse, choisis un mot de passe de 12 caractères minimum et saisis les codes de suivi.']);
    $leadStmt = $pdo->prepare('SELECT id, email, access_code_hash FROM quote_requests WHERE tracking_ref = ?'); $leadStmt->execute([$reference]); $lead = $leadStmt->fetch();
    if (!$lead || ($lead['email'] && strtolower((string)$lead['email']) !== $email) || !$lead['access_code_hash'] || !password_verify($code, $lead['access_code_hash'])) respond(401, ['error' => 'Adresse e-mail, référence ou code incorrect.']);
    $existingAccount = $pdo->prepare('SELECT id FROM client_accounts WHERE LOWER(email) = ? LIMIT 1');
    $existingAccount->execute([$email]);
    if ($existingAccount->fetchColumn()) respond(409, ['error' => 'Cette adresse e-mail possède déjà un espace client. Connectez-vous sur /client avec le mot de passe de ce compte.']);
    try {
        $pdo->beginTransaction();
        $existingAccount = $pdo->prepare('SELECT id FROM client_accounts WHERE LOWER(email) = ? LIMIT 1 FOR UPDATE');
        $existingAccount->execute([$email]);
        if ($existingAccount->fetchColumn()) {
            $pdo->rollBack();
            respond(409, ['error' => 'Cette adresse e-mail possède déjà un espace client. Connectez-vous sur /client avec le mot de passe de ce compte.']);
        }
        $insert = $pdo->prepare('INSERT INTO client_accounts (email, password_hash) VALUES (?, ?)');
        $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        $accountId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE quote_requests SET email = ?, last_activity_at = UTC_TIMESTAMP() WHERE id = ? AND (email IS NULL OR email = ?)')->execute([$email, (int)$lead['id'], '']);
        $link = $pdo->prepare('UPDATE quote_requests SET client_id = ?, last_activity_at = UTC_TIMESTAMP() WHERE email = ? AND client_id IS NULL');
        $link->execute([$accountId, $email]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode() === '23000') respond(409, ['error' => 'Un compte existe déjà avec cette adresse. Connecte-toi avec ton mot de passe.']);
        throw $error;
    }
    session_regenerate_id(true); unset($_SESSION['mg_client_lead_id']); $_SESSION['mg_client_account_id'] = $accountId;
    respond(201, ['ok' => true]);
}
if ($route === 'client/session' && $method === 'GET') {
    if (!isset($_SESSION['mg_client_lead_id']) && !isset($_SESSION['mg_client_account_id'])) respond(200, ['authenticated' => false]);
    $pdo = database();
    ensure_tracking_columns($pdo);
    ensure_client_schema($pdo);
    if (isset($_SESSION['mg_client_account_id'])) {
        $stmt = $pdo->prepare('SELECT id, name, phone, email, tracking_ref, service, message, status, created_at, quote_items, quote_labor, quote_extra, quote_note, quote_total, quote_sent_at, client_decision, quote_accepted_at, quote_deposit, quote_payment_info, payment_method, payment_reference, payment_submitted_at, payment_confirmed_at FROM quote_requests WHERE client_id = ? ORDER BY id DESC');
        $stmt->execute([(int)$_SESSION['mg_client_account_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT id, name, phone, email, tracking_ref, service, message, status, created_at, quote_items, quote_labor, quote_extra, quote_note, quote_total, quote_sent_at, client_decision, quote_accepted_at, quote_deposit, quote_payment_info, payment_method, payment_reference, payment_submitted_at, payment_confirmed_at FROM quote_requests WHERE id = ?');
        $stmt->execute([(int)$_SESSION['mg_client_lead_id']]);
    }
    $jobs = $stmt->fetchAll();
    if (!$jobs) { unset($_SESSION['mg_client_lead_id'], $_SESSION['mg_client_account_id']); respond(200, ['authenticated' => false]); }
    $photoStmt = $pdo->prepare('SELECT title, filename, created_at FROM progress_photos WHERE lead_id = ? ORDER BY id DESC');
    foreach ($jobs as &$job) {
        $job['request_id'] = (int)$job['id'];
        $job['quote_items'] = $job['quote_items'] ? (json_decode($job['quote_items'], true) ?: []) : [];
        if ($job['client_decision'] !== 'Accepté') $job['quote_payment_info'] = null;
        $photoStmt->execute([(int)$job['id']]);
        $job['photos'] = array_map(static fn($photo) => ['title' => $photo['title'], 'created_at' => $photo['created_at'], 'url' => '/api/client/photos/' . rawurlencode($photo['filename'])], $photoStmt->fetchAll());
        unset($job['id']);
    }
    unset($job);
    respond(200, ['authenticated' => true, 'jobs' => $jobs]);
}
if ($route === 'client/quote-decision' && $method === 'POST') {
    if (!isset($_SESSION['mg_client_lead_id']) && !isset($_SESSION['mg_client_account_id'])) respond(401, ['error' => 'Connecte-toi pour répondre au devis.']);
    $pdo = database(); ensure_client_schema($pdo); $data = read_json(2000);
    $leadId = filter_var($data['lead_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $decision = $data['decision'] ?? null;
    if (!$leadId || !in_array($decision, ['Accepter', 'Refuser'], true)) respond(400, ['error' => 'Réponse au devis invalide.']);
    $newDecision = $decision === 'Accepter' ? 'Accepté' : 'Refusé';
    $newStatus = $decision === 'Accepter' ? 'Devis accepté' : 'Devis refusé';
    if (isset($_SESSION['mg_client_account_id'])) {
        $update = $pdo->prepare("UPDATE quote_requests SET client_decision = ?, status = ?, quote_accepted_at = CASE WHEN ? = 'Accepté' THEN UTC_TIMESTAMP() ELSE NULL END, last_activity_at = UTC_TIMESTAMP() WHERE id = ? AND client_id = ? AND quote_sent_at IS NOT NULL AND client_decision = 'En attente'");
        $update->execute([$newDecision, $newStatus, $newDecision, $leadId, (int)$_SESSION['mg_client_account_id']]);
    } else {
        if ((int)$leadId !== (int)$_SESSION['mg_client_lead_id']) respond(403, ['error' => 'Ce devis ne correspond pas à ton accès client.']);
        $update = $pdo->prepare("UPDATE quote_requests SET client_decision = ?, status = ?, quote_accepted_at = CASE WHEN ? = 'Accepté' THEN UTC_TIMESTAMP() ELSE NULL END, last_activity_at = UTC_TIMESTAMP() WHERE id = ? AND quote_sent_at IS NOT NULL AND client_decision = 'En attente'");
        $update->execute([$newDecision, $newStatus, $newDecision, $leadId]);
    }
    if (!$update->rowCount()) respond(409, ['error' => 'Ce devis n’est plus en attente de réponse ou ne correspond pas à ton compte.']);
    $details = $pdo->prepare('SELECT tracking_ref, name, quote_accepted_at FROM quote_requests WHERE id = ?'); $details->execute([$leadId]); $job = $details->fetch();
    $notify = $pdo->prepare('SELECT `value` FROM site_info WHERE `key` = ?'); $notify->execute(['notificationEmail']); $notificationEmail = (string)$notify->fetchColumn();
    if ($notificationEmail !== '' && filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = "Réponse client au devis {$job['tracking_ref']}";
        $acceptedAt = $job['quote_accepted_at'] ? "\nDate/heure d’acceptation (UTC) : {$job['quote_accepted_at']}" : '';
        $body = "{$job['name']} a répondu « {$newDecision} » au devis {$job['tracking_ref']}.{$acceptedAt}\n\nConnecte-toi à l’administration pour consulter le dossier.";
        @mail($notificationEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "Content-Type: text/plain; charset=UTF-8\r\n" . site_mail_from($pdo));
    }
    respond(200, ['ok' => true, 'status' => $newStatus]);
}
if ($route === 'client/advance-payment' && $method === 'POST') {
    if (!isset($_SESSION['mg_client_lead_id']) && !isset($_SESSION['mg_client_account_id'])) respond(401, ['error' => 'Connecte-toi à ton espace client.']);
    $pdo = database(); ensure_client_schema($pdo); $data = read_json(2000);
    $leadId = filter_var($data['lead_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $methodName = clean_text($data['method'] ?? null, 30); $reference = clean_text($data['reference'] ?? null, 120);
    if (!$leadId || !in_array($methodName, ['Wave', 'Orange Money', 'MTN MoMo', 'Moov Money'], true) || $reference === '') respond(400, ['error' => 'Indique le moyen de paiement et la référence de transaction.']);
    $where = isset($_SESSION['mg_client_account_id']) ? 'client_id = ?' : 'id = ?'; $owner = isset($_SESSION['mg_client_account_id']) ? (int)$_SESSION['mg_client_account_id'] : (int)$_SESSION['mg_client_lead_id'];
    $check = $pdo->prepare("SELECT quote_payment_info FROM quote_requests WHERE id = ? AND {$where} AND client_decision = 'Accepté' AND quote_deposit > 0 AND payment_confirmed_at IS NULL");
    $check->execute([$leadId, $owner]); $payment = $check->fetch();
    $allowedPaymentInfo = $payment ? (json_decode((string)$payment['quote_payment_info'], true) ?: []) : [];
    if (!$payment || !array_key_exists($methodName, $allowedPaymentInfo)) respond(409, ['error' => 'Ce moyen de paiement n’est pas proposé pour ce devis ou le paiement est déjà confirmé.']);
    $stmt = $pdo->prepare("UPDATE quote_requests SET payment_method = ?, payment_reference = ?, payment_submitted_at = UTC_TIMESTAMP(), last_activity_at = UTC_TIMESTAMP() WHERE id = ? AND {$where} AND client_decision = 'Accepté' AND quote_deposit > 0 AND payment_confirmed_at IS NULL");
    $stmt->execute([$methodName, $reference, $leadId, $owner]);
    if (!$stmt->rowCount()) respond(409, ['error' => 'L’avance n’est pas requise, le devis n’est pas accepté, ou un paiement est déjà confirmé.']);
    $jobStmt = $pdo->prepare('SELECT name, tracking_ref, quote_deposit FROM quote_requests WHERE id = ?'); $jobStmt->execute([$leadId]); $job = $jobStmt->fetch();
    $notify = $pdo->prepare('SELECT `value` FROM site_info WHERE `key` = ?'); $notify->execute(['notificationEmail']); $notificationEmail = (string)$notify->fetchColumn();
    if ($notificationEmail !== '' && filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = "Avance à vérifier — devis {$job['tracking_ref']}";
        $body = "{$job['name']} a signalé un paiement d’avance.\nMontant attendu : " . number_format((float)$job['quote_deposit'], 0, ',', ' ') . " FCFA\nMoyen : {$methodName}\nRéférence déclarée : {$reference}\n\nVérifie le compte opérateur avant de confirmer dans l’administration.";
        @mail($notificationEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "Content-Type: text/plain; charset=UTF-8\r\n" . site_mail_from($pdo));
    }
    respond(200, ['ok' => true, 'message' => 'Référence transmise. L’entreprise vérifiera la réception avant de confirmer le démarrage.']);
}
if ($route === 'client/logout' && $method === 'POST') {
    unset($_SESSION['mg_client_lead_id'], $_SESSION['mg_client_account_id']);
    session_regenerate_id(true);
    respond(200, ['ok' => true]);
}

$pdo = database();
ensure_tracking_columns($pdo);
ensure_client_schema($pdo);
$uploadDir = __DIR__ . '/assets/uploads';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) respond(500, ['error' => 'Le dossier des photos ne peut pas être créé.']);

if ($route === 'site-info' && $method === 'GET') respond(200, site_info($pdo));
if ($route === 'admin/site-info' && $method === 'GET') {
    require_admin();
    $info = site_info($pdo);
    $stmt = $pdo->prepare('SELECT `value` FROM site_info WHERE `key` = ?'); $stmt->execute(['notificationEmail']);
    $info['notificationEmail'] = (string)($stmt->fetchColumn() ?: '');
    respond(200, $info);
}
if ($route === 'photos' && $method === 'GET') {
    $rows = $pdo->query('SELECT id, category, title, filename, created_at FROM portfolio_photos ORDER BY id DESC')->fetchAll();
    foreach ($rows as &$photo) $photo['url'] = '/assets/uploads/' . rawurlencode($photo['filename']);
    respond(200, $rows);
}
if ($route === 'leads' && $method === 'POST') {
    enforce_rate_limit($pdo, 'quote-form', 5, 600);
    $data = read_json();
    if (clean_text($data['website'] ?? null, 200) !== '') respond(400, ['error' => 'La demande n’a pas pu être envoyée.']);
    $name = clean_text($data['name'] ?? null, 100);
    $phone = clean_text($data['phone'] ?? null, 30);
    $email = clean_text($data['email'] ?? null, 254);
    $service = clean_text($data['service'] ?? null, 40);
    $message = clean_text($data['message'] ?? null, 2000);
    $createAccount = ($data['create_account'] ?? '') === 'on';
    $accountPassword = is_string($data['account_password'] ?? null) ? $data['account_password'] : '';
    if (!$name || !$phone || !$message || !in_array($service, ['Menuiserie', 'Peinture', 'Vernissage', 'Autre demande'], true) || ($data['consent'] ?? '') !== 'on') respond(400, ['error' => 'Vérifiez les champs obligatoires et votre consentement.']);
    if (!preg_match('/^[+\d() .-]{7,30}$/', $phone)) respond(400, ['error' => 'Le numéro de téléphone semble invalide.']);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'L’adresse e-mail semble invalide.']);
    if ($createAccount && (!$email || strlen($accountPassword) < 12)) respond(400, ['error' => 'Pour créer un compte, indique un e-mail valide et un mot de passe de 12 caractères minimum.']);
    $reference = strtoupper(bin2hex(random_bytes(6)));
    $accessCode = new_access_code();
    $accountId = null;
    if ($email !== '') {
        $accountStmt = $pdo->prepare('SELECT id FROM client_accounts WHERE email = ?'); $accountStmt->execute([strtolower($email)]); $accountId = $accountStmt->fetchColumn();
    }
    $accountExists = (bool)$accountId;
    if ($createAccount && $accountExists) $createAccount = false;
    try {
        $pdo->beginTransaction();
        if ($createAccount) {
            $accountInsert = $pdo->prepare('INSERT INTO client_accounts (email, password_hash) VALUES (?, ?)');
            $accountInsert->execute([strtolower($email), password_hash($accountPassword, PASSWORD_DEFAULT)]);
            $accountId = (int)$pdo->lastInsertId();
        }
        if ($accountId) {
            $linkOld = $pdo->prepare('UPDATE quote_requests SET client_id = ?, last_activity_at = UTC_TIMESTAMP() WHERE email = ? AND client_id IS NULL');
            $linkOld->execute([(int)$accountId, strtolower($email)]);
        }
        $stmt = $pdo->prepare("INSERT INTO quote_requests (name, phone, email, service, message, consent, consent_at, consent_version, tracking_ref, access_code_hash, client_id, last_activity_at) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), 'v2-2026-10', ?, ?, ?, UTC_TIMESTAMP())");
        $stmt->execute([$name, $phone, $email ?: null, $service, $message, $reference, password_hash($accessCode, PASSWORD_DEFAULT), $accountId ?: null]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode() === '23000') respond(409, ['error' => 'Un compte existe déjà pour cette adresse. Connecte-toi à ton espace client avant de continuer.']);
        throw $error;
    }
    $notify = $pdo->prepare('SELECT `value` FROM site_info WHERE `key` = ?');
    $notify->execute(['notificationEmail']);
    $notificationEmail = (string)$notify->fetchColumn();
    if ($notificationEmail !== '' && filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Nouvelle demande de devis — MG Multiservice';
        $body = "Nouvelle demande reçue sur le site.\n\nNom : {$name}\nTéléphone : {$phone}\nE-mail : " . ($email ?: 'non renseigné') . "\nService : {$service}\nRéférence : {$reference}\n\nDescription :\n{$message}\n\nConnecte-toi à l’espace admin pour traiter la demande.";
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n" . site_mail_from($pdo);
        if (!@mail($notificationEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers)) error_log('MG notification email could not be sent.');
    }
    if ($createAccount) { session_regenerate_id(true); $_SESSION['mg_client_account_id'] = (int)$accountId; }
    respond(201, ['ok' => true, 'reference' => $reference, 'access_code' => $accessCode, 'account_created' => $createAccount, 'account_exists' => $accountExists]);
}
if ($route === 'leads' && $method === 'GET') {
    require_admin();
    respond(200, $pdo->query('SELECT id, name, phone, email, service, message, status, tracking_ref, client_id, quote_items, quote_labor, quote_extra, quote_note, quote_total, quote_sent_at, client_decision, quote_accepted_at, quote_deposit, quote_payment_info, payment_method, payment_reference, payment_submitted_at, payment_confirmed_at, consent_at, created_at FROM quote_requests WHERE archived_at IS NULL ORDER BY id DESC LIMIT 300')->fetchAll());
}
if ($route === 'admin/dashboard' && $method === 'GET') {
    require_admin();
    $stats = $pdo->query("SELECT
        COALESCE(SUM(status = 'En cours' AND archived_at IS NULL), 0) AS projects_in_progress,
        COALESCE(SUM(CASE WHEN client_decision = 'Accepté' AND quote_accepted_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01 00:00:00') AND quote_accepted_at < DATE_ADD(DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH) THEN quote_total ELSE 0 END), 0) AS accepted_quote_month,
        COALESCE(SUM(CASE WHEN payment_confirmed_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01 00:00:00') AND payment_confirmed_at < DATE_ADD(DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH) THEN quote_deposit ELSE 0 END), 0) AS advances_collected_month,
        COALESCE(SUM(client_decision = 'Accepté'), 0) AS accepted_quotes,
        COALESCE(SUM(client_decision = 'Refusé'), 0) AS refused_quotes
        FROM quote_requests")->fetch();
    $answered = (int)$stats['accepted_quotes'] + (int)$stats['refused_quotes'];
    $stats['acceptance_rate'] = $answered > 0 ? round((int)$stats['accepted_quotes'] * 100 / $answered) : null;
    $pending = $pdo->query("SELECT id, name, phone, email, tracking_ref, quote_sent_at FROM quote_requests WHERE archived_at IS NULL AND status <> 'Annulée' AND client_decision = 'En attente' AND quote_sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) ORDER BY quote_sent_at ASC LIMIT 50")->fetchAll();
    respond(200, ['stats' => $stats, 'pending_quotes' => $pending]);
}
if ($route === 'admin/archives' && $method === 'GET') {
    require_admin();
    respond(200, $pdo->query('SELECT id, name, phone, email, service, message, status, tracking_ref, client_id, quote_total, quote_sent_at, client_decision, quote_accepted_at, quote_deposit, payment_method, payment_reference, payment_submitted_at, payment_confirmed_at, consent_at, created_at, archived_at FROM quote_requests WHERE archived_at IS NOT NULL ORDER BY archived_at DESC, id DESC')->fetchAll());
}
if (preg_match('#^leads/(\d+)/archive$#', $route, $match) && $method === 'POST') {
    require_admin();
    $stmt = $pdo->prepare("UPDATE quote_requests SET archived_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'Terminée' AND archived_at IS NULL");
    $stmt->execute([(int)$match[1]]);
    if (!$stmt->rowCount()) {
        $check = $pdo->prepare('SELECT status, archived_at FROM quote_requests WHERE id = ?');
        $check->execute([(int)$match[1]]); $item = $check->fetch();
        if (!$item) respond(404, ['error' => 'Demande introuvable.']);
        if ($item['archived_at'] !== null) respond(409, ['error' => 'Cette demande est déjà archivée.']);
        respond(409, ['error' => 'Seules les demandes marquées Terminée peuvent être archivées.']);
    }
    respond(200, ['ok' => true]);
}
if (preg_match('#^leads/(\d+)/restore$#', $route, $match) && $method === 'POST') {
    require_admin();
    $stmt = $pdo->prepare('UPDATE quote_requests SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL');
    $stmt->execute([(int)$match[1]]);
    if (!$stmt->rowCount()) {
        $check = $pdo->prepare('SELECT id FROM quote_requests WHERE id = ?');
        $check->execute([(int)$match[1]]);
        if (!$check->fetch()) respond(404, ['error' => 'Demande introuvable.']);
        respond(409, ['error' => 'Cette demande est déjà dans les demandes actives.']);
    }
    respond(200, ['ok' => true]);
}
if (preg_match('#^leads/(\d+)/access-code$#', $route, $match) && $method === 'POST') {
    require_admin();
    $id = (int)$match[1];
    $stmt = $pdo->prepare('SELECT id FROM quote_requests WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) respond(404, ['error' => 'Demande introuvable.']);
    $accessCode = new_access_code();
    $update = $pdo->prepare('UPDATE quote_requests SET access_code_hash = ?, last_activity_at = UTC_TIMESTAMP() WHERE id = ?');
    $update->execute([password_hash($accessCode, PASSWORD_DEFAULT), $id]);
    respond(200, ['access_code' => $accessCode]);
}
if (preg_match('#^admin/leads/(\d+)/account-password$#', $route, $match) && $method === 'POST') {
    require_admin();
    $stmt = $pdo->prepare('SELECT client_id FROM quote_requests WHERE id = ?'); $stmt->execute([(int)$match[1]]); $clientId = $stmt->fetchColumn();
    if (!$clientId) respond(409, ['error' => 'Cette demande n’est pas encore reliée à un compte client.']);
    $temporaryPassword = new_access_code();
    $update = $pdo->prepare('UPDATE client_accounts SET password_hash = ? WHERE id = ?'); $update->execute([password_hash($temporaryPassword, PASSWORD_DEFAULT), (int)$clientId]);
    respond(200, ['temporary_password' => $temporaryPassword]);
}
if (preg_match('#^admin/leads/(\d+)/quote$#', $route, $match) && $method === 'POST') {
    require_admin();
    $leadId = (int)$match[1]; $data = read_json(20000);
    $itemsInput = $data['items'] ?? null;
    if (!is_array($itemsInput) || count($itemsInput) > 30) respond(400, ['error' => 'Ajoute au moins une ligne de matériau ou un coût de main-d’œuvre.']);
    $items = []; $materialsTotal = 0.0;
    foreach ($itemsInput as $item) {
        if (!is_array($item)) continue;
        $name = clean_text($item['name'] ?? null, 120);
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $unitPrice = filter_var($item['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($name === '') continue;
        if ($name === '' || $quantity === false || $unitPrice === false || !is_finite((float)$quantity) || !is_finite((float)$unitPrice) || $quantity <= 0 || $unitPrice < 0) respond(400, ['error' => 'Vérifie le nom, la quantité et le prix de chaque matériau.']);
        $lineTotal = round((float)$quantity * (float)$unitPrice, 2);
        $items[] = ['name' => $name, 'quantity' => (float)$quantity, 'unit_price' => (float)$unitPrice, 'total' => $lineTotal];
        $materialsTotal += $lineTotal;
    }
    $labor = filter_var($data['labor'] ?? 0, FILTER_VALIDATE_FLOAT);
    $extra = filter_var($data['extra'] ?? 0, FILTER_VALIDATE_FLOAT);
    if ($labor === false || $extra === false || !is_finite((float)$labor) || !is_finite((float)$extra) || $labor < 0 || $extra < 0) respond(400, ['error' => 'Les montants de main-d’œuvre et de frais doivent être positifs ou nuls.']);
    $total = round($materialsTotal + (float)$labor + (float)$extra, 2);
    if ($total <= 0 || (!$items && (float)$labor <= 0 && (float)$extra <= 0)) respond(400, ['error' => 'Le devis doit contenir au moins un montant supérieur à zéro.']);
    $note = clean_text($data['note'] ?? null, 2000);
    $deposit = filter_var($data['deposit'] ?? 0, FILTER_VALIDATE_FLOAT);
    if ($deposit === false || !is_finite((float)$deposit) || $deposit < 0 || $deposit > $total) respond(400, ['error' => 'L’avance doit être comprise entre 0 et le total du devis.']);
    $paymentInput = is_array($data['payment_info'] ?? null) ? $data['payment_info'] : [];
    $paymentInfo = [];
    foreach (['Wave', 'Orange Money', 'MTN MoMo', 'Moov Money'] as $provider) {
        $account = clean_text($paymentInput[$provider] ?? null, 120);
        if ($account !== '') $paymentInfo[$provider] = $account;
    }
    if ($deposit > 0 && !$paymentInfo) respond(400, ['error' => 'Renseigne au moins un numéro de paiement mobile money pour proposer une avance.']);
    $leadStmt = $pdo->prepare('SELECT name, email, tracking_ref, client_decision, status FROM quote_requests WHERE id = ?'); $leadStmt->execute([$leadId]); $lead = $leadStmt->fetch();
    if (!$lead) respond(404, ['error' => 'Demande introuvable.']);
    if ($lead['client_decision'] === 'Accepté') respond(409, ['error' => 'Le client a déjà accepté ce devis. Contacte-le avant toute modification.']);
    if ($lead['status'] === 'Annulée') respond(409, ['error' => 'Ce dossier est annulé. Rétablis son statut avant d’envoyer un nouveau devis.']);
    $encodedItems = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $encodedPaymentInfo = json_encode($paymentInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $update = $pdo->prepare("UPDATE quote_requests SET quote_items = ?, quote_labor = ?, quote_extra = ?, quote_note = ?, quote_total = ?, quote_sent_at = UTC_TIMESTAMP(), client_decision = 'En attente', quote_accepted_at = NULL, quote_deposit = ?, quote_payment_info = ?, payment_method = NULL, payment_reference = NULL, payment_submitted_at = NULL, payment_confirmed_at = NULL, status = 'Devis envoyé', last_activity_at = UTC_TIMESTAMP() WHERE id = ?");
    $update->execute([$encodedItems, $labor, $extra, $note ?: null, $total, $deposit, $encodedPaymentInfo, $leadId]);
    if ($lead['email'] && filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        $subject = "Votre devis MG Multiservice — {$lead['tracking_ref']}";
        $lines = ['Votre devis est disponible dans votre espace client.', ''];
        foreach ($items as $item) $lines[] = $item['name'] . ' (' . $item['quantity'] . ' × ' . number_format($item['unit_price'], 0, ',', ' ') . ' FCFA) : ' . number_format($item['total'], 0, ',', ' ') . ' FCFA';
        $lines[] = 'Main-d’œuvre : ' . number_format((float)$labor, 0, ',', ' ') . ' FCFA';
        $lines[] = 'Autres frais : ' . number_format((float)$extra, 0, ',', ' ') . ' FCFA';
        $lines[] = 'TOTAL : ' . number_format($total, 0, ',', ' ') . ' FCFA';
        if ($note) $lines[] = "\nNotes : {$note}";
        if ($deposit > 0) $lines[] = "\nAvance demandée après acceptation : " . number_format($deposit, 0, ',', ' ') . ' FCFA';
        $baseUrl = configured_site_base_url($pdo);
        if ($baseUrl) $lines[] = "\nConnecte-toi sur {$baseUrl}/client pour accepter ou refuser ce devis, puis consulter les informations de paiement de l’avance.";
        else $lines[] = "\nConnecte-toi sur le site MG Multiservice et ouvre ton espace client pour répondre à ce devis.";
        $body = implode("\n", $lines);
        if (!@mail($lead['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "Content-Type: text/plain; charset=UTF-8\r\n" . site_mail_from($pdo))) error_log('MG quote email could not be sent.');
    }
    respond(200, ['ok' => true, 'total' => $total, 'deposit' => $deposit]);
}
if (preg_match('#^admin/leads/(\d+)/confirm-advance$#', $route, $match) && $method === 'POST') {
    require_admin(); $id = (int)$match[1];
    $stmt = $pdo->prepare("UPDATE quote_requests SET payment_confirmed_at = UTC_TIMESTAMP(), last_activity_at = UTC_TIMESTAMP() WHERE id = ? AND client_decision = 'Accepté' AND quote_deposit > 0 AND payment_reference IS NOT NULL AND payment_confirmed_at IS NULL");
    $stmt->execute([$id]);
    if (!$stmt->rowCount()) respond(409, ['error' => 'Aucune avance à confirmer pour ce dossier.']);
    respond(200, ['ok' => true]);
}
if (preg_match('#^leads/(\d+)$#', $route, $match) && $method === 'PATCH') {
    require_admin();
    $data = read_json(2000);
    $allowedStatuses = ['Nouvelle', 'Devis envoyé', 'Devis accepté', 'Devis refusé', 'Contactée', 'En cours', 'Terminée', 'Annulée'];
    if (!in_array($data['status'] ?? null, $allowedStatuses, true)) respond(400, ['error' => 'Statut inconnu.']);
    $current = $pdo->prepare('SELECT status, client_decision FROM quote_requests WHERE id = ?'); $current->execute([(int)$match[1]]); $existing = $current->fetch();
    if (!$existing) respond(404, ['error' => 'Demande introuvable.']);
    if ($data['status'] === 'Devis envoyé' && $data['status'] !== $existing['status']) respond(409, ['error' => 'Prépare puis envoie le devis depuis le formulaire dédié.']);
    if (in_array($data['status'], ['Devis accepté', 'Devis refusé'], true) && $data['status'] !== $existing['status']) respond(409, ['error' => 'Seul le client peut accepter ou refuser son devis depuis son espace.']);
    if (in_array($data['status'], ['En cours', 'Terminée'], true) && $existing['client_decision'] !== 'Accepté') respond(409, ['error' => 'Le client doit accepter le devis avant le démarrage ou la clôture des travaux.']);
    if (in_array($data['status'], ['En cours', 'Terminée'], true)) {
        $advance = $pdo->prepare('SELECT quote_deposit, payment_confirmed_at FROM quote_requests WHERE id = ?'); $advance->execute([(int)$match[1]]); $payment = $advance->fetch();
        if ((float)$payment['quote_deposit'] > 0 && empty($payment['payment_confirmed_at'])) respond(409, ['error' => 'Confirme la réception de l’avance avant de démarrer ou terminer les travaux.']);
    }
    if ($data['status'] === 'Terminée' && $existing['status'] !== 'Terminée') {
        $stmt = $pdo->prepare("UPDATE quote_requests SET status = ?, closed_at = UTC_TIMESTAMP(), retention_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 YEAR), last_activity_at = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$data['status'], (int)$match[1]]);
    } elseif ($data['status'] === 'Annulée' && $existing['status'] !== 'Annulée') {
        $stmt = $pdo->prepare("UPDATE quote_requests SET status = ?, closed_at = UTC_TIMESTAMP(), retention_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 YEAR), last_activity_at = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$data['status'], (int)$match[1]]);
    } elseif (!in_array($data['status'], ['Terminée', 'Annulée'], true) && in_array($existing['status'], ['Terminée', 'Annulée'], true)) {
        $stmt = $pdo->prepare('UPDATE quote_requests SET status = ?, closed_at = NULL, retention_until = NULL, last_activity_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$data['status'], (int)$match[1]]);
    } else {
        $stmt = $pdo->prepare('UPDATE quote_requests SET status = ?, last_activity_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$data['status'], (int)$match[1]]);
    }
    if (!$stmt->rowCount()) {
        $check = $pdo->prepare('SELECT id FROM quote_requests WHERE id = ?'); $check->execute([(int)$match[1]]);
        if (!$check->fetch()) respond(404, ['error' => 'Demande introuvable.']);
    }
    respond(200, ['ok' => true]);
}
if ($route === 'admin/site-info' && $method === 'PATCH') {
    require_admin();
    $data = read_json(5000);
    $limits = ['serviceHours' => 160, 'serviceArea' => 240, 'responseTime' => 160, 'warranty' => 240, 'publicEmail' => 254, 'siteBaseUrl' => 300];
    $stmt = $pdo->prepare('INSERT INTO site_info (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($limits as $key => $limit) if (array_key_exists($key, $data)) {
        $value = clean_text($data[$key], $limit);
        if ($key === 'publicEmail' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'L’adresse e-mail publique n’est pas valide.']);
        if ($key === 'siteBaseUrl' && $value !== '') {
            $parts = parse_url(rtrim($value, '/'));
            $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
            $localHttp = is_array($parts) && ($parts['scheme'] ?? '') === 'http' && in_array($host, ['localhost', '127.0.0.1'], true);
            $secureHttps = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && filter_var(rtrim($value, '/'), FILTER_VALIDATE_URL);
            if (!$parts || (!$localHttp && !$secureHttps) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) respond(400, ['error' => 'Entre l’adresse HTTPS du site, sans chemin (ex. https://sous-domaine.devsitwo.ci). En local, http://localhost:8080 est accepté.']);
            $value = rtrim($value, '/');
        }
        $stmt->execute([$key, $value]);
    }
    if (array_key_exists('notificationEmail', $data)) {
        $notificationEmail = clean_text($data['notificationEmail'], 254);
        if ($notificationEmail !== '' && !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'L’adresse de notification n’est pas valide.']);
        $stmt->execute(['notificationEmail', $notificationEmail]);
    }
    $info = site_info($pdo);
    $notificationStmt = $pdo->prepare('SELECT `value` FROM site_info WHERE `key` = ?');
    $notificationStmt->execute(['notificationEmail']);
    $info['notificationEmail'] = (string)($notificationStmt->fetchColumn() ?: '');
    respond(200, $info);
}
if ($route === 'admin/photos' && $method === 'POST') {
    require_admin();
    $data = read_json(7500000);
    $category = clean_text($data['category'] ?? null, 30);
    $title = clean_text($data['title'] ?? null, 90);
    if (!in_array($category, ['Menuiserie', 'Peinture', 'Vernissage'], true) || !$title || !is_string($data['image'] ?? null) || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data['image'], $match)) respond(400, ['error' => 'Vérifiez le métier, le titre et le format de la photo.']);
    $binary = base64_decode($match[2], true);
    if ($binary === false || strlen($binary) > 5 * 1024 * 1024) respond(413, ['error' => 'La photo doit faire moins de 5 Mo.']);
    $imageInfo = @getimagesizefromstring($binary);
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$imageInfo || !isset($mimeToExt[$imageInfo['mime']])) respond(400, ['error' => 'Le fichier envoyé n’est pas une image valide.']);
    if ((int)$imageInfo[0] * (int)$imageInfo[1] > 40000000) respond(413, ['error' => 'La photo a trop de pixels. Réduis-la avant de la téléverser.']);
    $filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$imageInfo['mime']];
    if (file_put_contents($uploadDir . '/' . $filename, $binary, LOCK_EX) === false) respond(500, ['error' => 'Le dossier de photos ne permet pas l’enregistrement.']);
    try {
        $stmt = $pdo->prepare('INSERT INTO portfolio_photos (category, title, filename) VALUES (?, ?, ?)');
        $stmt->execute([$category, $title, $filename]);
    } catch (Throwable $error) {
        @unlink($uploadDir . '/' . $filename);
        throw $error;
    }
    respond(201, ['ok' => true]);
}
if ($route === 'admin/lead-options' && $method === 'GET') {
    require_admin();
    respond(200, $pdo->query('SELECT id, name, service, tracking_ref, status, quote_items, quote_labor, quote_extra, quote_note, quote_total, quote_sent_at, client_decision, quote_deposit, quote_payment_info FROM quote_requests ORDER BY id DESC LIMIT 500')->fetchAll());
}
if ($route === 'admin/progress-photos' && $method === 'POST') {
    require_admin();
    $data = read_json(7500000);
    $leadId = filter_var($data['lead_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $title = clean_text($data['title'] ?? null, 120);
    if (!$leadId || !$title || !is_string($data['image'] ?? null) || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data['image'], $match)) respond(400, ['error' => 'Choisis un chantier, un titre et une image JPG, PNG ou WebP.']);
    $binary = base64_decode($match[2], true);
    if ($binary === false || strlen($binary) > 5 * 1024 * 1024) respond(413, ['error' => 'La photo doit faire 5 Mo maximum.']);
    $imageInfo = @getimagesizefromstring($binary);
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$imageInfo || !isset($mimeToExt[$imageInfo['mime']])) respond(400, ['error' => 'Le fichier envoyé n’est pas une image valide.']);
    if ((int)$imageInfo[0] * (int)$imageInfo[1] > 40000000) respond(413, ['error' => 'La photo a trop de pixels. Réduis-la avant de la téléverser.']);
    $check = $pdo->prepare('SELECT id FROM quote_requests WHERE id = ?'); $check->execute([(int)$leadId]);
    if (!$check->fetch()) respond(404, ['error' => 'Demande introuvable.']);
    $privateDir = __DIR__ . '/data/progress';
    if (!is_dir($privateDir) && !mkdir($privateDir, 0750, true) && !is_dir($privateDir)) respond(500, ['error' => 'Le dossier privé des photos de chantier ne peut pas être créé.']);
    $filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$imageInfo['mime']];
    if (file_put_contents($privateDir . '/' . $filename, $binary, LOCK_EX) === false) respond(500, ['error' => 'La photo ne peut pas être enregistrée.']);
    try {
        $insert = $pdo->prepare('INSERT INTO progress_photos (lead_id, title, filename) VALUES (?, ?, ?)'); $insert->execute([(int)$leadId, $title, $filename]);
    } catch (Throwable $error) { @unlink($privateDir . '/' . $filename); throw $error; }
    $pdo->prepare('UPDATE quote_requests SET last_activity_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$leadId]);
    respond(201, ['ok' => true]);
}
if (preg_match('#^admin/photos/(\d+)$#', $route, $match) && $method === 'DELETE') {
    require_admin();
    $stmt = $pdo->prepare('SELECT filename FROM portfolio_photos WHERE id = ?'); $stmt->execute([(int)$match[1]]); $photo = $stmt->fetch();
    if (!$photo) respond(404, ['error' => 'Photo introuvable.']);
    $delete = $pdo->prepare('DELETE FROM portfolio_photos WHERE id = ?'); $delete->execute([(int)$match[1]]);
    if (basename($photo['filename']) === $photo['filename']) @unlink($uploadDir . '/' . $photo['filename']);
    respond(200, ['ok' => true]);
}
respond(404, ['error' => 'Adresse API introuvable.']);
