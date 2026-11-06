<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Accounting;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Billing\Domain\BillingDocument;
use SiteXPost\Modules\Billing\Domain\BillingProfile;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\Refund;
use SiteXPost\Modules\Billing\Support\BillingSettings;

/**
 * Internal billing documents (receipt, refund record). They are NOT legal
 * VAT e-invoices: no e-invoice provider is integrated, and every document
 * says so (`legal_notice`). Numbering: <prefix>-<year>-<6 digits>, one per source.
 */
final class Documents
{
    public function __construct(private readonly BillingSettings $settings) {}

    public function receipt(PaymentOrder $order): BillingDocument
    {
        return $this->issue('receipt', 'RC', 'receipt:'.$order->id, $order->paid_amount_minor ?: $order->amount_minor, $order->currency, [
            'order_id' => $order->id, 'reference' => $order->reference, 'description' => $order->description,
            'purpose' => $order->purpose, 'paid_at' => $order->paid_at?->toIso8601ZuluString(), 'items' => [[
                'description' => $order->description, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency,
            ]],
        ], $order->id, null);
    }

    public function refundRecord(Refund $refund, PaymentOrder $order): BillingDocument
    {
        return $this->issue('refund_record', 'RF', 'refund:'.$refund->id, -$refund->amount_minor, $refund->currency, [
            'order_id' => $order->id, 'reference' => $order->reference, 'reason' => $refund->reason,
            'external_ref' => $refund->external_ref, 'credits_reversed_mc' => $refund->credits_reversed_mc,
        ], $order->id, $refund->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function issue(string $type, string $prefix, string $sourceKey, int $amount, string $currency, array $data, ?string $orderId, ?string $refundId): BillingDocument
    {
        $existing = BillingDocument::query()->where('source_key', $sourceKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $profile = BillingProfile::query()->first();
        $year = now()->year;
        // Sequential per type/year; the UNIQUE number + retry guards concurrent issuers.
        $count = DB::table('billing_documents')->where('type', $type)->where('number', 'like', $prefix.'-'.$year.'-%')->count();
        $doc = new BillingDocument;
        $doc->forceFill([
            'type' => $type, 'number' => sprintf('%s-%d-%06d', $prefix, $year, $count + 1), 'source_key' => $sourceKey,
            'payment_order_id' => $orderId, 'refund_id' => $refundId, 'amount_minor' => $amount, 'currency' => $currency,
            'snapshot' => $data + [
                'customer' => $profile !== null ? [
                    'company_name' => $profile->company_name, 'tax_code' => $profile->tax_code, 'address' => $profile->address,
                    'contact_name' => $profile->contact_name, 'email' => $profile->email,
                ] : null,
                'seller' => ['name' => $this->settings->get('seller_name'), 'tax_code' => $this->settings->get('seller_tax_code'), 'address' => $this->settings->get('seller_address')],
                'legal_notice' => 'internal_document_not_vat_invoice',
            ],
            'issued_at' => now(),
        ]);
        for ($try = 0; ; $try++) {
            try {
                $doc->save();

                return $doc;
            } catch (QueryException $e) {
                if ($try >= 4) {
                    throw $e;
                }
                $doc->setAttribute('number', sprintf('%s-%d-%06d', $prefix, $year, $count + 2 + $try));
            }
        }
    }
}
