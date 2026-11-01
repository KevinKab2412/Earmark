<?php

namespace App\Actions\Categories;

use App\Models\CategoryGroup;
use App\Models\Household;
use App\Services\Category\CategoryService;

class UpdateCategoryGroup
{
    public function __construct(private CategoryService $categories) {}

    /** @param array{name: string} $data */
    public function handle(array $data, CategoryGroup $categoryGroup, Household $household): CategoryGroup
    {
        return $this->categories->updateGroup($data, $categoryGroup, $household);
    }
}
