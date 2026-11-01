<?php

namespace App\Actions\Categories;

use App\Models\Category;
use App\Models\Household;
use App\Services\Category\CategoryService;

class UpdateCategory
{
    public function __construct(private CategoryService $categories) {}

    /**
     * @param  array{name: string, category_group_id: string, icon?: ?string, starting_month?: ?string, starting_balance_cents?: int}  $data
     */
    public function handle(array $data, Category $category, Household $household): Category
    {
        return $this->categories->update($data, $category, $household);
    }
}
