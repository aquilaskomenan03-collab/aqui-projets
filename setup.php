<?php
declare(strict_types=1);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$installed = is_file(__DIR__ . '/config.php');
$error = '';
$success = '';
if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $host = trim((string)($_POST['db_host'] ?? ''));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $dbPassword = (string)($_POST['db_password'] ?? '');
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $siteBaseUrl = rtrim(trim((string)($_POST['site_base_url'] ?? '')), '/');
    $siteParts = parse_url($siteBaseUrl);
    $siteHost = is_array($siteParts) ? strtolower((string)($siteParts['host'] ?? '')) : '';
    $localSite = is_array($siteParts) && ($siteParts['scheme'] ?? '') === 'http' && in_array($siteHost, ['localhost', '127.0.0.1'], true);
    $secureSite = is_array($siteParts) && ($siteParts['scheme'] ?? '') === 'https' && filter_var($siteBaseUrl, FILTER_VALIDATE_URL);
    $validSiteUrl = $siteParts && ($localSite || $secureSite) && !isset($siteParts['user']) && !isset($siteParts['pass']) && !isset($siteParts['query']) && !isset($siteParts['fragment']) && in_array($siteParts['path'] ?? '', ['', '/'], true);
    if (!$host || !$name || !$user || !$validSiteUrl || strlen($adminPassword) < 12) {
        $error = 'Remplis les champs, indique l’adresse HTTPS exacte du site (localhost accepté en local) et choisis un mot de passe admin d’au moins 12 caractères.';
    } else {
        try {
            $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE TABLE IF NOT EXISTS quote_requests (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, phone VARCHAR(30) NOT NULL, email VARCHAR(254) NULL, service VARCHAR(40) NOT NULL, message TEXT NOT NULL, consent TINYINT(1) NOT NULL DEFAULT 1, consent_at DATETIME NULL, consent_version VARCHAR(20) NULL, status VARCHAR(20) NOT NULL DEFAULT 'Nouvelle', tracking_ref CHAR(12) NULL UNIQUE, access_code_hash VARCHAR(255) NULL, client_id INT UNSIGNED NULL, quote_items LONGTEXT NULL, quote_labor DECIMAL(12,2) NOT NULL DEFAULT 0, quote_extra DECIMAL(12,2) NOT NULL DEFAULT 0, quote_note TEXT NULL, quote_total DECIMAL(12,2) NULL, quote_sent_at DATETIME NULL, quote_accepted_at DATETIME NULL, quote_deposit DECIMAL(12,2) NOT NULL DEFAULT 0, quote_payment_info TEXT NULL, payment_method VARCHAR(30) NULL, payment_reference VARCHAR(120) NULL, payment_submitted_at DATETIME NULL, payment_confirmed_at DATETIME NULL, closed_at DATETIME NULL, retention_until DATETIME NULL, last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, client_decision VARCHAR(20) NOT NULL DEFAULT 'En attente', archived_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_quote_retention_until (retention_until), INDEX idx_quote_last_activity (last_activity_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_photos (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category VARCHAR(30) NOT NULL, title VARCHAR(90) NOT NULL, filename VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_info (`key` VARCHAR(40) PRIMARY KEY, `value` VARCHAR(300) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->prepare('INSERT INTO site_info (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute(['siteBaseUrl', $siteBaseUrl]);
            $pdo->exec("CREATE TABLE IF NOT EXISTS client_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(254) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec('CREATE TABLE IF NOT EXISTS request_limits (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bucket CHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_request_limits_bucket_time (bucket, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $pdo->exec('CREATE TABLE IF NOT EXISTS password_reset_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_password_reset_client (client_id), CONSTRAINT fk_password_reset_client FOREIGN KEY (client_id) REFERENCES client_accounts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $pdo->exec('CREATE TABLE IF NOT EXISTS progress_photos (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, lead_id INT UNSIGNED NOT NULL, title VARCHAR(120) NOT NULL, filename VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_progress_lead_created (lead_id, created_at), CONSTRAINT fk_progress_lead FOREIGN KEY (lead_id) REFERENCES quote_requests(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $config = ['db_host' => $host, 'db_name' => $name, 'db_user' => $user, 'db_password' => $dbPassword, 'admin_password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT)];
            $php = "<?php\n// Identifiants privés : ne partage jamais ce fichier.\nreturn " . var_export($config, true) . ";\n";
            $temp = __DIR__ . '/config.php.new';
            if (file_put_contents($temp, $php, LOCK_EX) === false || !rename($temp, __DIR__ . '/config.php')) throw new RuntimeException('Impossible d’enregistrer config.php. Vérifie les permissions du dossier.');
            if (!is_dir(__DIR__ . '/assets/uploads')) mkdir(__DIR__ . '/assets/uploads', 0755, true);
            $installed = true;
            $success = 'Configuration terminée. Tu peux ouvrir l’administration.';
        } catch (Throwable $exception) {
            if (isset($temp) && is_file($temp)) @unlink($temp);
            error_log('MG setup error: ' . $exception->getMessage());
            $error = 'La connexion ou la configuration a échoué. Vérifie les identifiants MySQL cPanel et les permissions du dossier.';
        }
    }
}
?>
<!doctype html>
<html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuration du site MG</title>
<style>body{margin:0;background:#0b1421;color:#eef4fb;font:16px system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box}.card{width:min(100%,560px);background:#111f31;border:1px solid #263a51;border-radius:18px;padding:32px;box-sizing:border-box}h1{margin-top:0;color:#48a4ff}p{line-height:1.6;color:#c7d3e0}label{display:block;margin:16px 0 6px}input{box-sizing:border-box;width:100%;padding:12px;border-radius:8px;border:1px solid #40546a;background:#0c1725;color:white;font:inherit}button,a{display:inline-block;margin-top:20px;padding:12px 18px;background:#1685ed;color:white;border:0;border-radius:8px;text-decoration:none;font:inherit;cursor:pointer}.error{color:#ffb7a9}.success{color:#a8efbd}.note{font-size:14px;color:#aab9ca}</style>
<main class="card"><h1>MG Multiservice</h1>
<?php if ($installed): ?><p class="success"><?= htmlspecialchars($success ?: 'Ce site est déjà configuré. Cette page ne peut plus modifier ses accès.') ?></p><a href="/admin">Ouvrir l’administration</a>
<?php else: ?><p>Configure la base de données MySQL de ton hébergement et crée le mot de passe de l’administration. Ces informations sont enregistrées sur ton hébergement.</p>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post" autocomplete="off"><label for="site_base_url">Adresse du site (HTTPS)</label><input id="site_base_url" name="site_base_url" type="url" required placeholder="https://sous-domaine.devsitwo.ci"><p class="note">En local seulement : http://localhost:8080</p><label for="db_host">Hôte MySQL</label><input id="db_host" name="db_host" required placeholder="ex. localhost"><label for="db_name">Nom de la base de données</label><input id="db_name" name="db_name" required><label for="db_user">Utilisateur MySQL</label><input id="db_user" name="db_user" required><label for="db_password">Mot de passe MySQL</label><input id="db_password" name="db_password" type="password" required><label for="admin_password">Nouveau mot de passe administrateur (12 caractères minimum)</label><input id="admin_password" name="admin_password" type="password" minlength="12" required><button type="submit">Configurer le site</button></form><p class="note">Tu trouveras les informations MySQL dans cPanel → Bases de données MySQL. Ne partage pas le mot de passe administrateur.</p><?php endif; ?></main></html>
