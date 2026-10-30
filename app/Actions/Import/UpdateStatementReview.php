<?php

namespace App\Actions\Import;

use App\Models\StagedTransaction;
use App\Models\StatementUpload;
use App\Models\Transaction;
use App\Services\Import\TransferMatchSuggestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Applies reviewer corrections to a statement's staged rows: field edits,
 * accept/reject state, and split allocations. Already promoted rows are left
 * untouched so provenance survives later edits (task #1249).
 */
class UpdateStatementReview
{
    use AsAction;

    public function __construct(private TransferMatchSuggestionService $transferMatches) {}

    /**
     * @param  list<array{id: string, date: string, payee: string, amount: int, final_category_id: ?string, final_bucket_id: ?string, accept: bool, status: string, splits?: list<array{category_id: ?string, bucket_id: string, amount: int, memo: ?string}>, transfer_match_type?: ?string, transfer_match_id?: ?string}>  $rows
     */
    public function handle(StatementUpload $upload, array $rows): void
    {
        DB::transaction(function () use ($upload, $rows): void {
            foreach ($rows as $data) {
                $staged = $upload->stagedTransactions()->whereKey($data['id'])->first();

                if ($staged === null || $staged->status === 'promoted') {
                    continue;
                }

                $splits = $data['splits'] ?? [];
                $isSplit = $splits !== [];

                $staged->update([
                    'date' => $data['date'],
                    'payee' => $data['payee'],
                    'amount' => $data['amount'],
                    'final_category_id' => $isSplit ? null : ($data['final_category_id'] ?? null),
                    'final_bucket_id' => $isSplit ? null : ($data['final_bucket_id'] ?? null),
                    'accept' => $data['accept'],
                    'status' => $data['status'],
                    'is_split' => $isSplit,
                ]);

                if (array_key_exists('transfer_match_id', $data) || array_key_exists('transfer_match_type', $data)) {
                    $matchId = $data['transfer_match_id'] ?? null;
                    $matchType = $data['transfer_match_type'] ?? null;

                    if ($matchId === null || $matchType === null) {
                        $this->clearTransferMatch($staged);
                    } else {
                        $this->confirmTransferMatch($staged, $matchType, $matchId);
                    }
                }

                $staged->splits()->delete();

                foreach ($splits as $split) {
                    $staged->splits()->create([
                        'category_id' => $split['category_id'] ?? null,
                        'bucket_id' => $split['bucket_id'],
                        'amount' => $split['amount'],
                        'memo' => $split['memo'] ?? null,
                    ]);
                }
            }
        });
    }

    private function confirmTransferMatch(StagedTransaction $row, string $matchType, string $matchId): void
    {
        if ($row->is_split || $row->status !== 'pending') {
            throw ValidationException::withMessages([
                'rows' => __('Split or rejected rows cannot be paired as transfers.'),
            ]);
        }

        if ($row->transfer_pair_id !== null && $this->matchesCurrentPair($row, $matchType, $matchId)) {
            return;
        }

        if ($row->transfer_pair_id !== null) {
            $this->clearTransferMatch($row);
            $row->refresh();
        }

        $pairId = (string) Str::ulid();

        if ($matchType === 'staged') {
            $candidate = $this->transferMatches->findStagedCandidate($row, $matchId);

            if ($candidate === null) {
                throw ValidationException::withMessages([
                    'rows' => __('That staged row is no longer a valid transfer match.'),
                ]);
            }

            $candidate->update([
                'transfer_pair_id' => $pairId,
                'transfer_match_staged_transaction_id' => $row->id,
                'transfer_match_transaction_id' => null,
            ]);

            $row->update([
                'transfer_pair_id' => $pairId,
                'transfer_match_staged_transaction_id' => $candidate->id,
                'transfer_match_transaction_id' => null,
            ]);

            return;
        }

        if ($matchType !== 'transaction') {
            throw ValidationException::withMessages([
                'rows' => __('Choose a valid transfer match.'),
            ]);
        }

        $candidate = $this->transferMatches->findTransactionCandidate($row, $matchId);

        if ($candidate === null) {
            throw ValidationException::withMessages([
                'rows' => __('That transaction is no longer a valid transfer match.'),
            ]);
        }

        $row->update([
            'transfer_pair_id' => $pairId,
            'transfer_match_staged_transaction_id' => null,
            'transfer_match_transaction_id' => $candidate->id,
        ]);
    }

    private function clearTransferMatch(StagedTransaction $row): void
    {
        $pairId = $row->transfer_pair_id;
        $householdId = $row->statementUpload()->value('household_id');

        if ($pairId !== null && $row->transfer_match_staged_transaction_id !== null) {
            $counterpart = StagedTransaction::query()->find($row->transfer_match_staged_transaction_id);

            if ($counterpart !== null && $counterpart->transfer_pair_id === $pairId) {
                if ($counterpart->transaction_id !== null) {
                    Transaction::query()
                        ->whereKey($counterpart->transaction_id)
                        ->where('household_id', $householdId)
                        ->where('transfer_pair_id', $pairId)
                        ->update(['transfer_pair_id' => null]);
                }

                $counterpart->update([
                    'transfer_pair_id' => null,
                    'transfer_match_staged_transaction_id' => null,
                    'transfer_match_transaction_id' => null,
                ]);
            }
        }

        if ($pairId !== null && $row->transfer_match_transaction_id !== null) {
            Transaction::query()
                ->whereKey($row->transfer_match_transaction_id)
                ->where('household_id', $householdId)
                ->where('transfer_pair_id', $pairId)
                ->update(['transfer_pair_id' => null]);
        }

        if ($pairId !== null && $row->transaction_id !== null) {
            Transaction::query()
                ->whereKey($row->transaction_id)
                ->where('household_id', $householdId)
                ->where('transfer_pair_id', $pairId)
                ->update(['transfer_pair_id' => null]);
        }

        $row->update([
            'transfer_pair_id' => null,
            'transfer_match_staged_transaction_id' => null,
            'transfer_match_transaction_id' => null,
        ]);
    }

    private function matchesCurrentPair(StagedTransaction $row, string $matchType, string $matchId): bool
    {
        return ($matchType === 'staged' && $row->transfer_match_staged_transaction_id === $matchId)
            || ($matchType === 'transaction' && $row->transfer_match_transaction_id === $matchId);
    }
}
