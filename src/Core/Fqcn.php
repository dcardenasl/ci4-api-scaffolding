<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Core;

/**
 * Tiny helper for slicing fully-qualified class names. Used by generators
 * that receive a FQCN from ScaffoldingConfig but need to render templates
 * in two parts: a `use` statement (full FQCN) and the short class name
 * after `extends` / `implements` / `new`.
 */
final class Fqcn
{
    public static function shortName(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    public static function namespace(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }
}
