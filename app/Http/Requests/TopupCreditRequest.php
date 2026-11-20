<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TopupCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.50', 'max:500.00'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Minimum top-up amount is $0.50.',
            'amount.max' => 'Maximum top-up amount is $500.00.',
        ];
    }
}
