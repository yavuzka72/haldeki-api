<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdateRequest extends FormRequest
{
    public function authorize()
    {
        // Kullanıcı giriş yaptıysa true
        return auth()->check();
    }

    public function rules()
    {
        return [
            // Temel bilgiler
            'name'          => 'sometimes|string|max:255',
            'email'         => 'sometimes|nullable|email|max:255',
            'phone'         => 'sometimes|nullable|string|max:50',

            // Adres & konum
            'city_id'       => 'sometimes|nullable|integer',
            'city'          => 'sometimes|nullable|string|max:255',
            'district'      => 'sometimes|nullable|string|max:255',
            'address'       => 'sometimes|nullable|string|max:500',
            'latitude'      => 'sometimes|nullable|numeric',
            'longitude'     => 'sometimes|nullable|numeric',

            // Bayi & vendor
            'dealer_id'     => 'sometimes|nullable|integer',
            'vendor_id'     => 'sometimes|nullable|integer',

            // Durum
            'is_active'     => 'sometimes|boolean',
            'status'        => 'sometimes|nullable|integer',

            // Araç bilgileri
            'vehicle_plate' => 'sometimes|nullable|string|max:50',

            // Banka bilgileri
            'iban'          => 'sometimes|nullable|string|max:50',
            'commission_rate' => 'sometimes|nullable|numeric',
            'commission_type' => 'sometimes|nullable|string|max:50',

            // Profil resmi
            'profile_image' => 'sometimes|file|mimes:jpg,jpeg,png|max:2048',

            // Banka Hesap Alt Modeli
            'user_bank_account'               => 'sometimes|array',
            'user_bank_account.account_name'  => 'sometimes|nullable|string|max:255',
            'user_bank_account.bank_name'     => 'sometimes|nullable|string|max:255',
            'user_bank_account.iban'          => 'sometimes|nullable|string|max:50',
        ];
    }

    public function messages()
    {
        return [
            'email.email' => 'Geçerli bir e-posta adresi giriniz.',
            'profile_image.mimes' => 'Profil resmi jpg, jpeg veya png olmalıdır.',
            'profile_image.max'   => 'Profil resmi en fazla 2MB olabilir.',
        ];
    }
}
