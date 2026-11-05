<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use GdImage;

/**
 * Deterministic infographic renderer (GD + bundled DejaVu Sans, full
 * Vietnamese coverage). Same spec → same PNG bytes. The renderer draws only
 * the values it is given: no interpolation, no invented numbers, and a
 * source line is mandatory (or the explicit "customer provided" flag).
 */
final class InfographicRenderer
{
    public const TYPES = ['comparison', 'steps', 'features', 'timeline', 'bar_chart'];

    private const W = 1200;

    /**
     * @param  array<string, mixed>  $spec  validated by CreateInfographicRequest
     *
     * @throws MediaRejected
     */
    public function render(array $spec): string
    {
        $type = (string) $spec['type'];
        if (! in_array($type, self::TYPES, true)) {
            throw new MediaRejected('invalid_infographic');
        }
        $sources = array_values(array_filter($spec['sources'] ?? [], fn ($s) => is_array($s) && trim((string) ($s['label'] ?? '')) !== ''));
        if ($sources === [] && ($spec['customer_provided'] ?? false) !== true) {
            throw new MediaRejected('infographic_needs_source');
        }
        $palette = $this->palette($spec['brand_color'] ?? null);
        $title = (string) $spec['title'];

        $rows = match ($type) {
            'comparison' => $this->comparisonRows($spec),
            'bar_chart' => $this->barRows($spec),
            default => $this->listRows($spec),
        };
        $height = 160 + count($rows) * ($type === 'comparison' ? 70 : 90) + ($type === 'comparison' ? 70 : 0) + 90;
        $img = imagecreatetruecolor(self::W, min(4000, $height));
        imagefill($img, 0, 0, $this->color($img, '#ffffff'));
        imagefilledrectangle($img, 0, 0, self::W, 110, $this->color($img, $palette['primary']));
        $this->text($img, $title, 40, 72, 30, '#ffffff', true, self::W - 80);

        $y = 150;
        if ($type === 'comparison') {
            $y = $this->drawComparison($img, $spec, $palette, $y);
        } elseif ($type === 'bar_chart') {
            $y = $this->drawBars($img, $rows, $palette, $y, (string) ($spec['unit'] ?? ''));
        } else {
            $y = $this->drawList($img, $rows, $palette, $y, $type);
        }

        $label = $sources !== [] ? 'Nguồn: '.implode('; ', array_map(fn ($s) => trim((string) $s['label']), $sources)) : 'Nguồn: dữ liệu do khách hàng cung cấp';
        $this->text($img, mb_substr($label, 0, 160), 40, imagesy($img) - 30, 14, '#555555', false, self::W - 80);

        ob_start();
        imagepng($img, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function comparisonRows(array $spec): array
    {
        return array_values(array_filter($spec['rows'] ?? [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array{label: string, value: float}>
     */
    private function barRows(array $spec): array
    {
        $out = [];
        foreach ($spec['items'] ?? [] as $i) {
            if (is_array($i) && is_numeric($i['value'] ?? null)) {
                $out[] = ['label' => (string) ($i['label'] ?? ''), 'value' => (float) $i['value']];
            }
        }
        if ($out === []) {
            throw new MediaRejected('invalid_infographic');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array{label: string, text: string}>
     */
    private function listRows(array $spec): array
    {
        $out = [];
        foreach ($spec['items'] ?? [] as $i) {
            if (is_array($i)) {
                $out[] = ['label' => (string) ($i['label'] ?? ''), 'text' => (string) ($i['text'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array{primary: string, light: string}  $palette
     */
    private function drawComparison(GdImage $img, array $spec, array $palette, int $y): int
    {
        $cols = array_values(array_map('strval', $spec['columns'] ?? []));
        $n = max(1, count($cols));
        $colW = (int) ((self::W - 80) / $n);
        imagefilledrectangle($img, 40, $y, self::W - 40, $y + 60, $this->color($img, $palette['light']));
        foreach ($cols as $i => $c) {
            $this->text($img, $c, 52 + $i * $colW, $y + 40, 18, '#111111', true, $colW - 24);
        }
        $y += 70;
        foreach ($this->comparisonRows($spec) as $r => $row) {
            if ($r % 2 === 1) {
                imagefilledrectangle($img, 40, $y - 6, self::W - 40, $y + 58, $this->color($img, '#f5f7fa'));
            }
            foreach (array_values($row) as $i => $cell) {
                if ($i < $n) {
                    $this->text($img, (string) $cell, 52 + $i * $colW, $y + 34, 17, '#222222', $i === 0, $colW - 24);
                }
            }
            $y += 70;
        }

        return $y;
    }

    /**
     * @param  list<array{label: string, value: float}>  $rows
     * @param  array{primary: string, light: string}  $palette
     */
    private function drawBars(GdImage $img, array $rows, array $palette, int $y, string $unit): int
    {
        $max = max(array_map(fn ($r) => abs($r['value']), $rows)) ?: 1.0;
        foreach ($rows as $r) {
            $this->text($img, $r['label'], 40, $y + 30, 18, '#222222', true, 330);
            $len = (int) round((self::W - 560) * abs($r['value']) / $max);
            imagefilledrectangle($img, 390, $y + 8, 390 + max(2, $len), $y + 48, $this->color($img, $palette['primary']));
            $val = rtrim(rtrim(number_format($r['value'], 2, ',', '.'), '0'), ',').($unit !== '' ? ' '.$unit : '');
            $this->text($img, $val, 400 + max(2, $len), $y + 36, 17, '#111111', false, 200);
            $y += 90;
        }

        return $y;
    }

    /**
     * @param  list<array{label: string, text: string}>  $rows
     * @param  array{primary: string, light: string}  $palette
     */
    private function drawList(GdImage $img, array $rows, array $palette, int $y, string $type): int
    {
        foreach ($rows as $i => $r) {
            $badge = $type === 'features' ? '•' : (string) ($i + 1);
            imagefilledellipse($img, 70, $y + 30, 52, 52, $this->color($img, $palette['primary']));
            $this->text($img, $badge, $badge === '•' ? 62 : (strlen($badge) > 1 ? 56 : 63), $y + 39, 20, '#ffffff', true, 40);
            $this->text($img, $r['label'], 120, $y + 24, 20, '#111111', true, self::W - 180);
            $this->text($img, $r['text'], 120, $y + 58, 16, '#444444', false, self::W - 180);
            if ($type === 'timeline' && $i < count($rows) - 1) {
                imagesetthickness($img, 3);
                imageline($img, 70, $y + 58, 70, $y + 90, $this->color($img, $palette['primary']));
            }
            $y += 90;
        }

        return $y;
    }

    private function text(GdImage $img, string $s, int $x, int $y, int $size, string $hex, bool $bold, int $maxWidth): void
    {
        $font = $this->font($bold);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        // Truncate to fit (no wrapping: infographic rows stay one line).
        while ($s !== '' && ($box = imagettfbbox($size, 0, $font, $s)) !== false && ($box[2] - $box[0]) > $maxWidth) {
            $s = mb_substr($s, 0, mb_strlen($s) - 2).'…';
        }
        imagettftext($img, $size, 0, $x, $y, $this->color($img, $hex), $font, $s);
    }

    private function font(bool $bold): string
    {
        $dir = (string) config('sitex.media.font_dir', resource_path('fonts'));

        return $dir.'/'.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');
    }

    private function color(GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');

        return (int) imagecolorallocate($img, (int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
    }

    /**
     * @return array{primary: string, light: string}
     */
    private function palette(mixed $brand): array
    {
        $primary = is_string($brand) && preg_match('/^#[0-9a-fA-F]{6}$/', $brand) === 1 ? $brand : '#1d4ed8';

        return ['primary' => $primary, 'light' => '#e8eefc'];
    }
}
