<?php

namespace App\Services\Reporting;

use App\Models\Household;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical, server-side reporting queries. Totals are computed from the same
 * filtered transaction rows the drilldown returns, so a report always reconciles
 * to its rows. Transfers and rows hidden by rules (excluded_from_reports) are
 * omitted (task #1255).
 */
class ReportingService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{income_cents: int, expense_cents: int, net_cents: int}
     */
    public function cashFlow(Household $household, array $filters): array
    {
        if (empty($filters['category_id'])) {
            $amounts = $this->query($household, $filters)->pluck('amount');
        } else {
            $amounts = $this->query($household, $filters)
                ->where('is_split', false)
                ->pluck('amount');

            $splitAmounts = TransactionSplit::query()
                ->where('category_id', $filters['category_id'])
                ->whereHas('transaction', function ($query) use ($household, $filters): void {
                    $query->where('household_id', $household->id)
                        ->whereNull('transfer_pair_id')
                        ->where('excluded_from_reports', false)
                        ->when(! empty($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
                        ->when(! empty($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
                        ->when(! empty($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']));
                })
                ->pluck('amount');

            $amounts = $amounts->concat($splitAmounts);
        }
        $income = (int) $amounts->filter(fn (int $a): bool => $a > 0)->sum();
        $expense = (int) $amounts->filter(fn (int $a): bool => $a < 0)->sum();

        return [
            'income_cents' => $income,
            'expense_cents' => $expense,
            'net_cents' => $income + $expense,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{category: string, total_cents: int}>
     */
    public function byCategory(Household $household, array $filters, string $direction): array
    {
        $isIncome = $direction === 'income';
        $matchesDirection = function ($query) use ($direction): void {
            $query->whereNull('category_id')
                ->orWhereHas('category.group', fn ($groupQuery) => $groupQuery->where('direction', $direction));
        };

        $transactions = $this->query($household, $filters)
            ->where('is_split', false)
            ->where($matchesDirection)
            ->when($isIncome, fn ($query) => $query->where('amount', '>', 0), fn ($query) => $query->where('amount', '<', 0))
            ->with('category:id,name')
            ->get(['id', 'category_id', 'amount']);

        $splits = TransactionSplit::query()
            ->where('amount', $isIncome ? '>' : '<', 0)
            ->where($matchesDirection)
            ->whereHas('transaction', function ($query) use ($household, $filters): void {
                $query->where('household_id', $household->id)
                    ->whereNull('transfer_pair_id')
                    ->where('excluded_from_reports', false)
                    ->when(! empty($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
                    ->when(! empty($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
                    ->when(! empty($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']));
            })
            ->when(! empty($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->with('category:id,name')
            ->get(['category_id', 'amount']);

        return $transactions->map(fn (Transaction $transaction): array => [
            'category' => $transaction->category?->name ?? 'Uncategorized',
            'amount' => (int) $transaction->amount,
        ])->concat($splits->map(fn (TransactionSplit $split): array => [
            'category' => $split->category?->name ?? 'Uncategorized',
            'amount' => (int) $split->amount,
        ]))
            ->groupBy('category')
            ->map(fn ($group, string $name): array => [
                'category' => $name,
                'total_cents' => (int) $group->sum('amount'),
            ])
            ->sortBy('total_cents')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: string, date: string, account: ?string, payee: string, category: ?string, amount_cents: int}>
     */
    public function drilldown(Household $household, array $filters, int $limit = 500): array
    {
        if (empty($filters['category_id'])) {
            return $this->query($household, $filters)
                ->with(['account:id,name', 'category:id,name'])
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn (Transaction $t): array => [
                    'id' => $t->id,
                    'date' => $t->date->toDateString(),
                    'account' => $t->account?->name,
                    'payee' => $t->payee,
                    'category' => $t->category?->name,
                    'amount_cents' => $t->amount,
                ])
                ->all();
        }

        $transactions = $this->query($household, $filters)
            ->where('is_split', false)
            ->with(['account:id,name', 'category:id,name'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'account' => $transaction->account?->name,
                'payee' => $transaction->payee,
                'category' => $transaction->category?->name,
                'amount_cents' => $transaction->amount,
            ]);

        $splits = TransactionSplit::query()
            ->where('category_id', $filters['category_id'])
            ->whereHas('transaction', function ($query) use ($household, $filters): void {
                $query->where('household_id', $household->id)
                    ->whereNull('transfer_pair_id')
                    ->where('excluded_from_reports', false)
                    ->when(! empty($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
                    ->when(! empty($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
                    ->when(! empty($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']));
            })
            ->with(['transaction.account:id,name', 'transaction', 'category:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (TransactionSplit $split): array => [
                'id' => $split->id,
                'date' => $split->transaction?->date->toDateString() ?? '',
                'account' => $split->transaction?->account?->name,
                'payee' => $split->transaction?->payee ?? '',
                'category' => $split->category?->name,
                'amount_cents' => $split->amount,
            ]);

        return $transactions->concat($splits)
            ->sortByDesc('date')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function query(Household $household, array $filters): Builder
    {
        return Transaction::query()
            ->where('household_id', $household->id)
            ->whereNull('transfer_pair_id')
            ->where('excluded_from_reports', false)
            ->where(function (Builder $query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category.group', fn (Builder $groupQuery) => $groupQuery->whereIn('direction', ['income', 'expense']));
            })
            ->when(! empty($filters['from']), fn (Builder $q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn (Builder $q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(! empty($filters['account_id']), fn (Builder $q) => $q->where('account_id', $filters['account_id']))
            ->when(! empty($filters['category_id']), fn (Builder $q) => $q->where('category_id', $filters['category_id']));
    }
}
