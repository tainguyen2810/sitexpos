<?php

namespace Tests\Support;

use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Providers\ProviderAdapter;
use SiteXPost\Modules\AiGateway\Providers\ProviderRequest;
use SiteXPost\Modules\AiGateway\Providers\ProviderResponse;
use SiteXPost\Modules\AiGateway\Providers\Usage;

/**
 * Test-only image model: returns real PNG bytes (the built-in fake returns
 * a placeholder string that the Media pipeline correctly rejects).
 * Bind with app()->instance(FakeProvider::class, new FakeImageProvider).
 */
final class FakeImageProvider implements ProviderAdapter
{
    /** @var list<ProviderRequest> */
    public array $requests = [];

    public function code(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function forProvider(AiProvider $provider): static
    {
        return $this;
    }

    public function listModels(int $timeoutSeconds = 15): array
    {
        return [];
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $images = [];
        for ($i = 0; $i < max(1, $request->imageCount); $i++) {
            $images[] = base64_encode(self::png(640, 360, [40 + 50 * $i, 120, 200]));
        }

        return new ProviderResponse('', new Usage(inputTokens: 100, images: $request->imageCount), 'stop', 'fakeimg_'.count($this->requests), 200, $images);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function png(int $w, int $h, array $rgb = [30, 144, 255]): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, (int) imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
        imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w / 3), (int) ($h / 3), (int) imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
