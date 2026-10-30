<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryRolloverSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryRolloverSetting>
 */
class CategoryRolloverSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'effective_month' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'enabled' => true,
            'created_by_user_id' => null,
        ];
    }
}
