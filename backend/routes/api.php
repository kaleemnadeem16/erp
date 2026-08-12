<?php

// Deliberately near-empty. Laravel's withRouting() in bootstrap/app.php needs this
// file to exist, but individual route groups register themselves from their own
// service provider instead of being centralised here — the same "no touching core"
// mechanism docs/architecture/extensibility.md requires plugins to use, so core
// modules and plugins share one pattern from the start. See
// App\Providers\AuthServiceProvider and docs/architecture/code-structure.md.
