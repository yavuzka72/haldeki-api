<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki kontrolünüz yoksa true bırakın
    }

    public function rules(): array
    {
        return [
            // İsteğe göre iki yol: is_courier (bool) veya type (delivery_man|client)
            'is_courier'      => ['sometimes','boolean'],
            'type'            => ['sometimes','string','in:delivery_man,client'],

            // Temel bilgiler
            'name'            => ['required','string','max:255'],

            // Email zorunlu kalsın istiyorsan böyle bırak
            'email'           => ['required','email','max:255','unique:users,email'],

            'password'        => ['required','string','min:6'],

            // Opsiyonel kullanıcı adı
            'username'        => ['sometimes','nullable','string','max:191','unique:users,username'],

            // user_type Flutter’dan gelirse de çöpe gitmesin
            'user_type'       => ['sometimes','string','in:user,delivery_man,admin,client,vendor'],

            'contact_number'  => ['nullable','string','max:50'],

            // Şema: countries = şehirler, cities = ilçeler
            'country_id'      => ['sometimes','nullable','integer','exists:countries,id'],
            'city_id'         => ['sometimes','nullable','integer','exists:cities,id'],

            // ✅ City & District isimleri (users.city / users.district kolonları)
            'city'            => ['sometimes','nullable','string','max:191'],
            'district'        => ['sometimes','nullable','string','max:191'],

            // Adres
            'address'         => ['nullable','string','max:500'],

            // Eski string lat/lng kolonları
            'latitude'        => ['sometimes','nullable','string','max:50'],
            'longitude'       => ['sometimes','nullable','string','max:50'],

            // ✅ Yeni decimal konum kolonları
            'location_lat'    => ['sometimes','nullable','numeric'],
            'location_lng'    => ['sometimes','nullable','numeric'],

            // Finans & araç bilgileri
            'iban'                => ['sometimes','nullable','string','max:50'],
            'bank_account_owner'  => ['sometimes','nullable','string','max:191'],
            'vehicle_plate'       => ['sometimes','nullable','string','max:50'],

            'commission_rate'     => ['sometimes','nullable','numeric'],
            'commission_type'     => ['sometimes','nullable','in:percent,fixed'],
            'can_take_orders'     => ['sometimes','boolean'],
            'has_hadi_account'    => ['sometimes','boolean'],
            'secret_note'         => ['sometimes','nullable','string'],

            // Dealer ilişkisi
            'dealer_id'       => ['nullable','integer','exists:users,id'],

            // Çoklu dosya upload
            'documents'       => ['nullable','array'],
            'documents.*'     => ['file','mimes:jpg,jpeg,png,pdf','max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'       => 'Bu e-posta zaten kayıtlı.',
            'password.min'       => 'Şifre en az 6 karakter olmalı.',
            'documents.*.mimes'  => 'Yalnızca JPG, PNG veya PDF yükleyebilirsiniz.',
            'documents.*.max'    => 'Her bir dosya en fazla 4 MB olabilir.',
            'username.unique'    => 'Bu kullanıcı adı zaten kullanılıyor.',
        ];
    }
}
