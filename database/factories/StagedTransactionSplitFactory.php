<?php

namespace Database\Factories;

use App\Models\StagedTransaction;
use App\Models\StagedTransactionSplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StagedTransactionSplit>
 */
class StagedTransactionSplitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staged_transaction_id' => StagedTransaction::factory(),
            'category_id' => null,
            'amount' => fake()->numberBetween(-50_000, 50_000) ?: -1000,
            'memo' => null,
        ];
    }
}
