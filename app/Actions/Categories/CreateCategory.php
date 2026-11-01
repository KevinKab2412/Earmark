<?php

namespace App\Actions\Categories;

use App\Models\Category;
use App\Models\Household;
use App\Services\Category\CategoryService;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateCategory
{
    use AsAction;

    public function __construct(private CategoryService $categories) {}

    /**
     * @param  array{name: string, category_group_id: string, icon?: ?string, starting_month?: ?string, starting_balance_cents?: int, year?: int|string, month?: int|string}  $data
     */
    public function handle(array $data, Household $household): Category
    {
        return $this->categories->create($data, $household);
    }
}
