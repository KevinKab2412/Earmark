<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\AuthorizesHousehold;
use App\Models\Goal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGoalRequest extends FormRequest
{
    use AuthorizesHousehold;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $householdId = $this->household()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Goal::TYPES)],
            'target_amount' => ['required', 'integer', 'min:0'],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'apr_bps' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'required_payment' => ['nullable', 'integer', 'min:0'],
            'principal' => ['nullable', 'integer'],
            'linked_account_id' => ['nullable', 'string', Rule::exists('accounts', 'id')->where('household_id', $householdId)->whereIn('type', ['credit_card', 'mortgage', 'loan'])],
            'linked_category_id' => ['nullable', 'string', Rule::exists('categories', 'id')->where('household_id', $householdId)->whereIn('direction', ['income', 'expense'])],
            'payment_category_id' => ['nullable', 'string', Rule::exists('categories', 'id')->where('household_id', $householdId)->where('direction', 'expense')],
            'linked_position_id' => ['nullable', 'string', Rule::exists('financial_positions', 'id')->where('household_id', $householdId)->where('classification', 'liability')],
            'status' => ['nullable', Rule::in(['active', 'achieved', 'archived'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->input('type');
            $categoryId = $this->input('linked_category_id');
            $accountId = $this->input('linked_account_id');
            $positionId = $this->input('linked_position_id');

            if ($type === 'save_up' && empty($categoryId)) {
                $validator->errors()->add('linked_category_id', __('Choose a category for this savings goal.'));
            }

            if ($type === 'pay_down' && empty($accountId) && empty($positionId)) {
                $validator->errors()->add('linked_position_id', __('Choose the debt account or liability this goal will track.'));
            }

            if ($type === 'pay_down' && ! empty($accountId) && ! empty($positionId)) {
                $validator->errors()->add('linked_position_id', __('Link one debt balance only.'));
            }

            if ($type === 'save_up' && (! empty($accountId) || ! empty($positionId))) {
                $validator->errors()->add('linked_category_id', __('Savings goals track a budget category.'));
            }
        }];
    }
}
