<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Sources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\Research\Support\ResearchSettings;

/**
 * DataForSEO keyword provider (verified against docs.dataforseo.com
 * 2026-11-05): HTTP Basic auth "login:password" (Super Admin enters it in
 * Admin → Research, stored encrypted), live endpoints:
 *  - keywords_data/google_ads/search_volume/live → tasks[0].result[] {keyword, search_volume, cpc, monthly_searches}
 *  - dataforseo_labs/google/bulk_keyword_difficulty/live → tasks[0].result[0].items[] {keyword, keyword_difficulty}
 * Live endpoints allow 12 requests/minute per account.
 */
class DataForSeoClient
{
    private const BASE = 'https://api.dataforseo.com/v3';

    /** ISO country → DataForSEO location_code (Google Ads geo target ids). */
    public const LOCATIONS = ['VN' => 2704, 'US' => 2840, 'GB' => 2826, 'AU' => 2036, 'SG' => 2702, 'TH' => 2764, 'JP' => 2392, 'KR' => 2410];

    public function __construct(private readonly ResearchSettings $settings) {}

    public function isAvailable(): bool
    {
        $row = $this->settings->source('dataforseo');

        return $row !== null && $row->is_enabled && $row->hasSecret();
    }

    /**
     * @param  list<string>  $keywords
     * @return array<string, array{search_volume: int|null, cpc_micro_usd: int|null, trend: list<array{month: string, volume: int}>|null}>
     *
     * @throws ProviderUnavailable
     */
    public function searchVolume(array $keywords, string $country, string $language): array
    {
        $data = $this->post('/keywords_data/google_ads/search_volume/live', [[
            'keywords' => array_values(array_slice($keywords, 0, 1000)),
            'location_code' => self::LOCATIONS[strtoupper($country)] ?? throw new ProviderUnavailable('unsupported_location', 'Location not mapped for DataForSEO'),
            'language_code' => $language,
        ]]);
        $out = [];
        foreach ((array) ($data['tasks'][0]['result'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['keyword'] ?? null)) {
                continue;
            }
            $trend = [];
            foreach ((array) ($row['monthly_searches'] ?? []) as $m) {
                if (is_array($m) && isset($m['year'], $m['month'])) {
                    $trend[] = ['month' => sprintf('%04d-%02d', (int) $m['year'], (int) $m['month']), 'volume' => (int) ($m['search_volume'] ?? 0)];
                }
            }
            $out[mb_strtolower($row['keyword'])] = [
                'search_volume' => isset($row['search_volume']) ? (int) $row['search_volume'] : null,
                'cpc_micro_usd' => isset($row['cpc']) ? (int) round((float) $row['cpc'] * 1_000_000) : null,
                'trend' => $trend !== [] ? array_slice($trend, 0, 24) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $keywords
     * @return array<string, int>
     *
     * @throws ProviderUnavailable
     */
    public function difficulty(array $keywords, string $country, string $language): array
    {
        $data = $this->post('/dataforseo_labs/google/bulk_keyword_difficulty/live', [[
            'keywords' => array_values(array_slice($keywords, 0, 1000)),
            'location_code' => self::LOCATIONS[strtoupper($country)] ?? throw new ProviderUnavailable('unsupported_location', 'Location not mapped for DataForSEO'),
            'language_code' => $language,
        ]]);
        $out = [];
        foreach ((array) ($data['tasks'][0]['result'][0]['items'] ?? []) as $item) {
            if (is_array($item) && is_string($item['keyword'] ?? null) && isset($item['keyword_difficulty'])) {
                $out[mb_strtolower($item['keyword'])] = max(0, min(100, (int) $item['keyword_difficulty']));
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $body
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    protected function post(string $path, array $body): array
    {
        $row = $this->settings->source('dataforseo');
        $secret = (string) ($row->secret_encrypted ?? '');
        if (! $this->isAvailable() || ! str_contains($secret, ':')) {
            throw new ProviderUnavailable('not_configured', 'DataForSEO is not configured');
        }
        [$login, $password] = explode(':', $secret, 2);
        try {
            $res = Http::withBasicAuth($login, $password)->acceptJson()->timeout($this->settings->timeout('dataforseo'))->post(self::BASE.$path, $body);
        } catch (ConnectionException) {
            throw new ProviderUnavailable('unreachable', 'DataForSEO unreachable');
        }
        $json = (array) $res->json();
        if (! $res->successful() || (int) ($json['status_code'] ?? 0) !== 20000) {
            throw new ProviderUnavailable('provider_error', 'DataForSEO error '.($json['status_code'] ?? $res->status()));
        }
        $taskStatus = (int) ($json['tasks'][0]['status_code'] ?? 20000);
        if ($taskStatus !== 20000) {
            throw new ProviderUnavailable('provider_task_error', 'DataForSEO task error '.$taskStatus);
        }

        return $json;
    }
}
