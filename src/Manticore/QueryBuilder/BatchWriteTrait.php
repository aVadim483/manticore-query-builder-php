<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** Streaming SQL writes. Existing INSERT/REPLACE execution paths are untouched. */
trait BatchWriteTrait
{
    public function insertBatch(iterable $rows, array $options = []): BatchResult
    {
        return $this->_writeBatches('INSERT', $rows, $options);
    }

    public function replaceBatch(iterable $rows, array $options = []): BatchResult
    {
        return $this->_writeBatches('REPLACE', $rows, $options);
    }

    private function _writeBatches(string $command, iterable $rows, array $options): BatchResult
    {
        foreach ($options as $name => $value) {
            if (!in_array($name, ['max_rows', 'max_bytes', 'collect_ids', 'on_ids'], true)) {
                throw new \InvalidArgumentException('Unknown batch option: ' . $name);
            }
        }
        $options += ['max_rows' => 1000, 'max_bytes' => 1048576, 'collect_ids' => false, 'on_ids' => null];
        $maxRows = $options['max_rows'];
        $maxBytes = $options['max_bytes'];
        $collect = $options['collect_ids'];
        $onIds = $options['on_ids'];
        if (!is_int($maxRows) || $maxRows < 1 || !is_int($maxBytes) || $maxBytes < 1) {
            throw new \InvalidArgumentException('max_rows and max_bytes must be positive integers');
        }
        if (!is_bool($collect) || ($onIds !== null && !is_callable($onIds))) {
            throw new \InvalidArgumentException('collect_ids must be boolean and on_ids must be callable or null');
        }
        $table = $this->_sqlTable();
        if (!$table || !preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*|`[a-zA-Z_][a-zA-Z0-9_]*`)$/D', $table)) {
            throw new \InvalidArgumentException('Batch writes require one plain table name');
        }
        if ($this->sql !== null || $this->params || (string)$this->conditions !== ''
            || (method_exists($this->conditions, 'hasFullText') && $this->conditions->hasFullText())
            || $this->match !== null || $this->knn !== null || $this->joins || $this->group
            || $this->having || $this->order || $this->limit || $this->select || $this->options
            || $this->facets || $this->highlight || $this->exprColumns || $this->distanceColumns) {
            throw new \LogicException('Batch writes require a table query without read clauses, SQL or bindings');
        }

        $confirmed = $completed = $position = $start = 0;
        $error = $idError = $failedPosition = $failedBatch = null;
        $unknown = false;
        $collectedIds = $tuples = $columns = $types = $encoders = [];
        $header = '';
        $bytes = 0;

        $flush = function () use (
            $command, $collect, $onIds, &$tuples, &$header, &$bytes, &$start,
            &$confirmed, &$completed, &$error, &$idError, &$failedPosition,
            &$failedBatch, &$unknown, &$collectedIds
        ): bool {
            if (!$tuples) {
                return true;
            }
            $sql = $header . implode(',', $tuples);
            $count = count($tuples);
            $range = ['start' => $start, 'end' => $start + $count - 1];
            $time = microtime(true);
            try {
                // query() is part of the legacy client contract and never fetches IDs.
                $response = $this->client->query($sql);
                if (!is_array($response)) {
                    throw new \UnexpectedValueException('Batch client query() must return an array');
                }
                if (!empty($response['error'])) {
                    throw new \RuntimeException((string)$response['error']);
                }
            }
            catch (\Throwable $e) {
                $error = $e;
                $failedPosition = $start;
                $failedBatch = $range;
                $unknown = !$this->_batchKnownRejection($e);
                $this->_resultSet([
                    'command' => $command, 'query' => $sql, 'exec_time' => microtime(true) - $time,
                    'response' => ['error' => $e->getMessage()],
                    'result' => ['type' => 'bool', 'data' => false],
                ]);
                $this->_batchLogWrite($command, $sql, microtime(true) - $time, [], $e);

                return false;
            }
            $writeResponse = $response;
            $elapsed = microtime(true) - $time;
            $confirmed += $count;
            $batchIndex = $completed++;
            $last = $this->_resultSet([
                'command' => $command, 'query' => $sql, 'exec_time' => microtime(true) - $time,
                'result' => ['type' => 'bool', 'data' => true],
            ], $command === 'INSERT' ? 'inserted' : 'replaced');

            // Read immediately, before logging or user callbacks can issue another write.
            if ($collect || $onIds !== null) {
                try {
                    $response = $this->client->query('SELECT LAST_INSERT_ID()');
                    if (!empty($response['error'])) {
                        throw new \RuntimeException((string)$response['error']);
                    }
                    $ids = $this->_batchIds($response['data'] ?? []);
                    if (count($ids) !== $count) {
                        throw new \UnexpectedValueException('LAST_INSERT_ID() did not return one ID per batch document');
                    }
                    $last->setResult('id', count($ids) === 1 ? $ids[0] : $ids);
                }
                catch (\Throwable $e) {
                    $idError = $e;
                    $failedPosition = $start;
                    $failedBatch = $range;
                    $this->_batchLogWrite($command, $sql, $elapsed, $writeResponse, $e);

                    return false;
                }
                if ($collect) {
                    foreach ($ids as $id) {
                        $collectedIds[] = $id;
                    }
                }
            }
            $loggingError = $this->_batchLogWrite($command, $sql, $elapsed, $writeResponse);
            if ($loggingError !== null) {
                $error = $loggingError;
                $failedPosition = $start;
                $failedBatch = $range;

                return false;
            }
            if ($onIds !== null) {
                try {
                    $onIds($ids, $batchIndex);
                }
                catch (\Throwable $e) {
                    $error = $e;
                    $failedPosition = $start;
                    $failedBatch = $range;

                    return false;
                }
            }
            $tuples = [];
            $bytes = strlen($header);

            return true;
        };

