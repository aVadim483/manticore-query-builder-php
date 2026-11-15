<?php

declare(strict_types=1);

// Build an isolated consumer manifest from the distribution itself, never from checkout
// metadata. The synthetic version is local to this smoke test and is not published.
try {
    if ($argc !== 3) {
        throw new RuntimeException('Usage: php tests/ci/prepare-package.php <archive.zip> <empty-consumer-directory>');
    }
    $archive = realpath($argv[1]);
    if ($archive === false || !is_file($archive)) {
        throw new RuntimeException('The distribution archive does not exist');
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The archive smoke test requires the PHP zip extension');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot open the distribution archive');
    }
    try {
        $manifests = [];
        for ($position = 0; $position < $zip->numFiles; ++$position) {
            $entry = $zip->getNameIndex($position);
            if ($entry === false || preg_match('~(?:^/|^[a-zA-Z]:|(?:^|/)\.\.(?:/|$))~', $entry)) {
                throw new RuntimeException('The archive contains an invalid entry path');
            }
            if (preg_match('~^(?:[^/]+/)?composer\.json$~D', $entry)) {
                $manifests[] = $entry;
            }
        }
        if (count($manifests) !== 1) {
            throw new RuntimeException('The archive must contain exactly one root composer.json');
        }
        $manifestEntry = $manifests[0];
        $prefix = substr($manifestEntry, 0, -strlen('composer.json'));
        $manifest = json_decode((string)$zip->getFromName($manifestEntry), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['name'] ?? null) !== 'avadim/manticore-query-builder-php'
            || !isset($manifest['require'], $manifest['autoload']['psr-4'])) {
            throw new RuntimeException('The archive has unexpected package metadata');
        }
        foreach (['composer.json', 'LICENSE', 'src/Manticore/QueryBuilder/Query.php',
            'src/Manticore/QueryBuilder/BatchResult.php', 'src/Manticore/QueryBuilder/HybridHit.php',
            'src/Manticore/QueryBuilder/HybridResult.php', 'examples/hybrid-search.php'] as $required) {
            if ($zip->locateName($prefix . $required) === false) {
                throw new RuntimeException('The distribution is missing ' . $required);
            }
        }
        for ($position = 0; $position < $zip->numFiles; ++$position) {
            $entry = substr((string)$zip->getNameIndex($position), strlen($prefix));
            if (preg_match('~^(?:tests|docs|vendor|\.github|\.git|\.agents|\.codex)(?:/|$)~', $entry)
                || in_array($entry, ['AGENTS.md', 'composer.lock', 'phpunit.xml'], true)) {
                throw new RuntimeException('The distribution contains an excluded development file: ' . $entry);
            }
        }
    } finally {
        $zip->close();
    }

    $consumer = $argv[2];
    if (!is_dir($consumer) && !mkdir($consumer, 0777, true) && !is_dir($consumer)) {
        throw new RuntimeException('Cannot create the consumer directory');
    }
    if (array_diff(scandir($consumer) ?: [], ['.', '..'])) {
        throw new RuntimeException('The consumer directory must be empty');
    }
    $archivePath = str_replace('\\', '/', $archive);
    if ($archivePath[0] !== '/') {
        $archivePath = '/' . $archivePath;
    }
    $archiveUrl = 'file://' . preg_replace_callback('#[^a-zA-Z0-9/._~:\-]#', static function (array $match): string {
        return rawurlencode($match[0]);
    }, $archivePath);
    $checksum = sha1_file($archive);
    if ($checksum === false) {
        throw new RuntimeException('Cannot checksum the distribution archive');
    }
    $package = [
        'name' => $manifest['name'],
        'version' => '2.7.0-dev',
        'type' => $manifest['type'] ?? 'library',
        'require' => $manifest['require'],
        'autoload' => $manifest['autoload'],
        'dist' => ['type' => 'zip', 'url' => $archiveUrl, 'reference' => $checksum, 'shasum' => $checksum],
    ];
    if (isset($manifest['license'])) {
        $package['license'] = $manifest['license'];
    }
    $consumerManifest = [
        'name' => 'manticore-tests/distribution-consumer',
        'type' => 'project',
        'require' => [$package['name'] => $package['version']],
        'repositories' => [['type' => 'package', 'package' => $package]],
        'config' => ['allow-plugins' => false],
    ];
    $json = json_encode($consumerManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($consumer . '/composer.json', $json . "\n") === false) {
        throw new RuntimeException('Cannot write the consumer composer.json');
    }
    echo json_encode(['archive' => $archive, 'sha1' => $checksum, 'consumer' => realpath($consumer)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
