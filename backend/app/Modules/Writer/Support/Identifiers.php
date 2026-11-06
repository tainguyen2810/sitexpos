<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

/**
 * Exact tokens that a claim and its evidence must share: model codes
 * (AX210, BE200, i7-13700H, RTX 4090), numbers with units (5.4 Gbps,
 * 160 MHz, 2 triệu đồng, 30%), versions (Wi-Fi 6E, Bluetooth 5.3, v2.1)
 * and years. "AX210" never matches "AX211".
 */
final class Identifiers
{
    private const UNITS = 'ghz|mhz|khz|hz|gbps|mbps|kbps|mb\/s|gb\/s|tb|gb|mb|kb|mah|wh|kw|w|v|mm|cm|km|kg|g|inch|in|nm|ms|°c|%|đ|đồng|vnd|vnđ|usd|\$|triệu|tỷ|nghìn|giờ|phút|giây|năm|tháng|ngày|lần|x';

    /**
     * @return list<string> normalised identifiers
     */
    public static function of(string $text): array
    {
        $out = [];
        // Model codes: letters + digits (+ optional suffix), e.g. AX210, BE200, i7-13700H, RTX 4090, M2.
        if (preg_match_all('/\b([A-Za-z]{1,6})[- ]?(\d{2,5})([A-Za-z]{0,3})\b/u', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                if (preg_match('/^(năm|thang|tháng|ngay|ngày|lan|lần|top|page|trang|bước|buoc|phần|phan)$/iu', $x[1]) === 1) {
                    continue;
                }
                $out[] = strtoupper($x[1].$x[2].$x[3]);
            }
        }
        // Standards with versions: Wi-Fi 6E, Bluetooth 5.3, USB 3.2, PCIe 4.0, DDR5, HDMI 2.1.
        if (preg_match_all('/\b(wi-?fi|bluetooth|usb|pcie|hdmi|thunderbolt|ddr|lpddr|android|ios|windows)\s?(\d+(?:\.\d+)?[a-z]?)\b/iu', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                $out[] = strtoupper(str_replace('-', '', $x[1]).$x[2]);
            }
        }
        // Numbers with units.
        if (preg_match_all('/(\d+(?:[.,]\d+)*)\s?('.self::UNITS.')(?![\p{L}])/iu', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                $out[] = self::number($x[1]).mb_strtoupper(str_replace(' ', '', $x[2]));
            }
        }
        // Explicit versions and years.
        if (preg_match_all('/\b(?:v|version|phiên bản|ver\.?)\s?(\d+(?:\.\d+){0,3})\b/iu', $text, $m) > 0) {
            foreach ($m[1] as $v) {
                $out[] = 'V'.$v;
            }
        }
        if (preg_match_all('/\b(19\d{2}|20\d{2})\b/u', $text, $m) > 0) {
            foreach ($m[1] as $y) {
                $out[] = 'Y'.$y;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Identifiers of the claim that appear in none of the excerpts.
     *
     * @param  list<string>  $excerpts
     * @return list<string>
     */
    public static function missing(string $claim, array $excerpts): array
    {
        $have = [];
        foreach ($excerpts as $e) {
            foreach (self::of($e) as $id) {
                $have[$id] = true;
            }
        }
        $joined = strtoupper((string) preg_replace('/[\s-]+/u', '', implode(' ', $excerpts)));

        return array_values(array_filter(self::of($claim), static function (string $id) use ($have, $joined): bool {
            if (isset($have[$id])) {
                return false;
            }

            // Model codes may be written differently ("AX 210") — compare without spaces/hyphens.
            return ! (preg_match('/^[A-Z]{1,6}\d{2,5}[A-Z]{0,3}$/', $id) === 1 && str_contains($joined, $id));
        }));
    }

    private static function number(string $n): string
    {
        // 1.000.000 / 1,000,000 → 1000000 ; 5,4 → 5.4
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $n) === 1) {
            return str_replace(['.', ','], '', $n);
        }

        return str_replace(',', '.', $n);
    }

    public static function isModelCode(string $id): bool
    {
        return preg_match('/^[A-Z]{1,6}\d{2,5}[A-Z]{0,3}$/', $id) === 1;
    }
}
