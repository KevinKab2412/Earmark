<?php

namespace App\Actions\Categories;

use App\Models\Category;
use App\Models\CategoryRolloverSetting;
use App\Models\User;
use Carbon\CarbonImmutable;

class SetCategoryRollover
{
    public function handle(Category $category, bool $enabled, int $year, int $month, User $user): void
    {
        $effectiveMonth = CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth()->toDateString();

        $category->rolloverSettings()->whereDate('effective_month', '>=', $effectiveMonth)->delete();

        $setting = $category->rolloverSettings()
            ->whereDate('effective_month', $effectiveMonth)
            ->first();

        if ($setting === null) {
            CategoryRolloverSetting::query()->create([
                'category_id' => $category->id,
                'effective_month' => $effectiveMonth,
                'enabled' => $enabled,
                'created_by_user_id' => $user->id,
            ]);

            return;
        }

        $setting->update(['enabled' => $enabled, 'created_by_user_id' => $user->id]);
    }
}
