<?php

declare(strict_types=1);

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

// Run against a test server: the example creates and removes its own RT table.
$created = false;
$table = null;
$failure = null;
$report = [];
$json = '';

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Run this example with the PHP CLI.');
    }

    // A checkout/archive has its own vendor directory; a Composer dependency uses
    // the consumer's vendor/autoload.php two levels above the package directory.
    if (!class_exists(ManticoreDb::class)) {
        $package = dirname(__DIR__);
        foreach ([$package . '/vendor/autoload.php', $package . '/../../autoload.php'] as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                break;
            }
        }
    }
    if (!class_exists(ManticoreDb::class)) {
        throw new RuntimeException('Composer autoload not found. Run composer install first.');
    }

    $host = getenv('MANTICORE_HOST') ?: '127.0.0.1';
    $port = getenv('MANTICORE_PORT');
    $port = $port === false || $port === '' ? '9306' : $port;
    if (!preg_match('/^[0-9]+$/D', $port) || (int)$port < 1 || (int)$port > 65535) {
        throw new InvalidArgumentException('MANTICORE_PORT must be an integer between 1 and 65535.');
    }
    $username = getenv('MANTICORE_USERNAME');
    $password = getenv('MANTICORE_PASSWORD');
    ManticoreDb::init([
        'defaultConnection' => 'example',
        'connections' => [
            'example' => [
                'host' => $host,
                'port' => (int)$port,
                'username' => $username === false ? null : $username,
                'password' => $password === false ? null : $password,
                'timeout' => 5,
                'prefix' => '',
                'force_prefix' => false,
            ],
        ],
    ]);

    $table = 'example_hybrid_' . bin2hex(random_bytes(8));
    $create = ManticoreDb::table($table)->create(static function (SchemaTable $schema): void {
        $schema->text('title');
        $schema->text('body');
        $schema->integer('stock');
        $schema->json('info');
        $schema->floatVector('embedding', 2, 'l2');
    });
    if (!$create->success()) {
        throw new RuntimeException('Cannot create the example table: ' . $create->error());
    }
    $created = true;

    // Deliberately simple numeric fixtures: no model download or embedding API.
    $documents = (static function (): Generator {
        yield ['id' => 1, 'title' => 'Phone with bright screen', 'body' => 'Text match',
            'stock' => 1, 'info' => ['tags' => ['sale']], 'embedding' => [10, 10]];
        yield ['id' => 2, 'title' => 'Mobile device', 'body' => 'Compact handset',
            'stock' => 1, 'info' => ['tags' => ['sale']], 'embedding' => [0, 1]];
        yield ['id' => 3, 'title' => 'Phone', 'body' => 'Phone for everyday use',
            'stock' => 1, 'info' => ['tags' => ['sale']], 'embedding' => [0, 0]];
        yield ['id' => 4, 'title' => 'Phone unavailable', 'body' => 'Out of stock',
            'stock' => 0, 'info' => ['tags' => ['sale']], 'embedding' => [0, 0]];
        yield ['id' => 5, 'title' => 'Phone without discount', 'body' => 'Regular price',
            'stock' => 1, 'info' => ['tags' => ['regular']], 'embedding' => [0, 0]];
    })();
    $batch = ManticoreDb::table($table)->insertBatch($documents, ['max_rows' => 2]);
    if (!$batch->success()) {
        $cause = $batch->error() ?? $batch->idError();
        throw new RuntimeException('Batch insertion failed after ' . $batch->confirmedRows()
            . ' acknowledged documents: ' . ($cause ? $cause->getMessage() : 'unknown error'));
    }

    $base = ManticoreDb::table($table)->where('stock', 1)->whereJsonContains('info.tags', 'sale');
    $fulltext = $base->clone()->where(static function ($where): void {
        $where->whereFullText('title', 'phone')->orWhereFullText('body', 'handset');
    })->orderBy('id')->get();

    // Compose full-text conditions for the ordinary search above; the hybrid API
    // owns its independent MATCH and KNN branches and receives only common filters.
    $hybrid = $base->hybridSearch('phone', 'embedding', [0, 0], [
        'text_candidates' => 2,
        'vector_candidates' => 2,
        'knn_options' => ['ef' => 50, 'prefilter' => true],
    ]);
    $page = $hybrid->paginateWindow(2, 2);

    // Keep the example reproducible, and fail CI if an installed package regresses.
    if ($batch->confirmedRows() !== 5 || $batch->completedBatches() !== 3
        || array_column($fulltext, 'id') !== [1, 2, 3]
        || array_column($hybrid->rows(), 'id') !== [3, 1, 2]
        || $hybrid->windowTotal() !== 3 || $hybrid->totalFound() !== null
        || array_column($page['data'], 'id') !== [2]) {
        throw new RuntimeException('The example search did not return the expected fixture results.');
    }

    $report = [
        'batch' => ['confirmed_rows' => $batch->confirmedRows(), 'completed_batches' => $batch->completedBatches()],
        'fulltext' => array_values($fulltext),
        'hybrid' => $hybrid->toArray(),
        'page' => $page,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $failure = $error;
} finally {
    // Never drop a pre-existing table or an unacknowledged CREATE attempt.
    if ($created) {
        try {
            $drop = ManticoreDb::table($table)->drop();
            if (!$drop->success()) {
                throw new RuntimeException('Cannot remove the example table ' . $table . ': ' . $drop->error());
            }
        } catch (Throwable $cleanupError) {
            if ($failure !== null) {
                fwrite(STDERR, 'Cleanup failed: ' . $cleanupError->getMessage() . PHP_EOL);
            } else {
                $failure = $cleanupError;
            }
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'Example failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo $json, PHP_EOL;
// No explicit exit(0): a package smoke test can require this example and continue.
return $report;
