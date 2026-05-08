<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Validators;

use dcardenasl\Ci4ApiCore\Exceptions\BadRequestException;

/**
 * ValidatesRequiredFields Trait
 *
 * Provides validation methods for common required field patterns across services.
 * Reduces code duplication and standardizes validation error messages.
 */
trait ValidatesRequiredFields
{
    /**
     * Validate and extract required ID from data array
     *
     * @param array<string, mixed> $data Request data
     * @param string $field Field name to validate (default: id)
     * @param string|null $label Optional field label for localized message
     * @return int Validated ID
     * @throws BadRequestException If ID is missing or empty
     */
    protected function validateRequiredId(array $data, string $field = 'id', ?string $label = null): int
    {
        if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
            $fieldLabel = $label ?: ucfirst(str_replace('_', ' ', $field));
            throw new BadRequestException(
                lang('Api.invalidRequest'),
                [$field => lang('InputValidation.common.idRequired', [$fieldLabel])]
            );
        }

        return (int) $data[$field];
    }

    /**
     * Validate and extract required field from data array
     *
     * @param array<string, mixed> $data Request data
     * @param string $field Field name to validate
     * @param string|null $langKey Optional language key for error message
     * @return mixed Validated field value
     * @throws BadRequestException If field is missing or empty
     */
    protected function validateRequiredField(array $data, string $field, ?string $langKey = null): mixed
    {
        if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
            $errorMessage = $langKey ? lang($langKey) : lang('Api.fieldRequired', [$field]);

            throw new BadRequestException(
                lang('Api.invalidRequest'),
                [$field => $errorMessage]
            );
        }

        return $data[$field];
    }

    /**
     * Validate multiple required fields and throw a single exception with field-level errors.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $fieldErrors [field => errorMessage]
     * @param string $requestMessage
     * @throws BadRequestException
     */
    protected function validateRequiredFields(array $data, array $fieldErrors, string $requestMessage = ''): void
    {
        $errors = [];

        foreach ($fieldErrors as $field => $errorMessage) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $errors[$field] = $errorMessage;
            }
        }

        if ($errors !== []) {
            $message = $requestMessage !== ''
                ? $requestMessage
                : lang('Api.invalidRequest');

            throw new BadRequestException($message, $errors);
        }
    }
}
