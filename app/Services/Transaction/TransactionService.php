<?php

namespace App\Services\Transaction;

use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;

class TransactionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user, Household $household): Transaction
    {
        $data['household_id'] = $household->id;

        $data['created_by_user_id'] = $user->id;
        $data['cleared'] = (bool) ($data['cleared'] ?? false);

        return Transaction::query()->create($data);
    }
}
