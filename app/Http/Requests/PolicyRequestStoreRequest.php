<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PolicyRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:50'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:trip_start'],
            'destination' => ['required', 'string', 'in:DOMESTIC,INTERNATIONAL,domestic,international'],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', 'in:BASIC,PLUS,PREMIUM,basic,plus,premium'],
            'sum_insured' => ['required', 'numeric', 'in:100000,300000,500000,100000.00,300000.00,500000.00'],
        ];
    }

    public function messages(): array
    {
        return [
            'trip_end.after_or_equal' => 'The trip end date must be on or after the trip start date.',
            'date_of_birth.before' => 'The date of birth must be before the trip start date.',
            'plan_code.in' => 'Unsupported plan code. Permitted plans: BASIC, PLUS, PREMIUM.',
            'destination.in' => 'Unsupported destination. Permitted destinations: DOMESTIC, INTERNATIONAL.',
            'sum_insured.in' => 'Unsupported sum insured. Permitted coverage amounts: 100000, 300000, 500000.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed for policy request intake.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
