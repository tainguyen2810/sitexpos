<?php

use Database\Seeders\DemoSeeder;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;

it('renders unknown API routes with the error envelope and a request id', function () {
    $res = $this->getJson('/api/v1/does-not-exist')->assertNotFound();

    expect($res->json('error.code'))->toBe('not_found')
        ->and($res->json('error.request_id'))->toBe($res->headers->get('X-Request-Id'))
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('propagates a valid incoming X-Request-Id', function () {
    $this->withHeader('X-Request-Id', 'abc-12345678')->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertHeader('X-Request-Id', 'abc-12345678')
        ->assertJsonPath('error.request_id', 'abc-12345678');
});

it('localizes messages (vi default, en on request)', function () {
    $this->getJson('/api/v1/me')->assertJsonPath('error.message', 'Bạn cần đăng nhập để tiếp tục.');
    $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/me')->assertJsonPath('error.message', 'You need to sign in to continue.');
});

it('seeds demo data in development only', function () {
    $this->seed(DemoSeeder::class);

    expect(User::query()->where('email', 'owner@'.DemoSeeder::DOMAIN)->exists())->toBeTrue()
        ->and(Workspace::query()->where('is_demo', true)->whereIn('name', ['Cửa hàng A (Demo)', 'Agency B (Demo)'])->count())->toBe(2)
        ->and(Workspace::query()->where('is_demo', true)->count())->toBe(50); // + 48 sample billing customers (Phase 7)
});

it('refuses to seed demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->setContainer(app())->__invoke())->toThrow(RuntimeException::class, 'disabled in production');
    expect(User::query()->count())->toBe(0);
});
