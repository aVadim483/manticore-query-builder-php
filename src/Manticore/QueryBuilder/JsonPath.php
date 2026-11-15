<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** Validated JSON paths; keys are values, not arbitrary SQL fragments. */
final class JsonPath
{
    public static function compile(string $column): string
    {
        if (strpos($column, '->') === false) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:(?:\.[a-zA-Z_][a-zA-Z0-9_]*)|(?:\[[0-9]+\]))*$/D', $column)) {
                throw new \InvalidArgumentException('Invalid JSON path');
            }
            preg_match_all('/\[([0-9]+)\]/', $column, $indexes);
            foreach ($indexes[1] as $index) {
                self::index($index);
            }
            return $column;
        }

        $parts = self::segments($column);
        $root = array_shift($parts);
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $root)) {
            throw new \InvalidArgumentException('Invalid JSON attribute name');
        }
        $sql = $root;
        foreach ($parts as $part) {
            if ($part === '' || strpos($part, "\0") !== false) {
                throw new \InvalidArgumentException('JSON paths cannot have empty segments or null bytes');
            }
            if ($part[0] === '"') {
                try {
                    $key = json_decode($part, true, 512, JSON_THROW_ON_ERROR);
                }
                catch (\JsonException $error) {
                    throw new \InvalidArgumentException('Invalid quoted JSON key', 0, $error);
                }
                if (!is_string($key) || $key === '' || strpos($key, "\0") !== false) {
                    throw new \InvalidArgumentException('Invalid quoted JSON key');
                }
                $sql .= '[' . Query::quoteParam($key) . ']';
            }
            elseif (preg_match('/^[0-9]+$/D', $part)) {
                $sql .= self::index($part);
            }
            else {
                if (!preg_match('/^([^\[\]*]+)((?:\[[0-9]+\])*)$/uD', $part, $match)) {
                    throw new \InvalidArgumentException('JSON paths require keys and non-negative array indexes');
                }
                $sql .= '[' . Query::quoteParam($match[1]) . ']';
                preg_match_all('/\[([0-9]+)\]/', $match[2], $indexes);
                foreach ($indexes[1] as $index) {
                    $sql .= self::index($index);
                }
            }
        }
        return $sql;
    }

    /** Split arrows outside JSON-quoted keys, respecting escaped quotes/backslashes. */
    private static function segments(string $path): array
    {
        $segments = [];
        $start = 0;
        $quoted = false;
        $length = strlen($path);
        for ($position = 0; $position < $length; $position++) {
            $character = $path[$position];
            if ($quoted && $character === '\\') {
                // JSON decoding below validates the escape; its next byte cannot end the key.
                $position++;
                continue;
            }
            if ($character === '"') {
                $quoted = !$quoted;
            }
            elseif (!$quoted && $character === '-' && $position + 1 < $length && $path[$position + 1] === '>') {
                $segments[] = substr($path, $start, $position - $start);
                $start = $position + 2;
                $position++;
            }
        }
        if ($quoted) {
            throw new \InvalidArgumentException('Unterminated quoted JSON path key');
        }
        $segments[] = substr($path, $start);
        return $segments;
    }

    private static function index(string $index): string
    {
        $index = ltrim($index, '0') ?: '0';
        if (strlen($index) > 10 || (strlen($index) === 10 && strcmp($index, '2147483647') > 0)) {
            throw new \InvalidArgumentException('JSON array indexes must fit a signed 32-bit integer');
        }
        return '[' . $index . ']';
    }
}
