<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Behave like the SPA: JSON + a stateful (first-party) origin so Sanctum starts a session.
        $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => 'http://localhost/',
            'Accept-Language' => 'vi',
        ]);
    }
}
