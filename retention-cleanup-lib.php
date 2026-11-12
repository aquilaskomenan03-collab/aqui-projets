<?php
declare(strict_types=1);

function mg_ensure_retention_schema(PDO $pdo): void
{
    $columns = $pdo->query('SHOW COLUMNS FROM quote_requests')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('closed_at', $columns, true)) $pdo->exec('ALTER TABLE quote_requests ADD COLUMN closed_at DATETIME NULL');
    if (!in_array('retention_until', $columns, true)) $pdo->exec('ALTER TABLE quote_requests ADD COLUMN retention_until DATETIME NULL');
    if (!in_array('last_activity_at', $columns, true)) $pdo->exec('ALTER TABLE quote_requests ADD COLUMN last_activity_at DATETIME NULL');
    $indexes = $pdo->query('SHOW INDEX FROM quote_requests')->fetchAll(PDO::FETCH_COLUMN, 2);
    if (!in_array('idx_quote_retention_until', $indexes, true)) $pdo->exec('ALTER TABLE quote_requests ADD INDEX idx_quote_retention_until (retention_until)');
    if (!in_array('idx_quote_last_activity', $indexes, true)) $pdo->exec('ALTER TABLE quote_requests ADD INDEX idx_quote_last_activity (last_activity_at)');

    $hasProgressPhotos = (bool)$pdo->query("SHOW TABLES LIKE 'progress_photos'")->fetchColumn();
    if ($hasProgressPhotos) {
        $pdo->exec("UPDATE quote_requests q SET last_activity_at = GREATEST(
            q.created_at,
            COALESCE(q.quote_sent_at, q.created_at),
            COALESCE(q.quote_accepted_at, q.created_at),
            COALESCE(q.payment_submitted_at, q.created_at),
            COALESCE(q.payment_confirmed_at, q.created_at),
            COALESCE((SELECT MAX(p.created_at) FROM progress_photos p WHERE p.lead_id = q.id), q.created_at)
        ) WHERE q.last_activity_at IS NULL");
    } else {
        $pdo->exec("UPDATE quote_requests SET last_activity_at = GREATEST(created_at, COALESCE(quote_sent_at, created_at), COALESCE(quote_accepted_at, created_at), COALESCE(payment_submitted_at, created_at), COALESCE(payment_confirmed_at, created_at)) WHERE last_activity_at IS NULL");
    }

    // Historical terminal records receive a fresh rollout-based deadline so
    // installing the migration never deletes old work unexpectedly.
    $pdo->exec("UPDATE quote_requests SET closed_at = UTC_TIMESTAMP(), retention_until = CASE WHEN status = 'Terminée' THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 YEAR) ELSE DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 YEAR) END WHERE status IN ('Terminée', 'Annulée') AND retention_until IS NULL");
}

