<?php

namespace App\Models;

use Database\Factories\CategoryBudgetMoveFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryBudgetMove extends Model
{
    /** @use HasFactory<CategoryBudgetMoveFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'household_id',
        'from_category_id',
        'to_category_id',
        'effective_month',
        'amount',
        'memo',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['effective_month' => 'date', 'amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function fromCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'from_category_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function toCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'to_category_id');
    }
}