        try {
            foreach ($rows as $row) {
                if (!is_array($row) || !$row) {
                    throw new \InvalidArgumentException('Each batch row must be a non-empty associative array');
                }
                if (!$columns) {
                    $columns = array_keys($row);
                    foreach ($columns as $column) {
                        if (!is_string($column) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)) {
                            throw new \InvalidArgumentException('Batch columns must be plain identifiers');
                        }
                    }
                    // A failed DESCRIBE is an input preparation failure, not an acknowledged write.
                    if (isset($this->indexPool[$table]['columnsType'])) {
                        $types = $this->indexPool[$table]['columnsType'];
                    }
                    else {
                        $description = $this->_describeTable($table);
                        if (!$description->success()) {
                            throw new \RuntimeException((string)$description->error());
                        }
                        foreach ($description->result() as $column) {
                            $types[$column['Field']] = $column['Type'];
                        }
                        $this->indexPool[$table]['describe'] = $description;
                        $this->indexPool[$table]['columnsType'] = $types;
                    }
                    foreach ($columns as $column) {
                        $encoders[$column] = $this->_batchColumnEncoder($column, $types[$column] ?? null);
                    }
                    $header = $command . ' INTO ' . $table . '(' . implode(',', $columns) . ') VALUES ';
                    $bytes = strlen($header);
                }
                if (count($row) !== count($columns) || array_diff($columns, array_keys($row))) {
                    throw new \InvalidArgumentException('Every batch row must contain the same columns');
                }
                $values = [];
                foreach ($columns as $column) {
                    $this->_batchValidateValue($row[$column]);
                    $values[] = $encoders[$column]($row[$column]);
                }
                $tuple = '(' . implode(',', $values) . ')';
                if (strlen($header) + strlen($tuple) > $maxBytes) {
                    throw new \LengthException('Batch document at position ' . $position . ' exceeds max_bytes');
                }
                $addition = strlen($tuple) + ($tuples ? 1 : 0);
                if ($tuples && $bytes + $addition > $maxBytes) {
                    if (!$flush()) {
                        break;
                    }
                    $addition = strlen($tuple);
                }
                if (!$tuples) {
                    $start = $position;
                }
                $tuples[] = $tuple;
                $bytes += $addition;
                if (count($tuples) === $maxRows && !$flush()) {
                    break;
                }
                ++$position;
            }
            if ($error === null && $idError === null) {
                $flush();
            }
        }
        catch (\Throwable $e) {
            $error = $e;
            $failedPosition = $position;
        }

        return new BatchResult($confirmed, $completed, $failedPosition, $failedBatch,
            $error, $idError, $unknown, $collectedIds);
    }

    /** Resolve schema-dependent handling once, retaining the legacy formatter for values. */
    private function _batchColumnEncoder(string $column, ?string $type): callable
    {
        if (strcasecmp($column, 'id') === 0) {
            return function ($value): string { return $this->_batchDocumentId($value); };
        }
        if ($type === 'json') {
            return static function ($value) use ($column): string {
                if (is_array($value)) {
                    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                    if ($json === false) {
                        throw new \InvalidArgumentException('Invalid JSON value in batch column ' . $column);
                    }
                    return Parser::formatScalar($json, 'str');
                }
                return Parser::formatValue($value, 'json');
            };
        }
        return static function ($value) use ($type): string { return Parser::formatValue($value, $type); };
    }

    /** A normalized, exact uint64 SQL literal, without signed-integer or float conversion. */
    private function _batchDocumentId($id): string
    {
        if ((is_int($id) && $id >= 0) || (is_string($id) && preg_match('/^\d+$/D', $id))) {
            $id = ltrim((string)$id, '0');
            $id = $id === '' ? '0' : $id;
            $max = '18446744073709551615';
            if (strlen($id) < strlen($max) || (strlen($id) === strlen($max) && strcmp($id, $max) <= 0)) {
                return $id;
            }
        }

        throw new \InvalidArgumentException('Batch document IDs must be non-negative integers or uint64 decimal digit strings');
    }

    private function _batchValidateValue($value, int $depth = 0): void
    {
        if ($depth > 64) {
            throw new \InvalidArgumentException('Batch arrays must not be recursive or deeper than 64 levels');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->_batchValidateValue($item, $depth + 1);
            }
        }
        elseif (is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Batch values must be scalar values, null or arrays without expressions');
        }
    }

    private function _batchKnownRejection(\Throwable $error): bool
    {
        $state = $error instanceof \PDOException ? ($error->errorInfo[0] ?? (string)$error->getCode()) : '';
        // SQL and server error text can contain forged "Error [42000]" fragments.
        // Only structured PDO evidence can establish a definite rejection.
        return in_array(substr($state, 0, 2), ['22', '23', '42'], true);
    }

    /** A logger failure stops consumption but cannot undo a write acknowledgement. */
    private function _batchLogWrite(string $command, string $sql, float $elapsed, array $response, ?\Throwable $failure = null): ?\Throwable
    {
        $context = ['command' => $command, 'query' => $sql, 'params' => [], 'exec_time' => $elapsed, 'response' => $response];
        try {
            $this->log('info', 'Manticore Query:', $context);
            $this->log('debug', 'Manticore Result:', $context);
            if ($failure !== null) {
                $this->log('error', $failure->getMessage(), $context);
            }
        }
        catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /** @return array<int,int|string> */
    private function _batchIds(array $rows): array
    {
        if (!$rows || !is_array($rows[0]) || !$rows[0]) {
            throw new \UnexpectedValueException('LAST_INSERT_ID() returned no IDs');
        }
        $value = reset($rows[0]);
        $result = [];
        foreach (explode(',', (string)$value) as $id) {
            $id = trim($id);
            if (!preg_match('/^-?\d+$/D', $id)) {
                throw new \UnexpectedValueException('LAST_INSERT_ID() returned an invalid numeric ID');
            }
            // Some SQL transports expose uint64 IDs as signed two's-complement decimals.
            if ($id[0] === '-') {
                $magnitude = ltrim(substr($id, 1), '0');
                $signedMax = '9223372036854775808';
                if ($magnitude === '' || strlen($magnitude) > strlen($signedMax)
                    || (strlen($magnitude) === strlen($signedMax) && strcmp($magnitude, $signedMax) > 0)) {
                    throw new \UnexpectedValueException('LAST_INSERT_ID() returned an invalid signed 64-bit ID');
                }
                $full = '18446744073709551616';
                $magnitude = str_pad($magnitude, strlen($full), '0', STR_PAD_LEFT);
                $borrow = 0;
                $id = '';
                for ($i = strlen($full) - 1; $i >= 0; --$i) {
                    $digit = (int)$full[$i] - (int)$magnitude[$i] - $borrow;
                    $borrow = $digit < 0 ? 1 : 0;
                    $id = (string)($digit < 0 ? $digit + 10 : $digit) . $id;
                }
            }
            $id = $this->_batchDocumentId($id);
            $max = (string)PHP_INT_MAX;
            $result[] = strlen($id) < strlen($max) || (strlen($id) === strlen($max) && strcmp($id, $max) <= 0)
                ? (int)$id : $id;
        }

        return $result;
    }
}
