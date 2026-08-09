<?php

namespace avadim\Manticore\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Accumulating PSR-3 logger.
 *
 * The signature keeps $message untyped (contravariance) and declares ": void" so that the class
 * stays compatible with psr/log 1.x (PHP 7.4) as well as psr/log 2.x and 3.x, where
 * AbstractLogger::log() is declared as log($level, Stringable|string $message, array $context = []): void
 */
class TestLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    public array $data = [];

    /**
     * @param mixed $level
     * @param mixed $message
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $this->data[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * All recorded levels, in order
     *
     * @return string[]
     */
    public function levels(): array
    {
        return array_column($this->data, 'level');
    }

    /**
     * All recorded messages, in order
     *
     * @return string[]
     */
    public function messages(): array
    {
        return array_column($this->data, 'message');
    }

    /**
     * Records of the given level only
     *
     * @param string $level
     *
     * @return array<int, array{level: string, message: string, context: array}>
     */
    public function records(string $level): array
    {
        return array_values(array_filter($this->data, static function (array $record) use ($level) {
            return $record['level'] === $level;
        }));
    }

    /**
     * The first record of the given level
     *
     * @param string $level
     *
     * @return array{level: string, message: string, context: array}|null
     */
    public function firstRecord(string $level): ?array
    {
        $records = $this->records($level);

        return $records[0] ?? null;
    }
}
