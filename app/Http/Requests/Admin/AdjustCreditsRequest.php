<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdjustCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Positive amount adds credits; negative deducts (must not exceed user balance).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('billing.admin_adjust_max_credits', 1_000_000);

        return [
            'amount' => [
                'required',
                'integer',
                'not_in:0',
                'min:'.-1 * $max,
                'max:'.$max,
            ],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
