<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

// Shared fakes and builders. Loaded here rather than declared in a test file so
// no suite depends on another suite having been loaded first.
require_once __DIR__.'/Helpers/abilities.php';
