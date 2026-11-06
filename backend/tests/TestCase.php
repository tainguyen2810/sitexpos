<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never write test files into the real storage/app (media, logos, exports): it leaked
        // thousands of files that later slowed down real backups of storage/app.
        Storage::fake('local');
        Storage::fake('public');

        // Behave like the SPA: JSON + a stateful (first-party) origin so Sanctum starts a session.
        $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => 'http://localhost/',
            'Accept-Language' => 'vi',
        ]);
    }
}
