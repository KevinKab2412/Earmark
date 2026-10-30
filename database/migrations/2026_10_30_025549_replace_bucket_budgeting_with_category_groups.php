<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['household_id', 'name']);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignUlid('category_group_id')->nullable()->after('household_id')->constrained('category_groups')->restrictOnDelete();
            $table->date('starting_month')->nullable()->after('category_group_id');
            $table->bigInteger('starting_balance')->default(0)->after('starting_month');
            $table->string('direction')->default('expense')->after('name');
            $table->string('icon')->nullable()->after('direction');
        });

        Schema::create('category_budget_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained()->cascadeOnDelete();
            $table->date('effective_month');
            $table->bigInteger('planned_amount')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['category_id', 'effective_month']);
        });

        Schema::create('category_rollover_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained()->cascadeOnDelete();
            $table->date('effective_month');
            $table->boolean('enabled');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['category_id', 'effective_month']);
        });

        Schema::create('category_budget_moves', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('from_category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignUlid('to_category_id')->constrained('categories')->restrictOnDelete();
            $table->date('effective_month');
            $table->bigInteger('amount');
            $table->string('memo')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
            $table->index(['from_category_id', 'effective_month']);
            $table->index(['to_category_id', 'effective_month']);
        });

        Schema::table('goals', function (Blueprint $table): void {
            $table->foreignUlid('linked_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignUlid('payment_category_id')->nullable()->constrained('categories')->nullOnDelete();
        });

        $this->dropForeignColumn('goals', 'linked_bucket_id');
        $this->dropForeignColumn('payee_rules', 'bucket_id');
        $this->dropForeignColumn('staged_transaction_splits', 'bucket_id');
        $this->dropForeignColumn('staged_transactions', 'suggested_bucket_id');
        $this->dropForeignColumn('staged_transactions', 'final_bucket_id');
        $this->dropForeignColumn('transaction_splits', 'bucket_id');
        $this->dropForeignColumn('transactions', 'bucket_id');

        Schema::dropIfExists('bucket_assignments');
        Schema::dropIfExists('bucket_obligation_versions');
        Schema::dropIfExists('buckets');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('type');
        });

        Schema::getConnection()->statement(<<<'SQL'
            CREATE UNIQUE INDEX goals_one_active_savings_category
            ON goals (linked_category_id)
            WHERE type = 'save_up' AND status = 'active' AND linked_category_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The category budgeting migration cannot be reversed without losing category plans and moves.');
    }

    /**
     * SQLite refuses to drop a column while an index still uses it. Drop those
     * indexes first, then the foreign key, then the column, each in its own
     * statement so a later table rebuild cannot recreate the index.
     */
    private function dropForeignColumn(string $table, string $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['primary'] ?? false) === true) {
                    continue;
                }

                if (! in_array($column, $index['columns'], true)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropIndex($index['name']);
                });
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }
};
