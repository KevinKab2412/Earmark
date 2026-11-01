<?php

namespace App\Http\Controllers\Household;

use App\Actions\Categories\CreateCategoryGroup;
use App\Actions\Categories\UpdateCategoryGroup;
use App\Concerns\ResolvesHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\StoreCategoryGroupRequest;
use App\Http\Requests\Household\UpdateCategoryGroupRequest;
use App\Models\CategoryGroup;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryGroupController extends Controller
{
    use ResolvesHousehold;

    public function store(StoreCategoryGroupRequest $request, CreateCategoryGroup $action): RedirectResponse
    {
        $action->handle($request->validated(), $this->household($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category group created.')]);

        return back();
    }

    public function update(UpdateCategoryGroupRequest $request, CategoryGroup $categoryGroup, UpdateCategoryGroup $action): RedirectResponse
    {
        $action->handle($request->validated(), $categoryGroup, $this->household($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category group updated.')]);

        return back();
    }
}
