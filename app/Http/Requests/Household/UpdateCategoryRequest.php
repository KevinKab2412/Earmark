<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Models\Category;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    use AuthorizesHousehold;

    public function authorize(): bool
    {
        $category = $this->route('category');
        $household = $this->household();

        return $category instanceof Category
            && $category->household_id === $household->id
            && ($this->user()?->can('view', $household) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $household = $this->household();
        $category = $this->route('category');
        abort_unless($category instanceof Category, 404);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where('household_id', $household->id)
                    ->ignore($category->id),
            ],
            'category_group_id' => [
                'required',
                'string',
                Rule::exists('category_groups', 'id')
                    ->where('household_id', $household->id)
                    ->where('direction', $category->direction),
            ],
            'icon' => ['nullable', 'string', 'max:80'],
            'starting_month' => ['nullable', 'date_format:Y-m'],
            'starting_balance' => ['nullable', 'string', 'max:40'],
            'starting_balance_cents' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $startingBalance = $this->input('starting_balance', '0');

        if (is_string($startingBalance)) {
            $cents = Money::parseToCents($startingBalance);

            if ($cents !== null) {
                $this->merge(['starting_balance_cents' => $cents]);
            }
        }
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (Money::parseToCents((string) $this->input('starting_balance', '0')) === null) {
                $validator->errors()->add('starting_balance', __('Enter a valid starting balance.'));
            }
        }];
    }
}
