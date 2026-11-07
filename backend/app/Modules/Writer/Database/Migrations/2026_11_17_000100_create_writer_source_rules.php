<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source filter of a workspace (FACT-CHECK-POLICY.md §7). Additive only.
 * One row per domain: `prefer` raises the reliability score and is
 * suggested to the AI first; `block` is never fetched or used as evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writer_source_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->string('domain', 253);
            $table->string('mode', 16);
            $table->string('note', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'domain']);
            $table->index(['workspace_id', 'mode']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writer_source_rules');
    }
};
