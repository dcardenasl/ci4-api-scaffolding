<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Wiring;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * @internal
 */
final class PhpAstEditor
{
    /**
     * Parsea $code, clona el AST, aplica $mutate en el clon y devuelve el
     * output format-preserved. Devuelve null si $mutate retorna false (sin cambios)
     * o si el código no parsea.
     *
     * @param callable(array<\PhpParser\Node\Stmt>): bool $mutate
     */
    public function edit(string $code, callable $mutate): ?string
    {
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $origStmts = $parser->parse($code);
        } catch (Error) {
            return null;
        }

        if ($origStmts === null) {
            return null;
        }

        $origTokens = $parser->getTokens();

        $traverser = new NodeTraverser(new CloningVisitor());
        /** @var array<\PhpParser\Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($origStmts);

        if (!$mutate($newStmts)) {
            return null;
        }

        return (new Standard())->printFormatPreserving($newStmts, $origStmts, $origTokens);
    }

    /**
     * Devuelve true si $code es PHP sintácticamente válido.
     */
    public function isValidPhp(string $code): bool
    {
        try {
            $parser = (new ParserFactory())->createForHostVersion();
            return $parser->parse($code) !== null;
        } catch (Error) {
            return false;
        }
    }
}
