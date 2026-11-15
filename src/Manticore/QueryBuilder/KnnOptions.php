<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** Validation and SQL serialization for the explicit, extended KNN API. */
final class KnnOptions
{
    public static function normalize(array $options): array
    {
        $result = [];
        foreach ($options as $name => $value) {
            if ($name === 'ef') {
                $valid = is_int($value) && $value > 0;
            }
            elseif ($name === 'oversampling') {
                $valid = (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 1;
                if ($valid) {
                    $value = (float)$value;
                }
            }
            elseif (in_array($name, ['rescore', 'early_termination', 'prefilter'], true)) {
                if (is_bool($value)) {
                    $value = (int)$value;
                }
                $valid = $value === 0 || $value === 1;
            }
            else {
                $valid = false;
            }
            if (!$valid) {
                throw new \InvalidArgumentException('Invalid or unsupported KNN option: ' . $name);
            }
            $result[$name] = $value;
        }
        return $result;
    }

    public static function vector(array $vector): array
    {
        if (!$vector || array_keys($vector) !== range(0, count($vector) - 1)) {
            throw new \InvalidArgumentException('KNN requires a non-empty vector list.');
        }
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException('KNN vectors require finite numbers.');
            }
        }
        return $vector;
    }

    public static function toSql(array $options): string
    {
        $values = [];
        foreach (self::normalize($options) as $name => $value) {
            $values[] = $name . '=' . json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        return $values ? ', {' . implode(', ', $values) . '}' : '';
    }
}
