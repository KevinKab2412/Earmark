<?php

namespace App\Services\Portability;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portable, no-proprietary-dependency export and restore of a household's
 * canonical data. The export is a versioned JSON manifest plus per-table rows;
 * restore reproduces the core financial entities into a target household,
 * remapping identifiers and dropping instance-specific user references. Secrets
 * (users, auth) are never included (task #1262).
 */
class PortabilityService
{
    public const SCHEMA_VERSION = '2';

    /** Tables owned directly by a household (have household_id). */
    private const HOUSEHOLD_TABLES = [
        'category_groups', 'categories', 'accounts', 'financial_positions', 'household_plans',
        'category_budget_moves', 'goals', 'holdings', 'scenarios', 'payee_rules', 'recurring_schedules', 'saved_reports',
    ];

    /** Child tables reached through a parent, with the parent linkage for scoping. */
    private const CHILD_TABLES = [
        'transactions' => null, // has household_id too, but restored after accounts/categories
        'transaction_splits' => ['parent' => 'transactions', 'key' => 'transaction_id'],
        'valuations' => ['parent' => 'financial_positions', 'key' => 'financial_position_id'],
        'contribution_phases' => ['parent' => 'household_plans', 'key' => 'household_plan_id'],
        'category_budget_plans' => ['parent' => 'categories', 'key' => 'category_id'],
        'category_rollover_settings' => ['parent' => 'categories', 'key' => 'category_id'],
    ];

    /** Restore order (parents before children) and the FK columns to remap. */
    private const RESTORE = [
        'category_groups' => [],
        'categories' => ['category_group_id' => 'category_groups'],
        'accounts' => [],
        'financial_positions' => [],
        'household_plans' => [],
        'category_budget_plans' => ['category_id' => 'categories'],
        'category_rollover_settings' => ['category_id' => 'categories'],
        'category_budget_moves' => ['from_category_id' => 'categories', 'to_category_id' => 'categories'],
        'transactions' => ['account_id' => 'accounts', 'category_id' => 'categories'],
        'contribution_phases' => ['household_plan_id' => 'household_plans'],
        'transaction_splits' => ['transaction_id' => 'transactions', 'category_id' => 'categories'],
        'valuations' => ['financial_position_id' => 'financial_positions'],
        'goals' => ['linked_account_id' => 'accounts', 'linked_category_id' => 'categories', 'payment_category_id' => 'categories', 'linked_position_id' => 'financial_positions'],
        'holdings' => ['account_id' => 'accounts'],
        'scenarios' => [],
    ];

    private const USER_COLUMNS = ['created_by_user_id', 'owner_user_id', 'reconciled_by_user_id', 'uploaded_by_user_id'];

    /**
     * @return array{manifest: array<string, mixed>, data: array<string, array<int, array<string, mixed>>>}
     */
    public function export(Household $household, ?string $exportedAt = null): array
    {
        $data = [];

        foreach (self::HOUSEHOLD_TABLES as $table) {
            $data[$table] = DB::table($table)->where('household_id', $household->id)->get()
                ->map(fn ($row): array => (array) $row)->all();
        }

        $data['transactions'] = DB::table('transactions')->where('household_id', $household->id)->get()
            ->map(fn ($row): array => (array) $row)->all();

        foreach (self::CHILD_TABLES as $table => $link) {
            if ($link === null) {
                continue;
            }

            $parentIds = collect($data[$link['parent']])->pluck('id');
            $data[$table] = DB::table($table)->whereIn($link['key'], $parentIds)->get()
                ->map(fn ($row): array => (array) $row)->all();
        }

        $counts = collect($data)->map(fn (array $rows): int => count($rows))->all();

        return [
            'manifest' => [
                'schema_version' => self::SCHEMA_VERSION,
                'exported_at' => $exportedAt,
                'household' => $household->name,
                'counts' => $counts,
            ],
            'data' => $data,
        ];
    }

    /**
     * Restore a bundle's core entities into the target household, replacing its
     * existing data. Returns per-table counts restored.
     *
     * @param  array{data?: array<string, array<int, array<string, mixed>>>}  $bundle
     * @return array<string, int>
     */
    public function restore(Household $household, array $bundle, User $user): array
    {
        $data = $bundle['data'] ?? [];
        $maps = [];
        $counts = [];

        DB::transaction(function () use ($household, $data, $user, &$maps, &$counts): void {
            $this->wipe($household);

            foreach (self::RESTORE as $table => $foreignKeys) {
                $maps[$table] = [];
                $counts[$table] = 0;

                foreach ($data[$table] ?? [] as $row) {
                    $row = (array) $row;
                    $oldId = $row['id'] ?? null;
                    $row['id'] = (string) Str::ulid();

                    if ($oldId !== null) {
                        $maps[$table][$oldId] = $row['id'];
                    }

                    if (array_key_exists('household_id', $row)) {
                        $row['household_id'] = $household->id;
                    }

                    foreach ($foreignKeys as $column => $sourceTable) {
                        if (! empty($row[$column])) {
                            $row[$column] = $maps[$sourceTable][$row[$column]] ?? null;
                        }
                    }

                    foreach (self::USER_COLUMNS as $column) {
                        if (array_key_exists($column, $row)) {
                            $row[$column] = $column === 'owner_user_id' ? null : $user->id;
                        }
                    }

                    DB::table($table)->insert($row);
                    $counts[$table]++;
                }
            }
        });

        return $counts;
    }

    public function wipe(Household $household): void
    {
        $accountIds = DB::table('accounts')->where('household_id', $household->id)->pluck('id');
        $positionIds = DB::table('financial_positions')->where('household_id', $household->id)->pluck('id');
        $planIds = DB::table('household_plans')->where('household_id', $household->id)->pluck('id');
        $transactionIds = DB::table('transactions')->where('household_id', $household->id)->pluck('id');

        DB::table('transaction_splits')->whereIn('transaction_id', $transactionIds)->delete();
        DB::table('valuations')->whereIn('financial_position_id', $positionIds)->delete();
        DB::table('contribution_phases')->whereIn('household_plan_id', $planIds)->delete();

        foreach (['transactions', 'goals', 'holdings', 'scenarios', 'category_budget_moves', 'financial_positions', 'household_plans', 'accounts', 'categories', 'category_groups'] as $table) {
            DB::table($table)->where('household_id', $household->id)->delete();
        }
    }
}
