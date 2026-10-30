<?php

namespace App\Services\Import;

use App\Models\StagedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Suggests transfer counterparts. A suggestion does not set transfer_pair_id;
 * the household confirms a match before either side is paired.
 */
class TransferMatchSuggestionService
{
    private const WINDOW_DAYS = 3;

    /**
     * @param  Collection<int, StagedTransaction>  $rows
     * @return array<string, list<array{id: string, type: 'staged'|'transaction', date: string, payee: string, amount: int, account: string, days_apart: int}>>
     */
    public function forRows(Collection $rows): array
    {
        $suggestions = $rows->mapWithKeys(fn (StagedTransaction $row): array => [$row->id => []])->all();

        $eligibleRows = $rows->filter(fn (StagedTransaction $row): bool => $this->canSuggest($row))->values();

        if ($eligibleRows->isEmpty()) {
            return $suggestions;
        }

        $eligibleRows->each(fn (StagedTransaction $row) => $row->loadMissing('statementUpload.account'));
        $upload = $eligibleRows->first()->statementUpload;

        if ($upload === null) {
            return $suggestions;
        }

        $dates = $eligibleRows->map(fn (StagedTransaction $row): CarbonImmutable => CarbonImmutable::parse($row->date)->startOfDay());
        $startDate = $dates->min()->subDays(self::WINDOW_DAYS)->toDateString();
        $endDate = $dates->max()->addDays(self::WINDOW_DAYS)->toDateString();
        $oppositeAmounts = $eligibleRows->pluck('amount')->map(fn (int $amount): int => -$amount)->unique()->values();

        $stagedSuggestions = StagedTransaction::query()
            ->with('statementUpload.account')
            ->where('status', 'pending')
            ->whereNull('transfer_pair_id')
            ->where('is_split', false)
            ->whereIn('amount', $oppositeAmounts)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->whereHas('statementUpload', function (Builder $query) use ($upload): void {
                $query->where('household_id', $upload->household_id)
                    ->where('account_id', '!=', $upload->account_id);
            })
            ->get();

        $transactionSuggestions = Transaction::query()
            ->with('account')
            ->where('household_id', $upload->household_id)
            ->where('account_id', '!=', $upload->account_id)
            ->whereIn('amount', $oppositeAmounts)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->whereNull('transfer_pair_id')
            ->where('is_split', false)
            ->get();

        foreach ($eligibleRows as $row) {
            $matches = [];

            foreach ($stagedSuggestions as $candidate) {
                if (! $this->isStagedMatch($row, $candidate)) {
                    continue;
                }

                $matches[] = $this->stagedPayload($row, $candidate);
            }

            foreach ($transactionSuggestions as $candidate) {
                if (! $this->isTransactionMatch($row, $candidate)) {
                    continue;
                }

                $matches[] = $this->transactionPayload($row, $candidate);
            }

            $suggestions[$row->id] = collect($matches)
                ->sortBy(fn (array $match): string => sprintf('%02d|%s|%s', $match['days_apart'], $match['date'], $match['payee']))
                ->take(3)
                ->values()
                ->all();
        }

        return $suggestions;
    }

    public function findStagedCandidate(StagedTransaction $row, string $candidateId): ?StagedTransaction
    {
        $candidate = StagedTransaction::query()
            ->with('statementUpload.account')
            ->find($candidateId);

        if ($candidate === null || ! $this->isStagedMatch($row, $candidate)) {
            return null;
        }

        return $candidate;
    }

    public function findTransactionCandidate(StagedTransaction $row, string $candidateId): ?Transaction
    {
        $row->loadMissing('statementUpload');

        if ($row->statementUpload === null) {
            return null;
        }

        $candidate = Transaction::query()
            ->with('account')
            ->where('household_id', $row->statementUpload->household_id)
            ->find($candidateId);

        if ($candidate === null || ! $this->isTransactionMatch($row, $candidate)) {
            return null;
        }

        return $candidate;
    }

    public function isStagedMatch(StagedTransaction $row, StagedTransaction $candidate): bool
    {
        if ($row->id === $candidate->id || $row->transfer_pair_id !== null || $candidate->transfer_pair_id !== null) {
            return false;
        }

        if (! $this->canSuggest($row) || ! $this->canSuggest($candidate)) {
            return false;
        }

        $row->loadMissing('statementUpload');
        $candidate->loadMissing('statementUpload');

        $rowUpload = $row->statementUpload;
        $candidateUpload = $candidate->statementUpload;

        if ($rowUpload === null || $candidateUpload === null) {
            return false;
        }

        if ($rowUpload->household_id !== $candidateUpload->household_id) {
            return false;
        }

        if ($rowUpload->account_id === $candidateUpload->account_id) {
            return false;
        }

        if ((int) $candidate->amount !== -$row->amount) {
            return false;
        }

        return $this->withinWindow($row->date, $candidate->date);
    }

    public function isTransactionMatch(StagedTransaction $row, Transaction $candidate): bool
    {
        if ($row->transfer_pair_id !== null || $candidate->transfer_pair_id !== null || $candidate->is_split) {
            return false;
        }

        if (! $this->canSuggest($row)) {
            return false;
        }

        $row->loadMissing('statementUpload');
        $upload = $row->statementUpload;

        if ($upload === null || $candidate->household_id !== $upload->household_id) {
            return false;
        }

        if ($candidate->account_id === $upload->account_id) {
            return false;
        }

        if ((int) $candidate->amount !== -$row->amount) {
            return false;
        }

        return $this->withinWindow($row->date, $candidate->date);
    }

    private function canSuggest(StagedTransaction $row): bool
    {
        return $row->status === 'pending'
            && $row->transfer_pair_id === null
            && ! $row->is_split
            && $row->amount !== 0;
    }

    private function withinWindow(mixed $left, mixed $right): bool
    {
        return $this->daysApart($left, $right) <= self::WINDOW_DAYS;
    }

    private function daysApart(mixed $left, mixed $right): int
    {
        return (int) CarbonImmutable::parse($left)->startOfDay()->diffInDays(
            CarbonImmutable::parse($right)->startOfDay(),
            absolute: true,
        );
    }

    /**
     * @return array{id: string, type: 'staged', date: string, payee: string, amount: int, account: string, days_apart: int}
     */
    private function stagedPayload(StagedTransaction $row, StagedTransaction $candidate): array
    {
        return [
            'id' => $candidate->id,
            'type' => 'staged',
            'date' => $candidate->date->toDateString(),
            'payee' => $candidate->payee,
            'amount' => (int) $candidate->amount,
            'account' => $candidate->statementUpload?->account?->name ?? __('Another account'),
            'days_apart' => $this->daysApart($row->date, $candidate->date),
        ];
    }

    /**
     * @return array{id: string, type: 'transaction', date: string, payee: string, amount: int, account: string, days_apart: int}
     */
    private function transactionPayload(StagedTransaction $row, Transaction $candidate): array
    {
        return [
            'id' => $candidate->id,
            'type' => 'transaction',
            'date' => $candidate->date->toDateString(),
            'payee' => $candidate->payee,
            'amount' => (int) $candidate->amount,
            'account' => $candidate->account?->name ?? __('Another account'),
            'days_apart' => $this->daysApart($row->date, $candidate->date),
        ];
    }
}
