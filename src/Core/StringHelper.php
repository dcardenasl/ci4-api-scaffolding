<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Core;

/**
 * Single source of truth for the string transformations the scaffolding engine
 * performs on resource names: studly/pluralize/snake/kebab.
 *
 * Previously these helpers were duplicated across MakeCrud, ModuleCheck, and
 * ResourceSchema — one bug fix had to be applied three times.
 */
final class StringHelper
{
    public static function studly(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        // Already a single alphanumeric identifier (e.g. `SchoolCategory`, `product`, `APIKey`)?
        // Preserve its internal casing — just ensure the first letter is uppercase.
        // Previously this branch was missing, so `SchoolCategory` became `Schoolcategory`,
        // breaking every compound-name resource at the class/filename level.
        if (preg_match('/^[a-zA-Z0-9]+$/', $value) === 1) {
            return ucfirst($value);
        }

        // Split on non-alphanumeric separators (_, -, space) and capitalize each part.
        $parts = preg_split('/[^a-zA-Z0-9]+/', $value) ?: [];
        $parts = array_map(static fn (string $part): string => ucfirst(strtolower($part)), $parts);

        return implode('', $parts);
    }

    public static function pluralize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (preg_match('/[^aeiouAEIOU]y$/', $value) === 1) {
            return substr($value, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $value) === 1) {
            return $value . 'es';
        }

        return $value . 's';
    }

    public static function toKebab(string $value): string
    {
        return self::splitCamelHumps($value, '-');
    }

    public static function toSnakeCase(string $value): string
    {
        return self::splitCamelHumps($value, '_');
    }

    /**
     * Convert StudlyCase / camelCase to lowerCamelCase, preserving the structure of
     * acronym runs (APIKey → apiKey instead of the broken aPIKey from naive lcfirst).
     */
    public static function toCamelCase(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Normalize acronym runs to title-case first: APIKey → ApiKey, HTTPRequest → HttpRequest.
        $value = (string) preg_replace_callback(
            '/([A-Z]+)([A-Z][a-z]|$)/',
            static fn (array $m): string => ucfirst(strtolower($m[1])) . $m[2],
            $value
        );

        return lcfirst($value);
    }

    /**
     * True if the value contains a run of two or more consecutive uppercase letters
     * followed by a lowercase letter — the pattern that produces broken snake_case
     * output unless callers normalize first.
     */
    public static function hasAcronymRun(string $value): bool
    {
        return preg_match('/[A-Z]{2,}[a-z]|[A-Z]{2,}$/', $value) === 1;
    }

    /**
     * Convert camelCase / StudlyCase to a delimited lowercase form.
     *
     * Treats runs of uppercase letters as a single word so acronyms survive intact:
     *   APIKey       → api_key      (not a_p_i_key)
     *   HTTPRequest  → http_request (not h_t_t_p_request)
     *   OAuth2Token  → o_auth2_token
     *   SchoolCategory → school_category
     *   product      → product
     */
    private static function splitCamelHumps(string $value, string $delimiter): string
    {
        // Boundary 1: lowercase / digit followed by uppercase (camelCase split).
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1' . $delimiter . '$2', $value);
        // Boundary 2: end of an uppercase run before an uppercase+lowercase pair (acronym split).
        $value = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1' . $delimiter . '$2', $value);

        return strtolower($value);
    }
}
