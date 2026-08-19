<?php

declare(strict_types=1);

// Deliberately broken fixture: the factory boots this file, and it throws.
// Used by the interceptor regression tests for the boot-failure path.

throw new RuntimeException('broken bootstrap fixture');
