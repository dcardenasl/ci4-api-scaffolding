<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Wiring;

use RuntimeException;

/**
 * Thrown by {@see ConfigWireman::wire()} when a regex-based injection step
 * could not be verified after writing the file. Carries the manual snippet
 * the user should paste so the partially-scaffolded module can be recovered
 * by hand instead of leaving the consumer in a broken half-wired state.
 */
class WiringFailedException extends RuntimeException
{
    /**
     * @param array{trait_file: string, trait_content: string, service_method: string, services_register: string} $manualSnippet
     */
    public function __construct(string $message, public readonly array $manualSnippet)
    {
        parent::__construct($message);
    }

    /**
     * Render the exception + the manual recovery snippet as a single
     * human-readable block, suitable for printing to the spark CLI.
     */
    public function describe(): string
    {
        $lines = [
            $this->getMessage(),
            '',
            'Apply the following manually to recover:',
            '',
            "1) Create or overwrite {$this->manualSnippet['trait_file']} with:",
            $this->manualSnippet['trait_content'],
            '',
            '2) Inside the trait body, paste this factory method:',
            $this->manualSnippet['service_method'],
            '',
            '3) Update Config/Services.php:',
            $this->manualSnippet['services_register'],
        ];

        return implode("\n", $lines);
    }
}
