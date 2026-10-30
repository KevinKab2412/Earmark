<?php

namespace App\Models;

use Database\Factories\CategoryRolloverSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryRolloverSetting extends Model
{
    /** @use HasFactory<CategoryRolloverSettingFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['category_id', 'effective_month', 'enabled', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['effective_month' => 'date', 'enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
