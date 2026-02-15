<?php
declare(strict_types=1);

namespace Sugar\Core\Exception\Helper;

/**
 * Suggest correct directive names using Levenshtein distance
 */
final class DidYouMean
{
    /**
     * Suggest a correction for a mistyped directive
     *
     * @param string $input The mistyped directive name
     * @param array<string> $candidates Valid directive names
     * @param int $maxDistance Maximum Levenshtein distance to consider (default 2)
     * @return string|null Suggested directive name, or null if no close match
     */
    public static function suggest(
        string $input,
        array $candidates,
        int $maxDistance = 2,
    ): ?string {
        if ($input === '' || $candidates === []) {
            return null;
        }

        $input = strtolower($input);
        $closestDistance = PHP_INT_MAX;
        $closestMatch = null;

        foreach ($candidates as $candidate) {
            $candidate = strtolower($candidate);

            // Exact match means no suggestion needed
            if ($input === $candidate) {
                return null;
            }

            $distance = levenshtein($input, $candidate);

            if ($distance < $closestDistance) {
                $closestDistance = $distance;
                $closestMatch = $candidate;
            }
        }

        // Only suggest if within acceptable distance
        if ($closestDistance <= $maxDistance) {
            return $closestMatch;
        }

        return null;
    }
}
