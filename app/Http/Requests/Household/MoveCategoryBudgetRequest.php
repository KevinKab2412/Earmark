<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Models\CategoryGroup;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MoveCategoryBudgetRequest extends FormRequest
{
    use AuthorizesHousehold;

    protected function prepareForValidation(): void
    {
        $cents = Money::parseToCents((string) $this->input('amount', ''));
        if ($cents !== null) {
            $this->merge(['amount_cents' => $cents]);
        }

    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = Rule::exists('categories', 'id')
            ->where('household_id', $this->household()->id)
            ->where('kind', 'standard')
            ->where(fn ($query) => $query->whereIn('category_group_id', CategoryGroup::query()->whereIn('direction', ['income', 'expense'])->select('id')->toBase()));

        return [
            'from_category_id' => ['required', 'string', 'different:to_category_id', $category],
            'to_category_id' => ['required', 'string', 'different:from_category_id', $category],
            'amount' => ['required', 'string', 'max:40'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $cents = Money::parseToCents((string) $this->input('amount', ''));
            if ($cents === null || $cents < 1) {
                $validator->errors()->add('amount', __('Enter an amount greater than zero.'));
            }
        }];
    }
}
