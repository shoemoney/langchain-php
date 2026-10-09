<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

/**
 * Builds the SQL conditions for metadata filters.
 *
 * Port of `store/modules/query-builder.ts`. Conditions use positional `?`
 * placeholders (PDO has no `$n`), so the `params` list is appended to in the
 * exact order the placeholders appear. The JSONB key-exists operator `?` would
 * collide with them and is written as `jsonb_exists(value, ?)`.
 */
final class QueryBuilder
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, mixed> $filter
     * @param  list<mixed>          $params Appended to.
     * @return list<string>
     */
    public static function buildFilterConditions(array $filter, array &$params): array
    {
        $conditions = [];

        foreach ($filter as $key => $value) {
            $key = (string) $key;
            if (is_array($value) && !array_is_list($value)) {
                $isOperatorObject = false;
                foreach (array_keys($value) as $operator) {
                    if (str_starts_with((string) $operator, '$')) {
                        $isOperatorObject = true;
                        break;
                    }
                }

                if ($isOperatorObject) {
                    foreach ($value as $operator => $operatorValue) {
                        $condition = self::buildOperatorCondition($key, (string) $operator, $operatorValue, $params);
                        if ($condition !== null) {
                            $conditions[] = $condition;
                        }
                    }
                } else {
                    $conditions[] = 'value @> ?::jsonb';
                    $params[] = json_encode([$key => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            } elseif (is_array($value)) {
                // A list value, as upstream, falls through to the simple-value branch
                // where String([..]) joins the elements with commas.
                $conditions[] = 'value ->> ?::text = ?';
                array_push($params, $key, implode(',', array_map(self::stringify(...), $value)));
            } else {
                $conditions[] = 'value ->> ?::text = ?';
                array_push($params, $key, self::stringify($value));
            }
        }

        return $conditions;
    }

    /** JavaScript's `String(value)` for the scalar values a filter can hold. */
    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return implode(',', array_map(self::stringify(...), $value));
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    /**
     * @param list<mixed> $params Appended to.
     */
    private static function buildOperatorCondition(string $key, string $operator, mixed $operatorValue, array &$params): ?string
    {
        switch ($operator) {
            case '$eq':
                array_push($params, $key, self::stringify($operatorValue));

                return 'value ->> ?::text = ?';
            case '$ne':
                array_push($params, $key, self::stringify($operatorValue));

                return 'value ->> ?::text != ?';
            case '$gt':
            case '$gte':
            case '$lt':
            case '$lte':
                $sqlOperator = ['$gt' => '>', '$gte' => '>=', '$lt' => '<', '$lte' => '<='][$operator];
                array_push($params, $key, is_scalar($operatorValue) ? $operatorValue : self::stringify($operatorValue));

                return "(value ->> ?::text)::numeric {$sqlOperator} ?";
            case '$in':
            case '$nin':
                if (is_array($operatorValue) && $operatorValue !== []) {
                    $placeholders = implode(',', array_fill(0, count($operatorValue), '?'));
                    $params[] = $key;
                    foreach ($operatorValue as $item) {
                        $params[] = self::stringify($item);
                    }

                    return $operator === '$in'
                        ? "value ->> ?::text = ANY(ARRAY[{$placeholders}])"
                        : "value ->> ?::text != ALL(ARRAY[{$placeholders}])";
                }

                return null;
            case '$exists':
                $params[] = $key;

                return $operatorValue ? 'jsonb_exists(value, ?)' : 'NOT jsonb_exists(value, ?)';
            default:
                return null;
        }
    }
}
