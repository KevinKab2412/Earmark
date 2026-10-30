<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A suggestion is computed, not stored. These columns remember a confirmed
 * match: transfer_pair_id stays null until the household confirms one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staged_transactions', function (Blueprint $table): void {
            $table->ulid('transfer_pair_id')->nullable()->index();
            $table->foreignUlid('transfer_match_staged_transaction_id')->nullable()
                ->constrained('staged_transactions')->nullOnDelete();
            $table->foreignUlid('transfer_match_transaction_id')->nullable()
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staged_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_match_transaction_id');
            $table->dropConstrainedForeignId('transfer_match_staged_transaction_id');
            $table->dropIndex(['transfer_pair_id']);
            $table->dropColumn('transfer_pair_id');
        });
    }
};
