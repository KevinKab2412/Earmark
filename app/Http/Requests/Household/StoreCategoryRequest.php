<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    use AuthorizesHousehold;

    protected function prepareForValidation(): void
    {
        $startingBalanceCents = Money::parseToCents((string) $this->input('starting_balance', '0'));
        if ($startingBalanceCents !== null) {
            $this->merge(['starting_balance_cents' => $startingBalanceCents]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->where('household_id', $this->household()->id),
            ],
            'category_group_id' => [
                'required',
                'string',
                Rule::exists('category_groups', 'id')->where('household_id', $this->household()->id),
            ],
            'direction' => ['required', Rule::in(['income', 'expense', 'transfer', 'adjustment'])],
            'icon' => ['nullable', 'string', 'max:80'],
            'starting_month' => ['nullable', 'date_format:Y-m'],
            'starting_balance' => ['nullable', 'string', 'max:40'],
            'starting_balance_cents' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ];
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
