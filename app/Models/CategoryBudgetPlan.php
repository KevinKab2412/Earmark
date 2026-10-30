<?php

namespace App\Models;

use Database\Factories\CategoryBudgetPlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryBudgetPlan extends Model
{
    /** @use HasFactory<CategoryBudgetPlanFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['category_id', 'effective_month', 'planned_amount', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['effective_month' => 'date', 'planned_amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
