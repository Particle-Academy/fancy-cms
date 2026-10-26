<?php

declare(strict_types=1);

namespace FancyCms\Exceptions;

use RuntimeException;

/** A constant reason is safe to display; resolver exception details are never propagated. */
final class FontResolutionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('CMS font resolution failed: '.$reason);
    }
}
