<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class SetCategoryRolloverRequest extends FormRequest
{
    use AuthorizesHousehold;

    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category
            && ($this->user()?->can('view', $this->household()) ?? false)
            && $category->household_id === $this->household()->id
            && $category->direction === 'expense'
            && $category->isBudgetable();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
