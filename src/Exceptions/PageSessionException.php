<?php

declare(strict_types=1);

namespace FancyCms\Exceptions;

use RuntimeException;

/** Safe tool-facing refusal. Host adapters map getErrorCode(), not PHP's int code. */
final class PageSessionException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, private readonly array $errors, private readonly ?string $revision)
    {
        parent::__construct('[fancy-cms] '.$errorCode.' at '.($errors[0]['path'] ?? '$').'. Read the current page and changes before retrying.');
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getRevision(): ?string
    {
        return $this->revision;
    }
}
