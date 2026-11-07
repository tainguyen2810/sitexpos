<?php

use SiteXPost\Modules\Writer\Enums\RiskLevel;
use SiteXPost\Modules\Writer\Enums\Verdict;
use SiteXPost\Modules\Writer\Pipeline\VerdictRules;
use SiteXPost\Modules\Writer\Support\SourceFilter;

it('normalizes domains the way people type them', function () {
    expect(SourceFilter::normalizeDomain('https://www.Example.com/path?a=1'))->toBe('example.com')
        ->and(SourceFilter::normalizeDomain('*.docs.example.com.vn'))->toBe('docs.example.com.vn')
        ->and(SourceFilter::normalizeDomain('example.com.'))->toBe('example.com')
        ->and(SourceFilter::normalizeDomain('example.com:8080/x'))->toBe('example.com')
        ->and(SourceFilter::normalizeDomain('localhost'))->toBeNull()
        ->and(SourceFilter::normalizeDomain('not a domain'))->toBeNull()
        ->and(SourceFilter::normalizeDomain('192.168.1.1'))->toBeNull()
        ->and(SourceFilter::normalizeDomain(''))->toBeNull();
});

it('matches a domain and its subdomains only', function () {
    expect(SourceFilter::matches('docs.example.com', ['example.com']))->toBeTrue()
        ->and(SourceFilter::matches('www.example.com', ['example.com']))->toBeTrue()
        ->and(SourceFilter::matches('badexample.com', ['example.com']))->toBeFalse()
        ->and(SourceFilter::matches('example.com.evil.net', ['example.com']))->toBeFalse();
});

it('groups URLs by website to count independent sources', function () {
    expect(SourceFilter::site('https://docs.intel.com/a'))->toBe('intel.com')
        ->and(SourceFilter::site('https://www.thegioididong.com.vn/x'))->toBe('thegioididong.com.vn')
        ->and(SourceFilter::site('https://a.b.example.co.uk/'))->toBe('example.co.uk');
});

it('needs two independent websites or one official source for high-risk claims', function () {
    $claim = 'Intel AX210 hỗ trợ băng tần 6 GHz';
    $e = fn (string $url, string $type = 'reputable') => ['ref' => 1, 'excerpt' => 'The Intel AX210 supports the 6 GHz band.', 'reliability' => 80, 'date' => '2025-05-01', 'url' => $url, 'type' => $type];
    $d = fn (array $sup, RiskLevel $r = RiskLevel::High) => VerdictRules::decide($claim, 'spec', $r, 'VERIFIED', $sup, [], 60, 24, 2);

    $one = $d([$e('https://review.example/a'), $e('https://www.review.example/b')]);
    expect($one['verdict'])->toBe(Verdict::NeedsHumanReview)->and($one['notes'][0])->toBe('needs_independent_sources: 1/2');
    expect($d([$e('https://review.example/a'), $e('https://other.example/b')])['verdict'])->toBe(Verdict::Verified)
        ->and($d([$e('https://intel.example/a', 'manufacturer')])['verdict'])->toBe(Verdict::Verified)
        ->and($d([$e('https://chinhphu.vn/a', 'regulator')], RiskLevel::Critical)['verdict'])->toBe(Verdict::Verified)
        // MEDIUM / LOW claims and the "off" setting keep the single-source rule.
        ->and($d([$e('https://review.example/a')], RiskLevel::Medium)['verdict'])->toBe(Verdict::Verified)
        ->and(VerdictRules::decide($claim, 'spec', RiskLevel::High, 'VERIFIED', [$e('https://review.example/a')], [], 60, 24, 1)['verdict'])->toBe(Verdict::Verified);
});
