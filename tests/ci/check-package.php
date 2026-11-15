<?php

declare(strict_types=1);

// This checker deliberately does not require the repository's autoloader or test bootstrap.
try {
    if ($argc !== 2) {
        throw new RuntimeException('Usage: php tests/ci/check-package.php <installed-consumer-directory>');
    }
    $consumer = realpath($argv[1]);
    if ($consumer === false || !is_file($consumer . '/vendor/autoload.php')) {
        throw new RuntimeException('The consumer must be installed with Composer first');
    }
    require $consumer . '/vendor/autoload.php';
    $packagePath = $consumer . '/vendor/avadim/manticore-query-builder-php';
    $package = realpath($packagePath);
    if ($package === false || is_link($packagePath)) {
        throw new RuntimeException('The installed distribution package is missing or is a source symlink');
    }
    $packagePrefix = str_replace('\\', '/', $package) . '/';
    foreach (['Query', 'Builder', 'BatchResult', 'JsonPath', 'KnnOptions', 'HybridHit', 'HybridResult'] as $name) {
        $class = new ReflectionClass('avadim\\Manticore\\QueryBuilder\\' . $name);
        $source = str_replace('\\', '/', (string)realpath((string)$class->getFileName()));
        if (strpos($source, $packagePrefix) !== 0) {
            throw new RuntimeException('A class was loaded outside the installed distribution: ' . $name);
        }
    }
    $query = new ReflectionClass('avadim\\Manticore\\QueryBuilder\\Query');
    foreach (['insertBatch', 'replaceBatch', 'whereJsonContains', 'whereFullText', 'knn',
        'hybridSearch', 'hybridGet', 'hybridFirst', 'whereKnn', 'ranker'] as $method) {
        if (!$query->hasMethod($method)) {
            throw new RuntimeException('The installed query builder is missing ' . $method);
        }
    }
    foreach (['HybridHit', 'HybridResult'] as $name) {
        $class = new ReflectionClass('avadim\\Manticore\\QueryBuilder\\' . $name);
        if (!$class->implementsInterface(JsonSerializable::class) || !$class->hasMethod('toArray')) {
            throw new RuntimeException('The installed ' . $name . ' is missing its export contract');
        }
    }
    if (class_exists('PHPUnit\\Framework\\TestCase') || is_dir($consumer . '/vendor/phpunit')) {
        throw new RuntimeException('The consumer unexpectedly contains development dependencies');
    }
    foreach (['tests', 'docs', '.github', 'vendor'] as $excluded) {
        if (file_exists($package . '/' . $excluded)) {
            throw new RuntimeException('The installed package includes an excluded directory: ' . $excluded);
        }
    }
    $lock = json_decode((string)file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $installed = null;
    foreach ($lock['packages'] ?? [] as $dependency) {
        if (($dependency['name'] ?? null) === 'avadim/manticore-query-builder-php') {
            $installed = $dependency;
        }
    }
    if ($installed === null || ($installed['dist']['type'] ?? null) !== 'zip'
        || strpos($installed['dist']['url'] ?? '', 'file://') !== 0
        || empty($installed['dist']['shasum']) || isset($installed['source'])
        || !empty($lock['packages-dev'])) {
        throw new RuntimeException('The consumer did not install the expected runtime-only ZIP distribution');
    }
    $example = $package . '/examples/hybrid-search.php';
    if (!is_file($example)) {
        throw new RuntimeException('The installed runnable example is missing');
    }
    // Keep top-level example variables out of this checker's scope.
    [$returned, $output] = (static function (string $script): array {
        ob_start();
        try {
            $returned = require $script;
            return [$returned, (string)ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    })($example);
    $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    // JSON can encode an integral float as an integer; compare serialized values.
    $normalizedReturn = json_decode(json_encode($returned, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($returned) || $report !== $normalizedReturn
        || ($report['batch']['confirmed_rows'] ?? null) !== 5
        || ($report['batch']['completed_batches'] ?? null) !== 3
        || ($report['hybrid']['window_total'] ?? null) !== 3
        || !array_key_exists('total_found', $report['hybrid']) || $report['hybrid']['total_found'] !== null
        || count($report['page']['data'] ?? []) !== 1) {
        throw new RuntimeException('The installed example returned unexpected results');
    }
    echo json_encode(['package' => $package, 'php' => PHP_VERSION, 'smoke' => 'passed', 'example' => $report],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
