<?php

use Illuminate\Support\Facades\RateLimiter;

function downloadedPluginMain(string $body): string
{
    $file = tempnam(sys_get_temp_dir(), 'sxptest');
    file_put_contents($file, $body);
    $zip = new ZipArchive;
    expect($zip->open($file))->toBeTrue();
    $main = (string) $zip->getFromName('sitex-post/sitex-post.php');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();
    unlink($file);

    expect($names)->each->toStartWith('sitex-post/')
        ->and($names)->toContain('sitex-post/includes/Autoloader.php', 'sitex-post/languages/sitex-post-vi.mo');

    return $main;
}

it('serves the plugin zip pre-configured with this Cloud URL, without login', function (): void {
    config(['sitex.connector.public_url' => 'https://api-post.example.vn']);

    $res = $this->get('/api/v1/plugin/sitex-post.zip')->assertOk();

    expect($res->headers->get('content-type'))->toBe('application/zip')
        ->and((string) $res->headers->get('content-disposition'))->toContain('sitex-post-')->toContain('.zip');

    $main = downloadedPluginMain((string) $res->streamedContent());
    expect($main)->toContain("define( 'SITEX_POST_CLOUD_URL', 'https://api-post.example.vn' );")
        ->not->toContain('api.sitexpost.com')
        // wp-config.php can still override it.
        ->toContain("if ( ! defined( 'SITEX_POST_CLOUD_URL' ) ) {");
});

it('never writes an unsafe Cloud URL into the plugin source', function (): void {
    config(['sitex.connector.public_url' => "https://evil.example'); system('id"]);

    $this->withoutExceptionHandling();
    $this->get('/api/v1/plugin/sitex-post.zip');
})->throws(RuntimeException::class, 'Invalid connector public URL.');

it('rate limits plugin downloads per IP', function (): void {
    config(['sitex.plugin.downloads_per_minute_ip' => 2]);
    RateLimiter::clear('plugin:127.0.0.1');

    $this->get('/api/v1/plugin/sitex-post.zip')->assertOk();
    $this->get('/api/v1/plugin/sitex-post.zip')->assertOk();
    $this->get('/api/v1/plugin/sitex-post.zip')->assertStatus(429);
});
