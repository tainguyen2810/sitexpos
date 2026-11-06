<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use SiteXPost\Modules\Media\Domain\MediaAsset;

/**
 * Rule-based alt text / caption. Describes what the image is known to be
 * (its own alt/title, the section it illustrates) and never claims more:
 * AI images are always described and captioned as AI illustrations.
 */
final class AltText
{
    public static function suggest(MediaAsset $a, string $context): string
    {
        $base = trim((string) ($a->alt_text ?: $a->title ?: $context));
        $base = trim((string) preg_replace('/\s+/u', ' ', strip_tags($base)));
        if ($a->is_ai_generated && ! str_starts_with(mb_strtolower($base), 'minh họa')) {
            $base = 'Minh họa: '.$base;
        } elseif ($a->source_type === 'infographic' && ! str_starts_with(mb_strtolower($base), 'infographic')) {
            $base = 'Infographic: '.$base;
        }

        return mb_substr($base, 0, 125);
    }

    public static function caption(MediaAsset $a): ?string
    {
        if ($a->is_ai_generated) {
            return __('media.labels.ai_caption');
        }
        if ($a->attribution !== null && $a->attribution !== '') {
            return mb_substr($a->attribution, 0, 300);
        }

        return $a->caption;
    }
}
