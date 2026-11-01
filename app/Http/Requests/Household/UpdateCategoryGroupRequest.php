<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Models\CategoryGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryGroupRequest extends FormRequest
{
    use AuthorizesHousehold;

    public function authorize(): bool
    {
        $categoryGroup = $this->route('categoryGroup');
        $household = $this->household();

        return $categoryGroup instanceof CategoryGroup
            && $categoryGroup->household_id === $household->id
            && ($this->user()?->can('view', $household) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $household = $this->household();
        $categoryGroup = $this->route('categoryGroup');
        abort_unless($categoryGroup instanceof CategoryGroup, 404);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('category_groups', 'name')
                    ->where('household_id', $household->id)
                    ->ignore($categoryGroup->id),
            ],
        ];
    }
}