function mg_purge_expired_requests(PDO $pdo, bool $dryRun = true): array
{
    $inactive = $pdo->query("SELECT id, status, last_activity_at FROM quote_requests
        WHERE status NOT IN ('Terminée', 'Annulée', 'En cours')
          AND COALESCE(last_activity_at, created_at) <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 MONTH)
        ORDER BY id")->fetchAll();
    $inactiveIds = array_map(static fn($row) => (int)$row['id'], $inactive);

    if (!$dryRun && $inactiveIds) {
        $cancel = $pdo->prepare("UPDATE quote_requests SET status = 'Annulée', closed_at = UTC_TIMESTAMP(),
            retention_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 YEAR), last_activity_at = UTC_TIMESTAMP()
            WHERE id = ? AND status NOT IN ('Terminée', 'Annulée', 'En cours')
              AND COALESCE(last_activity_at, created_at) <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 MONTH)");
        foreach ($inactiveIds as $id) $cancel->execute([$id]);
    }

    $expiredIds = array_map('intval', $pdo->query('SELECT id FROM quote_requests WHERE retention_until IS NOT NULL AND retention_until <= UTC_TIMESTAMP() ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $expiredPhotos = [];
    if ($expiredIds && $pdo->query("SHOW TABLES LIKE 'progress_photos'")->fetchColumn()) {
        foreach (array_chunk($expiredIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $photos = $pdo->prepare("SELECT filename FROM progress_photos WHERE lead_id IN ({$marks})");
            $photos->execute($chunk);
            foreach ($photos->fetchAll(PDO::FETCH_COLUMN) as $filename) $expiredPhotos[] = $filename;
        }
    }

    $orphanAccounts = [];
    if ($pdo->query("SHOW TABLES LIKE 'client_accounts'")->fetchColumn()) {
        $orphanAccounts = array_map('intval', $pdo->query('SELECT id FROM client_accounts WHERE NOT EXISTS (SELECT 1 FROM quote_requests WHERE quote_requests.client_id = client_accounts.id) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
    $expiredTokens = [];
    if ($pdo->query("SHOW TABLES LIKE 'password_reset_tokens'")->fetchColumn()) {
        $expiredTokens = array_map('intval', $pdo->query('SELECT id FROM password_reset_tokens WHERE expires_at <= UTC_TIMESTAMP() ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    $referencedFiles = [];
    if ($pdo->query("SHOW TABLES LIKE 'progress_photos'")->fetchColumn()) {
        foreach ($pdo->query('SELECT filename FROM progress_photos')->fetchAll(PDO::FETCH_COLUMN) as $name) if (is_string($name)) $referencedFiles[$name] = true;
    }
    $orphanFiles = [];
    $privateDir = __DIR__ . '/data/progress';
    if (is_dir($privateDir)) {
        foreach (new DirectoryIterator($privateDir) as $entry) {
            if (!$entry->isFile() || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $entry->getFilename())) continue;
            if ($entry->getMTime() > time() - 86400 || isset($referencedFiles[$entry->getFilename()])) continue;
            $orphanFiles[] = $entry->getFilename();
        }
    }

    $deletedFiles = 0;
    $deletedRequests = 0;
    $deletedAccounts = 0;
    $deletedTokens = 0;
    if (!$dryRun) {
        foreach (array_chunk($expiredIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $delete = $pdo->prepare("DELETE FROM quote_requests WHERE id IN ({$marks}) AND retention_until <= UTC_TIMESTAMP()");
            $delete->execute($chunk);
            $deletedRequests += $delete->rowCount();
        }
        if ($orphanAccounts && $pdo->query("SHOW TABLES LIKE 'client_accounts'")->fetchColumn()) {
            $deleteAccount = $pdo->prepare('DELETE FROM client_accounts WHERE id = ? AND NOT EXISTS (SELECT 1 FROM quote_requests WHERE quote_requests.client_id = client_accounts.id)');
            foreach ($orphanAccounts as $id) { $deleteAccount->execute([$id]); $deletedAccounts += $deleteAccount->rowCount(); }
        }
        if ($expiredTokens && $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'")->fetchColumn()) {
            $deleteToken = $pdo->prepare('DELETE FROM password_reset_tokens WHERE id = ? AND expires_at <= UTC_TIMESTAMP()');
            foreach ($expiredTokens as $id) { $deleteToken->execute([$id]); $deletedTokens += $deleteToken->rowCount(); }
        }

        $candidateFiles = array_unique(array_merge($expiredPhotos, $orphanFiles));
        $isReferenced = $pdo->query("SHOW TABLES LIKE 'progress_photos'")->fetchColumn() ? $pdo->prepare('SELECT 1 FROM progress_photos WHERE filename = ? LIMIT 1') : null;
        foreach ($candidateFiles as $filename) {
            if (!is_string($filename) || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $filename)) continue;
            if ($isReferenced) { $isReferenced->execute([$filename]); if ($isReferenced->fetchColumn()) continue; }
            $path = $privateDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path) && @unlink($path)) $deletedFiles++;
        }
    }

    return [
        'dry_run' => $dryRun,
        'inactive_requests' => $inactiveIds,
        'expired_requests' => $expiredIds,
        'expired_private_photos' => array_values(array_filter($expiredPhotos, static fn($name) => is_string($name) && preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $name))),
        'orphan_private_photos' => $orphanFiles,
        'orphaned_accounts' => $orphanAccounts,
        'expired_reset_tokens' => $expiredTokens,
        'deleted_requests' => $deletedRequests,
        'deleted_private_photos' => $deletedFiles,
        'deleted_orphaned_accounts' => $deletedAccounts,
        'deleted_reset_tokens' => $deletedTokens,
    ];
}
