<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRepairVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'check_number' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'vendor_invoice_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'payment_date' => ['nullable', 'date'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,doc,docx'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Please enter the payment amount.',
            'amount.min' => 'The payment amount must be at least $0.01.',
            'amount.numeric' => 'The payment amount must be a valid number.',
            'vendor_invoice_amount.numeric' => 'The vendor invoice amount must be a valid number.',
            'attachment.max' => 'The attachment must not exceed 10MB.',
            'attachment.mimes' => 'The attachment must be a PDF, image, or document file.',
        ];
    }
}
