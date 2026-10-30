<?php

namespace App\Actions\Categories;

use App\Models\CategoryGroup;
use App\Models\Household;
use App\Services\Category\CategoryService;

class CreateCategoryGroup
{
    public function __construct(private CategoryService $categories) {}

    /**
     * @param  array{name: string}  $data
     */
    public function handle(array $data, Household $household): CategoryGroup
    {
        return $this->categories->createGroup($data, $household);
    }
}
