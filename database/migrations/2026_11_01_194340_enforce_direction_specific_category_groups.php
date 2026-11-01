<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('category_groups', function (Blueprint $table): void {
            $table->string('direction')->nullable()->after('name');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->string('kind')->default('standard')->after('direction');
        });

        $this->assignExistingGroupDirections();
        $this->assignUngroupedCategories();
        $this->synchronizeCategoryDirections();
        DB::table('category_rollover_settings')
            ->whereIn('category_id', DB::table('categories')->where('direction', '!=', 'expense')->select('id'))
            ->delete();

        if (DB::table('categories')->whereNull('category_group_id')->exists()) {
            throw new RuntimeException('Every category must belong to a direction-specific group.');
        }

        Schema::table('category_groups', function (Blueprint $table): void {
            $table->string('direction')->default('expense')->nullable(false)->change();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->ulid('category_group_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('categories')
            ->where('kind', 'balance_adjustment')
            ->update(['direction' => 'adjustment']);

        Schema::table('categories', function (Blueprint $table): void {
            $table->ulid('category_group_id')->nullable()->change();
            $table->dropColumn('kind');
        });

        Schema::table('category_groups', function (Blueprint $table): void {
            $table->dropColumn('direction');
        });
    }

    private function assignExistingGroupDirections(): void
    {
        foreach (DB::table('category_groups')->get(['id', 'name']) as $group) {
            $directions = DB::table('categories')
                ->where('category_group_id', $group->id)
                ->pluck('direction')
                ->map(fn (string $direction): string => $direction === 'adjustment' ? 'transfer' : $direction)
                ->unique()
                ->values();

            if ($directions->count() > 1) {
                throw new RuntimeException("Category group [{$group->name}] contains mixed directions and must be separated before migration.");
            }

            $direction = $directions->first() ?? match (mb_strtolower($group->name)) {
                'income' => 'income',
                'transfer', 'transfers' => 'transfer',
                default => 'expense',
            };

            if (! in_array($direction, ['income', 'expense', 'transfer'], true)) {
                throw new RuntimeException("Category group [{$group->name}] has unsupported direction [{$direction}].");
            }

            DB::table('category_groups')->where('id', $group->id)->update(['direction' => $direction]);
        }
    }

    private function assignUngroupedCategories(): void
    {
        $categories = DB::table('categories')->whereNull('category_group_id')->get(['id', 'household_id', 'direction']);

        foreach ($categories as $category) {
            $direction = $category->direction === 'adjustment' ? 'transfer' : $category->direction;

            if (! in_array($direction, ['income', 'expense', 'transfer'], true)) {
                throw new RuntimeException("Category [{$category->id}] has unsupported direction [{$category->direction}].");
            }

            $name = match ($direction) {
                'income' => 'Income',
                'transfer' => 'Transfers',
                default => 'Other',
            };

            $group = DB::table('category_groups')
                ->where('household_id', $category->household_id)
                ->where('name', $name)
                ->first();

            if ($group === null) {
                $groupId = (string) Str::ulid();
                $sortOrder = ((int) DB::table('category_groups')->where('household_id', $category->household_id)->max('sort_order')) + 10;
                $timestamp = now();

                DB::table('category_groups')->insert([
                    'id' => $groupId,
                    'household_id' => $category->household_id,
                    'name' => $name,
                    'direction' => $direction,
                    'sort_order' => $sortOrder,
                    'archived' => false,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            } else {
                if ($group->direction !== null && $group->direction !== $direction) {
                    throw new RuntimeException("Default category group [{$name}] has the wrong direction.");
                }

                $groupId = $group->id;
                DB::table('category_groups')->where('id', $groupId)->update(['direction' => $direction]);
            }

            DB::table('categories')->where('id', $category->id)->update(['category_group_id' => $groupId]);
        }
    }

    private function synchronizeCategoryDirections(): void
    {
        foreach (DB::table('categories')->get(['id', 'category_group_id', 'direction']) as $category) {
            if ($category->direction === 'adjustment') {
                DB::table('categories')->where('id', $category->id)->update([
                    'direction' => 'transfer',
                    'kind' => 'balance_adjustment',
                ]);
            }

            $groupDirection = DB::table('category_groups')->where('id', $category->category_group_id)->value('direction');

            if ($groupDirection === null) {
                throw new RuntimeException("Category [{$category->id}] does not belong to a direction-specific group.");
            }

            DB::table('categories')->where('id', $category->id)->update(['direction' => $groupDirection]);
        }
    }
};
