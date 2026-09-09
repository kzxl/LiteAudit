<?php

declare(strict_types=1);

namespace LiteAudit\Diff;

use DateTimeInterface;

/**
 * High-performance diff calculator for generating compact Git-like audit deltas.
 */
final class AuditDiffCalculator
{
    /**
     * Compute field-level difference between old and new state.
     *
     * @param ?array<string, mixed> $old Old property/column values
     * @param ?array<string, mixed> $new New property/column values
     * @param list<string> $ignored Field names to exclude from diff
     * @return array<string, array{old: mixed, new: mixed}> Field-level deltas
     */
    public static function diff(?array $old, ?array $new, array $ignored = []): array
    {
        $diff = [];
        $ignoredMap = array_fill_keys($ignored, true);

        // CREATE: Only new values exist
        if ($old === null || empty($old)) {
            if ($new === null) {
                return [];
            }
            foreach ($new as $key => $value) {
                if (isset($ignoredMap[$key])) {
                    continue;
                }
                $diff[$key] = [
                    'old' => null,
                    'new' => self::normalizeValue($value),
                ];
            }
            return $diff;
        }

        // DELETE: Only old values exist
        if ($new === null || empty($new)) {
            foreach ($old as $key => $value) {
                if (isset($ignoredMap[$key])) {
                    continue;
                }
                $diff[$key] = [
                    'old' => self::normalizeValue($value),
                    'new' => null,
                ];
            }
            return $diff;
        }

        // UPDATE: Compare existing keys
        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $key) {
            if (isset($ignoredMap[$key])) {
                continue;
            }

            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;

            if (!self::valuesEqual($oldVal, $newVal)) {
                $diff[$key] = [
                    'old' => self::normalizeValue($oldVal),
                    'new' => self::normalizeValue($newVal),
                ];
            }
        }

        return $diff;
    }

    /**
     * Filter out ignored keys from a snapshot.
     *
     * @param ?array<string, mixed> $snapshot
     * @param list<string> $ignored
     * @return ?array<string, mixed>
     */
    public static function filterIgnored(?array $snapshot, array $ignored): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $filtered = [];
        $ignoredMap = array_fill_keys($ignored, true);

        foreach ($snapshot as $key => $value) {
            if (!isset($ignoredMap[$key])) {
                $filtered[$key] = self::normalizeValue($value);
            }
        }

        return $filtered;
    }

    /**
     * Normalize values for serializable JSON diffs.
     */
    public static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        return $value;
    }

    /**
     * Determine if two values are logically equivalent.
     */
    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $normA = self::normalizeValue($a);
        $normB = self::normalizeValue($b);

        if ($normA === $normB) {
            return true;
        }

        // Loose equivalence for numeric strings and numbers
        if (is_numeric($normA) && is_numeric($normB)) {
            return (string)$normA === (string)$normB;
        }

        return false;
    }
}
