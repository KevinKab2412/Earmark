<?php

namespace App\Actions\Import;

use App\Models\Household;
use App\Models\StagedTransaction;
use App\Models\StatementUpload;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Transaction\TransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Promotes accepted, pending staged rows into the ledger in one atomic action:
 * either every selected valid row commits with provenance, or none does. Already
 * promoted rows are skipped, so retrying a completed promotion creates no
 * duplicate transactions (task #1249, user story 6).
 */
class PromoteStatementImport
{
    use AsAction;

    public function __construct(private TransactionService $transactions) {}

    /**
     * @return array{promoted: int, failed: int, rejected: int, pending: int}
     */
    public function handle(StatementUpload $upload, User $user, Household $household): array
    {
        $rows = $upload->stagedTransactions()->with('splits')->get();

        $rejected = $rows->where('status', 'rejected')->count();
        $selected = $rows->filter(fn (StagedTransaction $row): bool => $row->status === 'pending' && $row->accept);

        $valid = $selected->filter(fn (StagedTransaction $row): bool => $this->isValid($row))->values();
        $failed = $selected->count() - $valid->count();

        DB::transaction(function () use ($valid, $user, $household, $upload): void {
            foreach ($valid as $row) {
                $this->promoteRow($row, $user, $household, $upload);
            }

            $upload->update([
                'status' => 'imported',
                'imported_transaction_count' => $upload->stagedTransactions()->where('status', 'promoted')->count(),
            ]);
        });

        return [
            'promoted' => $valid->count(),
            'failed' => $failed,
            'rejected' => $rejected,
            'pending' => $rows->where('status', 'pending')->count() - $valid->count(),
        ];
    }

    private function isValid(StagedTransaction $row): bool
    {
        if ($row->transfer_pair_id !== null && ! $this->confirmedCounterpartExists($row)) {
            return false;
        }

        if (! $row->is_split) {
            return true;
        }

        if ($row->splits->isEmpty()) {
            return false;
        }

        return $row->splits->sum('amount') === $row->amount;
    }

    private function promoteRow(StagedTransaction $row, User $user, Household $household, StatementUpload $upload): void
    {
        if ($row->transaction_id !== null) {
            return;
        }

        $source = match ($upload->source) {
            'csv' => 'imported_csv',
            'qif' => 'imported_qif',
            'ofx', 'qfx', 'qbo' => 'imported_ofx',
            default => 'imported_pdf',
        };

        if ($row->is_split) {
            $transaction = Transaction::query()->create([
                'household_id' => $household->id,
                'account_id' => $upload->account_id,
                'date' => $row->date->toDateString(),
                'payee' => $row->payee,
                'amount' => $row->amount,
                'is_split' => true,
                'source' => $source,
                'import_batch_id' => $upload->id,
                'external_id' => $row->external_id,
                'transfer_pair_id' => $row->transfer_pair_id,
                'created_by_user_id' => $user->id,
                'cleared' => false,
            ]);

            foreach ($row->splits as $split) {
                TransactionSplit::query()->create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $split->category_id,
                    'bucket_id' => $split->bucket_id,
                    'amount' => $split->amount,
                    'memo' => $split->memo,
                ]);
            }
        } else {
            $transaction = $this->transactions->create([
                'date' => $row->date->toDateString(),
                'account_id' => $upload->account_id,
                'payee' => $row->payee,
                'category_id' => $row->final_category_id,
                'bucket_id' => $row->final_bucket_id,
                'amount' => $row->amount,
                'transfer_pair_id' => $row->transfer_pair_id,
                'source' => $source,
                'import_batch_id' => $upload->id,
                'external_id' => $row->external_id,
            ], $user, $household);
        }

        if ($row->transfer_match_transaction_id !== null && $row->transfer_pair_id !== null) {
            $paired = Transaction::query()
                ->whereKey($row->transfer_match_transaction_id)
                ->where('household_id', $household->id)
                ->whereNull('transfer_pair_id')
                ->update(['transfer_pair_id' => $row->transfer_pair_id]);

            if ($paired !== 1) {
                throw ValidationException::withMessages([
                    'transfer_match' => __('The confirmed transfer match is no longer available.'),
                ]);
            }
        }

        $row->update(['status' => 'promoted', 'transaction_id' => $transaction->id]);
    }

    private function confirmedCounterpartExists(StagedTransaction $row): bool
    {
        $row->loadMissing('statementUpload');
        $householdId = $row->statementUpload?->household_id;

        if ($householdId === null) {
            return false;
        }

        if ($row->transfer_match_staged_transaction_id !== null) {
            return StagedTransaction::query()
                ->whereKey($row->transfer_match_staged_transaction_id)
                ->where('transfer_pair_id', $row->transfer_pair_id)
                ->whereIn('status', ['pending', 'promoted'])
                ->whereHas('statementUpload', function (Builder $query) use ($householdId): void {
                    $query->where('household_id', $householdId);
                })
                ->exists();
        }

        if ($row->transfer_match_transaction_id !== null) {
            return Transaction::query()
                ->whereKey($row->transfer_match_transaction_id)
                ->where('household_id', $householdId)
                ->where(function (Builder $query) use ($row): void {
                    $query->whereNull('transfer_pair_id')
                        ->orWhere('transfer_pair_id', $row->transfer_pair_id);
                })
                ->exists();
        }

        return false;
    }
}
