<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy/permission kullanıyorsanız burayı düzenleyin
    }

    public function rules(): array
    {
        return [
            'name'                   => ['required','string','max:255'],
            'contact_number'         => ['nullable','string','max:50'],

            'is_open'                => ['nullable','boolean'],
            'require_pickup_photo'   => ['nullable','boolean'],
            'require_delivery_photo' => ['nullable','boolean'],

            'commission_type'        => ['nullable','in:km,fixed'],
            'km_opening_fee'         => ['nullable','numeric'], // DECIMAL(10,2)
            'km_price'               => ['nullable','numeric'],

            'pay_receiver'           => ['nullable','boolean'],
            'pay_sender'             => ['nullable','boolean'],
            'pay_admin'              => ['nullable','boolean'],
        ];
    }

    public function prepareForValidation(): void
    {
        // "100,00" -> "100.00" gibi normalize etmek isterseniz:
        foreach (['km_opening_fee','km_price'] as $k) {
            if ($this->has($k) && is_string($this->$k)) {
                $this->merge([
                    $k => str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $this->$k)),
                ]);
            }
        }
    }
}
