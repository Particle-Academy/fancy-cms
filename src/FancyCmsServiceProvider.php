<?php

declare(strict_types=1);

namespace FancyCms;

use Illuminate\Support\ServiceProvider;

/**
 * A well-behaved guest: opt-in routes/middleware, respects host auth + layout +
 * data, never force-publishes globals. Phase 0 binds nothing yet — persistence,
 * the file API, and publish routing arrive in Phase 1.
 */
final class FancyCmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 1+: bind the document store, file-disk API, and publish router.
    }

    public function boot(): void
    {
        // Phase 1+: register opt-in routes + the <CmsRegion> view component.
    }
}
