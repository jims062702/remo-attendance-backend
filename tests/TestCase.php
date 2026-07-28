<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum treats a request as stateful -- and therefore gives it the
        // session + CSRF stack -- only when its Origin or Referer matches a
        // configured stateful domain. A test request carries neither by
        // default, so cookie auth would silently degrade to stateless and any
        // session call would fail.
        //
        // Sending the Origin a real SPA would send keeps the tests exercising
        // the same middleware path as production.
        $this->withHeader('Origin', (string) config('app.url'));
    }
}
