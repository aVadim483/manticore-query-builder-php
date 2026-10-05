<?php

declare(strict_types=1);

// Independent of the query builder: establish that the CI service can actually execute
// the features required by the full suite before testing the library itself.
$host = getenv('MANTICORE_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MANTICORE_PORT') ?: 9306);
$timeout = (int)(getenv('MANTICORE_READY_TIMEOUT') ?: 90);
if ($timeout < 1 || $timeout > 120) {
    fwrite(STDERR, "MANTICORE_READY_TIMEOUT must be between 1 and 120 seconds\n");
    exit(1);
}
$deadline = microtime(true) + $timeout;
$table = 'qb_ci_' . bin2hex(random_bytes(8));
$renamed = $table . '_renamed';
$stage = 'SQL connection';
$lastError = '';

do {
    $pdo = null;
    $ready = false;
    try {
        $stage = 'SQL connection';
        $pdo = new PDO("mysql:host={$host};port={$port}", null, null, [
            PDO::ATTR_TIMEOUT => 2,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1')->fetchAll();

        $stage = 'Columnar and KNN table creation';
        $pdo->exec("CREATE TABLE {$table} (title text, price float engine='columnar', "
            . "vec float_vector knn_type='hnsw' knn_dims='2' hnsw_similarity='l2')");
        $pdo->exec("INSERT INTO {$table} (id, title, price, vec) VALUES (1, 'probe', 10, (1,2))");

        $stage = 'Columnar filtering';
        $rows = $pdo->query("SELECT id FROM {$table} WHERE price > 5")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1) {
            throw new RuntimeException('Columnar probe returned unexpected rows');
        }

        $stage = 'KNN search';
        $rows = $pdo->query("SELECT id FROM {$table} WHERE knn(vec, 1, (1,2))")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1) {
            throw new RuntimeException('KNN probe returned unexpected rows');
        }

        $stage = 'Buddy rename';
        $pdo->exec("ALTER TABLE {$table} RENAME {$renamed}");
        $rows = $pdo->query("SELECT id FROM {$renamed}")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1) {
            throw new RuntimeException('Renamed table did not retain the probe document');
        }

        $stage = 'version diagnostics';
        $version = $pdo->query("SHOW STATUS LIKE 'version'")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($version, JSON_UNESCAPED_SLASHES) . "\n";
        $ready = true;
    } catch (Throwable $error) {
        $lastError = $stage . ': ' . $error->getMessage();
        fwrite(STDERR, $lastError . "\n");
    } finally {
        // Both names are ours, even when a failed rename left its outcome uncertain.
        if ($pdo !== null) {
            foreach ([$renamed, $table] as $name) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS {$name}");
                } catch (Throwable $error) {
                    $ready = false;
                    $lastError = 'Probe cleanup: ' . $error->getMessage();
                    fwrite(STDERR, $lastError . "\n");
                }
            }
        }
    }
    if ($ready) {
        echo "SQL, Columnar, KNN and Buddy probes passed\n";
        exit(0);
    }
    if (microtime(true) < $deadline) {
        usleep(500000);
    }
} while (microtime(true) < $deadline);

fwrite(STDERR, "Manticore readiness deadline exceeded: {$lastError}\n");
exit(1);
