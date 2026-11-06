<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Contracts;

/**
 * Phase 9 — read-only view of the billing configuration for the Setup Wizard
 * and System Health, plus the one write the wizard needs (draft plan shells).
 * Never exposes payment credentials.
 */
interface BillingReadiness
{
    /**
     * @return list<array{code: string, name: string, status: string, is_free: bool, is_demo: bool, published: bool, monthly_price_minor: ?int, yearly_price_minor: ?int}>
     */
    public function plans(): array;

    /**
     * Creates draft plans (no version, no price) for codes that do not exist yet.
     *
     * @param  array<string, string>  $codes  code => name
     * @return list<string> codes created
     */
    public function createDraftPlans(array $codes, string $staffId): array;

    /**
     * @return list<array{code: string, name: string, adapter: string, enabled: bool, configured: bool, missing: list<string>, has_credentials: bool, qr_ok: ?bool}>
     */
    public function paymentProviders(): array;

    /**
     * @return array{configured: bool, micro_usd_per_credit: ?int, markup_bps: ?int, effective_from: ?string}
     */
    public function creditPolicy(): array;

    /**
     * @return array<string, mixed>
     */
    public function settings(): array;

    /**
     * @return array{received: int, processed: int, rejected: int, last_received_at: ?string}
     */
    public function webhookHealth(\DateTimeInterface $since): array;
}
