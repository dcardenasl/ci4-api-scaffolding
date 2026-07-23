<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use dcardenasl\Ci4ApiScaffolding\Commands\MakeCrudRemove;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the non-TTY confirmation crash (same failure class as
 * make:crud's C13 audit finding): `CLI::prompt()` returns `bool` instead of
 * `string` when stdin is not a TTY, throwing a `TypeError` mid-command. The
 * command must detect a non-interactive stdin and fail clean (requiring
 * --force) before ever calling `CLI::prompt()`.
 */
final class MakeCrudRemoveTest extends TestCase
{
    public function testRunGuardsAgainstNonTtyBeforePromptingForConfirmation(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(MakeCrudRemove::class))->getFileName(),
        );

        $promptOffset = strpos($source, "CLI::prompt('Proceed?");
        $this->assertNotFalse($promptOffset, 'Expected the confirmation prompt to still exist.');

        $guardOffset = strpos($source, 'posix_isatty(STDIN)');
        $this->assertNotFalse(
            $guardOffset,
            'run() must check posix_isatty(STDIN) before calling CLI::prompt(), '
            . 'otherwise a non-interactive invocation without --force crashes with a TypeError.',
        );

        $this->assertLessThan(
            $promptOffset,
            $guardOffset,
            'The posix_isatty(STDIN) guard must run BEFORE CLI::prompt(), not after.',
        );
    }
}
