<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Models\CategoryGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryGroupRequest extends FormRequest
{
    use AuthorizesHousehold;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(CategoryGroup::DIRECTIONS)],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('category_groups', 'name')->where('household_id', $this->household()->id),
            ],
        ];
    }
}
