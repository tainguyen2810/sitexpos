<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — billing, subscriptions, entitlements, AI credits, payments,
 * reconciliation, billing documents, revenue & cost accounting
 * (BILLING-ARCHITECTURE.md, CREDIT-LEDGER.md, PAYMENT-RECONCILIATION.md).
 *
 * Conventions: money = integer `*_minor` + `currency`; AI cost = integer
 * `*_micro_usd`; credits = integer milli-credits `*_mc` (1 credit = 1000 mc).
 * No float anywhere. Financial rows reference workspaces with RESTRICT (never
 * cascade-deleted; workspaces are soft-deleted). Ledger tables are
 * append-only (enforced in the models; no update/delete code path exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------- Catalog ----------------
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft'); // draft | active | hidden | archived
            $table->boolean('is_free')->default(false);
            $table->boolean('is_demo')->default(false); // DemoSeeder sample, never a business policy
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->ulid('current_version_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'display_order']);
        });

        // Immutable once published: subscriptions point to the exact version they bought.
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('plan_id');
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft'); // draft | published | retired
            $table->char('currency', 3);
            $table->unsignedBigInteger('monthly_price_minor')->nullable(); // null = interval not sold
            $table->unsignedBigInteger('yearly_price_minor')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedBigInteger('trial_credits_mc')->default(0);
            $table->unsignedBigInteger('included_credits_mc')->default(0); // granted every billing period
            $table->string('support_level', 32)->nullable();
            $table->json('feature_flags')->nullable(); // {"automation":true,"auto_publish":false,...}
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->unique(['plan_id', 'version']);
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->ulid('plan_version_id');
            $table->string('resource_key', 40); // websites | members | research_runs | keywords | articles | ai_images | publishing_jobs | storage_mb | automation_schedules
            $table->bigInteger('limit_value'); // -1 = unlimited, 0 = not included
            $table->string('period', 16)->default('month'); // month | total (count of existing rows)
            $table->foreign('plan_version_id')->references('id')->on('plan_versions')->cascadeOnDelete();
            $table->unique(['plan_version_id', 'resource_key']);
        });

        Schema::create('credit_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->unsignedBigInteger('credits_mc');
            $table->unsignedBigInteger('bonus_credits_mc')->default(0);
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('validity_days')->nullable(); // null = non-expiring (only if policy allows)
            $table->string('status', 16)->default('active'); // active | hidden | archived
            $table->boolean('is_demo')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        // Credit conversion policy, versioned. Provider cost (Phase 3 ai_model_prices) stays separate.
        Schema::create('credit_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedInteger('version')->unique();
            $table->unsignedBigInteger('micro_usd_per_credit'); // provider cost covered by 1 credit before markup
            $table->unsignedInteger('markup_bps')->default(0); // 10000 = +100 %
            $table->unsignedBigInteger('min_charge_mc')->default(0);
            $table->timestamp('effective_from');
            $table->text('notes')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->index('effective_from');
        });

        // ---------------- Subscriptions ----------------
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('plan_id');
            $table->ulid('plan_version_id');
            $table->string('status', 16); // TRIALING ACTIVE PAST_DUE GRACE_PERIOD PAUSED CANCELLED EXPIRED SUSPENDED
            $table->string('interval', 8)->default('month'); // month | year
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('started_at');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end')->nullable(); // null = free plan without end
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status_before_hold', 16)->nullable(); // restored on resume/unsuspend
            $table->boolean('renewal_reminders')->default(true);
            $table->string('live_key', 40)->nullable(); // = workspace_id while not ended → one live subscription per workspace
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('plan_version_id')->references('id')->on('plan_versions')->restrictOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->unique('live_key');
            $table->unique(['workspace_id', 'id']);
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->ulid('subscription_id');
            $table->string('event', 40); // created, trial_started, activated, renewed, upgraded, downgrade_scheduled, ...
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->ulid('plan_version_id')->nullable();
            $table->ulid('payment_order_id')->nullable();
            $table->string('actor_type', 16)->default('system'); // user | staff | system
            $table->ulid('actor_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at');
            $table->foreign(['workspace_id', 'subscription_id'])->references(['workspace_id', 'id'])->on('subscriptions')->restrictOnDelete();
            $table->index(['subscription_id', 'id']);
        });

        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('subscription_id');
            $table->string('kind', 16); // upgrade | downgrade | interval
            $table->ulid('from_version_id');
            $table->ulid('to_version_id');
            $table->string('to_interval', 8);
            $table->string('status', 16); // pending_payment | scheduled | applied | cancelled
            $table->timestamp('effective_at')->nullable();
            $table->bigInteger('amount_minor')->default(0);
            $table->char('currency', 3);
            $table->json('quote')->nullable(); // proration breakdown shown to the customer
            $table->ulid('payment_order_id')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->foreign(['workspace_id', 'subscription_id'])->references(['workspace_id', 'id'])->on('subscriptions')->restrictOnDelete();
            $table->index(['status', 'effective_at']);
        });

        // Trial abuse prevention: one trial per owner account / e-mail, not per workspace.
        Schema::create('trial_redemptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('user_id');
            $table->char('email_hash', 64);
            $table->ulid('workspace_id');
            $table->ulid('plan_id');
            $table->timestamp('created_at');
            $table->unique('user_id');
            $table->unique('email_hash');
        });

        // ---------------- Credits ----------------
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            // Projections of the ledger (source of truth), updated in the same
            // transaction under a row lock; verified by sitex:billing:verify-ledger.
            $table->bigInteger('balance_mc')->default(0);
            $table->unsignedBigInteger('held_mc')->default(0);
            $table->unsignedBigInteger('overdraft_limit_mc')->default(0); // approved per-tenant policy
            $table->unsignedBigInteger('low_balance_threshold_mc')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->unique('workspace_id');
            $table->unique(['workspace_id', 'id']);
        });

        Schema::create('credit_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('wallet_id');
            $table->string('source', 16); // included | trial | purchased | promotional | bonus | refund | adjustment
            $table->unsignedBigInteger('amount_mc');
            $table->unsignedBigInteger('remaining_mc');
            $table->timestamp('expires_at')->nullable();
            $table->string('source_ref', 120); // e.g. order:<id>, sub:<id>:<period>, adj:<id> — UNIQUE = never granted twice
            $table->unsignedBigInteger('price_minor')->default(0); // cash paid for this grant (revenue recognition)
            $table->char('currency', 3)->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
            $table->foreign(['workspace_id', 'wallet_id'])->references(['workspace_id', 'id'])->on('credit_wallets')->restrictOnDelete();
            $table->unique(['workspace_id', 'source_ref']);
            $table->index(['wallet_id', 'remaining_mc', 'expires_at']);
        });

        Schema::create('credit_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('wallet_id');
            $table->string('idempotency_key', 191)->unique(); // = AI Gateway budget reservation key (call key + attempt)
            $table->string('purpose', 64); // AI task key or other metered action
            $table->string('category', 24)->nullable(); // research | writing | fact_check | image | other
            $table->ulid('policy_id')->nullable(); // conversion policy frozen at reservation time
            $table->unsignedBigInteger('estimate_micro_usd')->default(0);
            $table->unsignedBigInteger('amount_mc');
            $table->string('status', 16)->default('held'); // held | settled | released | uncertain | expired
            $table->unsignedBigInteger('actual_micro_usd')->nullable();
            $table->unsignedBigInteger('charged_mc')->nullable();
            $table->unsignedBigInteger('uncollected_mc')->default(0); // cost above what the wallet could cover (absorbed, visible in profitability)
            $table->boolean('needs_review')->default(false); // provider outcome unknown → charged at estimate, reviewable
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->foreign(['workspace_id', 'wallet_id'])->references(['workspace_id', 'id'])->on('credit_wallets')->restrictOnDelete();
            $table->index(['status', 'expires_at']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->ulid('wallet_id');
            $table->ulid('grant_id')->nullable();
            $table->string('entry_type', 16); // grant | consume | expire | refund | adjust | reversal
            $table->bigInteger('amount_mc'); // signed
            $table->bigInteger('balance_after_mc');
            $table->string('idempotency_key', 191)->unique();
            $table->string('reference_type', 32)->nullable(); // reservation | payment_order | adjustment | refund | subscription
            $table->string('reference_id', 64)->nullable();
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->ulid('actor_id')->nullable();
            $table->timestamp('created_at');
            $table->foreign(['workspace_id', 'wallet_id'])->references(['workspace_id', 'id'])->on('credit_wallets')->restrictOnDelete();
            $table->index(['wallet_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('credit_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->bigInteger('amount_mc'); // signed
            $table->string('kind', 16); // promotional | bonus | correction | refund
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->ulid('created_by');
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });

        // Counted usage per period (articles, research runs, AI images, publishing jobs, keywords).
        Schema::create('usage_meters', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->string('resource_key', 40);
            $table->date('period_start');
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'resource_key', 'period_start']);
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->string('resource_key', 40);
            $table->date('period_start');
            $table->integer('amount'); // + consume, − release
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ---------------- Payments ----------------
        Schema::create('payment_provider_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('adapter', 40); // manual_bank | signed_webhook
            $table->string('name', 120);
            $table->boolean('is_enabled')->default(false);
            $table->json('settings')->nullable(); // non-secret: bank_bin, account_no, account_name, tolerance...
            $table->text('credentials')->nullable(); // encrypted JSON (webhook secret / API key), never returned by the API
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->string('purpose', 24); // subscription | renewal | upgrade | credit_package
            $table->ulid('plan_version_id')->nullable();
            $table->string('interval', 8)->nullable();
            $table->ulid('credit_package_id')->nullable();
            $table->ulid('subscription_id')->nullable();
            $table->ulid('subscription_change_id')->nullable();
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider', 40);
            $table->string('reference', 24)->unique(); // transfer memo, e.g. SXP7K2M9QX4
            $table->string('status', 20)->default('PENDING'); // PENDING PROCESSING PAID EXPIRED CANCELLED FAILED REFUNDED PARTIALLY_REFUNDED
            $table->unsignedBigInteger('paid_amount_minor')->default(0);
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0); // payment processing fee (cost accounting)
            $table->string('idempotency_key', 100)->nullable();
            $table->json('snapshot')->nullable(); // plan / package / quote frozen at order time
            $table->ulid('created_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->unique(['workspace_id', 'id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 120);
            $table->boolean('signature_valid');
            $table->char('payload_hash', 64);
            $table->json('payload')->nullable(); // normalized, no secrets
            $table->string('status', 16)->default('received'); // received | processed | rejected | duplicate
            $table->string('error', 255)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 40);
            $table->string('external_id', 120);
            $table->string('direction', 8)->default('in');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('description', 500)->nullable();
            $table->string('counterparty', 191)->nullable();
            $table->timestamp('occurred_at');
            $table->string('match_status', 16)->default('unmatched'); // matched | unmatched | mismatch | duplicate | ignored
            $table->string('match_reason', 64)->nullable();
            $table->ulid('payment_order_id')->nullable();
            $table->ulid('workspace_id')->nullable(); // set when matched (platform table, not tenant-scoped)
            $table->unsignedBigInteger('webhook_event_id')->nullable();
            $table->ulid('recorded_by')->nullable(); // staff for manual entries
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->index(['match_status', 'occurred_at']);
        });

        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->ulid('payment_transaction_id')->nullable();
            $table->ulid('payment_order_id')->nullable();
            $table->string('result', 24); // auto_matched | manual_approved | manual_rejected | amount_mismatch | reference_not_found | order_expired | duplicate | ignored
            $table->bigInteger('difference_minor')->default(0);
            $table->ulid('reviewed_by')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index('payment_order_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('payment_order_id');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('credits_reversed_mc')->default(0);
            $table->string('status', 16)->default('requested'); // requested | processing | succeeded | failed | cancelled
            $table->string('reason', 500);
            $table->string('external_ref', 120)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->ulid('requested_by');
            $table->ulid('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->foreign(['workspace_id', 'payment_order_id'])->references(['workspace_id', 'id'])->on('payment_orders')->restrictOnDelete();
        });

        // ---------------- Documents & profile ----------------
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->string('company_name', 191)->nullable();
            $table->string('tax_code', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique('workspace_id');
        });

        // Internal documents only — NOT a legal VAT e-invoice (no e-invoice provider integrated).
        Schema::create('billing_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->string('type', 24); // receipt | order_confirmation | refund_record
            $table->string('number', 32)->unique(); // RC-2026-000123
            $table->ulid('payment_order_id')->nullable();
            $table->ulid('refund_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->json('snapshot'); // billing profile, items, amounts at issue time
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->string('source_key', 120)->unique(); // receipt:<order> / refund:<refund> — one document per source
        });

        Schema::create('billing_notifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->string('type', 40);
            $table->string('dedupe_key', 191)->unique(); // anti duplicate sends
            $table->json('data')->nullable();
            $table->timestamp('sent_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ---------------- Accounting ----------------
        Schema::create('revenue_records', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->string('kind', 16); // subscription | credits | refund | fee
            $table->ulid('payment_order_id')->nullable();
            $table->ulid('refund_id')->nullable();
            $table->ulid('plan_id')->nullable();
            $table->bigInteger('amount_minor'); // cash collected (negative for refunds/fees)
            $table->bigInteger('discount_minor')->default(0);
            $table->char('currency', 3);
            // Recognition window: subscription revenue is recognised linearly over
            // the service period; credit revenue when credits are consumed.
            $table->timestamp('service_start')->nullable();
            $table->timestamp('service_end')->nullable();
            $table->ulid('credit_grant_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            $table->string('source_key', 120)->unique(); // order:<id> / refund:<id> — recorded once
            $table->index(['workspace_id', 'occurred_at']);
        });

        // Daily AI cost snapshot copied from ai_requests (pruned after 90 days) + fees.
        Schema::create('cost_records', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->date('day');
            $table->string('category', 24); // research | writing | fact_check | image | other
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedBigInteger('uncertain_micro_usd')->default(0);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'day', 'category', 'provider', 'model'], 'cost_records_unique');
            $table->index('day');
        });
    }

    public function down(): void
    {
        foreach ([
            'cost_records', 'revenue_records', 'billing_notifications', 'billing_documents', 'billing_profiles',
            'refunds', 'payment_reconciliations', 'payment_transactions', 'payment_webhook_events', 'payment_orders',
            'payment_provider_configs', 'usage_events', 'usage_meters', 'credit_adjustments', 'credit_ledger_entries',
            'credit_reservations', 'credit_grants', 'credit_wallets', 'trial_redemptions', 'subscription_changes',
            'subscription_events', 'subscriptions', 'credit_policies', 'credit_packages', 'plan_entitlements',
            'plan_versions', 'plans', 'billing_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
