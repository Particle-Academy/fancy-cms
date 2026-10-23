<?php

declare(strict_types=1);

namespace FancyCms\Exceptions;

use InvalidArgumentException;

/** Structured path errors; values are never interpolated into the message. */
final class SchemaException extends InvalidArgumentException
{
    public function __construct(private readonly array $errors)
    {
        $first = $errors[0];
        parent::__construct('[fancy-cms] invalid schema at '.$first['path'].': expected '.$first['expected'].', got '.$first['got'].(count($errors) > 1 ? ' (+'.(count($errors) - 1).' more)' : ''));
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
