<?php

namespace App\Models;

use Database\Factories\CategoryGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryGroup extends Model
{
    /** @use HasFactory<CategoryGroupFactory> */
    use HasFactory, HasUlids;

    public const DIRECTIONS = ['income', 'expense', 'transfer'];

    protected $fillable = ['household_id', 'name', 'direction', 'sort_order', 'archived'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived' => 'boolean',
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
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'category_group_id');
    }
}
