<?php

// Package registration stays outside the migrated test sources, as an actual
// application's Inertia provider does. The callback type remains package-owned.
\Illuminate\Testing\TestResponse::macro('assertInertia', function (?\Closure $callback = null) {
    $callback(new \Tests\PageAssertions());
    return $this;
});
