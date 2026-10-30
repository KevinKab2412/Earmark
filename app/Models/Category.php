<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'household_id',
        'category_group_id',
        'starting_month',
        'starting_balance',
        'name',
        'direction',
        'icon',
        'sort_order',
        'archived',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived' => 'boolean',
            'starting_month' => 'date',
            'starting_balance' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<CategoryGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CategoryGroup::class, 'category_group_id');
    }

    /**
     * @return HasMany<CategoryBudgetPlan, $this>
     */
    public function budgetPlans(): HasMany
    {
        return $this->hasMany(CategoryBudgetPlan::class);
    }

    /**
     * @return HasMany<CategoryRolloverSetting, $this>
     */
    public function rolloverSettings(): HasMany
    {
        return $this->hasMany(CategoryRolloverSetting::class);
    }

    /**
     * @return HasMany<CategoryBudgetMove, $this>
     */
    public function budgetMovesFrom(): HasMany
    {
        return $this->hasMany(CategoryBudgetMove::class, 'from_category_id');
    }

    /**
     * @return HasMany<CategoryBudgetMove, $this>
     */
    public function budgetMovesTo(): HasMany
    {
        return $this->hasMany(CategoryBudgetMove::class, 'to_category_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<TransactionSplit, $this>
     */
    public function transactionSplits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }
}
