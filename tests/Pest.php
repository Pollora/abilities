<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Brain Monkey stands in for WordPress. Every test gets a clean set of stubbed
| core functions, so the adapters can be exercised without WordPress loaded and
| without one test's registrations leaking into the next.
|
*/

uses()
    ->beforeEach(function (): void {
        Brain\Monkey\setUp();
    })
    ->afterEach(function (): void {
        Brain\Monkey\tearDown();
    })
    ->in('Unit');
