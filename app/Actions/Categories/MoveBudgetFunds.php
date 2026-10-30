<?php

namespace App\Actions\Categories;

use App\Models\CategoryBudgetMove;
use App\Models\Household;
use App\Models\User;

class MoveBudgetFunds
{
    /**
     * @param  array{from_category_id: string, to_category_id: string, amount: int, year: int, month: int, memo: ?string}  $data
     */
    public function handle(array $data, User $user, Household $household): CategoryBudgetMove
    {
        return CategoryBudgetMove::query()->create([
            'household_id' => $household->id,
            'from_category_id' => $data['from_category_id'],
            'to_category_id' => $data['to_category_id'],
            'effective_month' => sprintf('%04d-%02d-01', $data['year'], $data['month']),
            'amount' => $data['amount_cents'],
            'memo' => $data['memo'] ?? null,
            'created_by_user_id' => $user->id,
        ]);
    }
}
