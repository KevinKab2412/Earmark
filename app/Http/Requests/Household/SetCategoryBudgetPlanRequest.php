<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Models\Category;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SetCategoryBudgetPlanRequest extends FormRequest
{
    use AuthorizesHousehold;

    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category
            && ($this->user()?->can('view', $this->household()) ?? false)
            && $category->household_id === $this->household()->id
            && $category->isBudgetable();
    }

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
        return [
            'amount' => ['required', 'string', 'max:40'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'apply_to_future_months' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $cents = Money::parseToCents((string) $this->input('amount', ''));
            if ($cents === null || $cents < 0) {
                $validator->errors()->add('amount', __('Enter a non-negative amount.'));
            }
        }];
    }
}
