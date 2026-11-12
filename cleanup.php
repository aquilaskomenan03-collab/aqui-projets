<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv, 1);
$unknown = array_values(array_filter($arguments, static fn($arg) => !in_array($arg, ['--dry-run', '--apply', '--help'], true)));
if ($unknown || (in_array('--dry-run', $arguments, true) && in_array('--apply', $arguments, true))) {
    fwrite(STDERR, "Usage : php cleanup.php [--dry-run|--apply|--help]\n");
    exit(2);
}
if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, "Sans option ou avec --dry-run : simulation sans changement de statut ni suppression.\n--apply : annuler les dossiers inactifs et appliquer les purges échues. Sauvegardez d’abord la base et vérifiez la simulation.\n");
    exit(0);
}
$dryRun = !in_array('--apply', $arguments, true);

require_once __DIR__ . '/retention-cleanup-lib.php';
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Site non configuré : config.php absent.\n");
    exit(1);
}
$config = require $configPath;
try {
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $result = mg_purge_expired_requests($pdo, $dryRun);
    fwrite(STDOUT, $dryRun ? "MODE SIMULATION — aucun statut ni fichier ne sera modifié.\n" : "MODE APPLICATION — les changements listés sont appliqués.\n");
    fwrite(STDOUT, 'Dossiers inactifs à classer Annulée après 12 mois : ' . count($result['inactive_requests']) . ' (IDs : ' . implode(', ', $result['inactive_requests']) . ")\n");
    fwrite(STDOUT, 'Dossiers arrivés à échéance : ' . count($result['expired_requests']) . ' (IDs : ' . implode(', ', $result['expired_requests']) . ")\n");
    fwrite(STDOUT, 'Photos privées liées à ces dossiers : ' . count($result['expired_private_photos']) . ' (' . implode(', ', $result['expired_private_photos']) . ")\n");
    fwrite(STDOUT, 'Photos privées orphelines de plus de 24 h : ' . count($result['orphan_private_photos']) . ' (' . implode(', ', $result['orphan_private_photos']) . ")\n");
    fwrite(STDOUT, 'Comptes sans dossier : ' . count($result['orphaned_accounts']) . ' (IDs : ' . implode(', ', $result['orphaned_accounts']) . ")\n");
    fwrite(STDOUT, 'Liens de réinitialisation expirés : ' . count($result['expired_reset_tokens']) . ' (IDs : ' . implode(', ', $result['expired_reset_tokens']) . ")\n");
    if ($dryRun) fwrite(STDOUT, "Relisez cette liste et faites une sauvegarde MySQL avant php cleanup.php --apply.\n");
    else fwrite(STDOUT, sprintf("Résultat : %d dossiers et %d photos supprimés, %d comptes et %d liens nettoyés.\n", $result['deleted_requests'], $result['deleted_private_photos'], $result['deleted_orphaned_accounts'], $result['deleted_reset_tokens']));
} catch (Throwable $error) {
    error_log('MG data-retention cleanup failed: ' . $error->getMessage());
    fwrite(STDERR, "Échec du nettoyage. Vérifiez les journaux PHP et la configuration MySQL.\n");
    exit(1);
}
