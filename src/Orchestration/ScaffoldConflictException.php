<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Orchestration;

use RuntimeException;

class ScaffoldConflictException extends RuntimeException
{
    /**
     * @param string[] $existingFiles Exact-match collisions
     * @param array<string,string> $caseInsensitiveCollisions Map of planned-path => existing-sibling-with-different-case
     */
    public function __construct(array $existingFiles, array $caseInsensitiveCollisions = [])
    {
        $message = '';

        if (!empty($caseInsensitiveCollisions)) {
            $list = [];
            foreach ($caseInsensitiveCollisions as $planned => $existing) {
                $list[] = "{$planned}\n     ↔ existing: {$existing}";
            }
            $message .= "Scaffolding aborted: planned files would collide with existing modules\n"
                . "on case-insensitive filesystems (macOS HFS+/APFS, Windows NTFS).\n"
                . "On Linux ext4 these would silently overwrite the existing files instead.\n\n"
                . "Collisions:\n - " . implode("\n - ", $list)
                . "\n\nUse a different resource name (e.g. 'ApiKey' instead of 'APIKey')";
            $message .= empty($existingFiles) ? ".\n" : " or remove the conflicting files listed below.\n";
        }

        if (!empty($existingFiles)) {
            $fileList = implode("\n - ", $existingFiles);
            if ($message !== '') {
                $message .= "\n";
            }
            $message .= "The following files already exist (exact-name match):\n - {$fileList}\n\nPlease remove them or use a different resource name.";
        }

        parent::__construct($message);
    }
}
