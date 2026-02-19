<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;     // varsa kullan
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Http\Request;                 // index için lazım
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserOnboardController extends Controller
{
    /**
     * GET /api/couriers
     * Giriş yapan kullanıcının dealer_id’sine göre kurye (delivery_man) listesi
     * ?per_page=50, ?q=ali (ad/email/telefon arama)
     */
  // app/Http/Controllers/Api/UserOnboardController.php
        public function index(Request $r)
        {
            $per = (int) $r->input('per_page', 15);
            $userType = $r->input('user_type', 'client');
        
            $q = User::query()->where('user_type', $userType);
        
            $dealerId = $r->input('dealer_id') ?? optional($r->user())->dealer_id;
            if (!empty($dealerId)) {
                $q->where('dealer_id', (int) $dealerId);
            }
        
            if ($s = $r->input('q')) {
                $q->where(function($w) use ($s) {
                    $w->where('name','like',"%$s%")
                      ->orWhere('email','like',"%$s%")
                      ->orWhere('contact_number','like',"%$s%");
                });
            }
        
            $q->orderByDesc('id');
        
            return response()->json([
                'success' => true,
                'data'    => $q->paginate($per),
            ]);
        }
        public function changePassword($id, Request $request)
        {
            $request->validate([
                'password' => ['required', 'min:6', 'confirmed'],
            ]);
        
            $user = \App\Models\User::find($id);
            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }
        
            // burada istersen yetki kontrolü yap (ör. admin mi, dealer_id eşleşiyor mu vs.)
            $user->password = bcrypt($request->password);
            $user->save();
        
            return response()->json(['message' => 'Password updated']);
        }


    // KURYE (delivery_man)
    public function storeCouriers(StoreUserRequest $request)
    {
        $data = $request->validated();

     //   $dealerId = auth()->user()->dealer_id ?? auth()->id(); // “login olduğum dealer id”

        $user = User::create([
            'name'           => $data['name'],
            'email'          => $data['email'],
            'username'       => $data['username'] ?? Str::slug($data['name']).'-'.Str::random(5),
            'password'       => Hash::make($data['password']),
            'contact_number' => $data['contact_number'] ?? null,
            'user_type'      => 'delivery_man',        // İSTENEN: delivery_man
             'dealer_id'      => $data['dealer_id'],       // ⬅️ dealer bağlama
            'country_id'     => $data['country_id'] ?? null,
            'city_id'        => $data['city_id'] ?? null,
            'latitude'       => $data['latitude'] ?? null,
            'longitude'      => $data['longitude'] ?? null,
            'is_active'      => 1,
        ]);

        // Birincil adres kaydı (opsiyonel)
        if (!empty($data['address'])) {
            UserAddress::create([
                'user_id'        => $user->id,
                'country_id'     => $data['country_id'] ?? null,
                'city_id'        => $data['city_id'] ?? null,
                'address'        => $data['address'],
                'latitude'       => $data['latitude'] ?? null,
                'longitude'      => $data['longitude'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
            ]);
        }

        // Evrak yükleme (opsiyonel)
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $file->store('documents/'.$user->id, 'public');
                // Spatie kullanıyorsan:
                // $user->addMedia($file)->toMediaCollection('delivery_man_document');
            }
        }

        return response()->json(['ok' => true, 'user_id' => $user->id], 201);
    }
public function storeCourier(StoreUserRequest $request)
{
    $data = $request->validated();

    $user = User::create([
        'name'           => $data['name'],
        'email'          => $data['email'],
        'username'       => $data['username'] ?? Str::slug($data['name']).'-'.Str::random(5),
        'password'       => Hash::make($data['password']),
        'contact_number' => $data['contact_number'] ?? null,
        'user_type'      => 'delivery_man',
        'dealer_id'      => $data['dealer_id'] ?? null,

        'country_id'     => $data['country_id'] ?? null,
        'city_id'        => $data['city_id'] ?? null,

        // ✅ City / district kolonları
        'city'           => $data['city'] ?? null,
        'district'       => $data['district'] ?? null,

        // ✅ Hem eski string hem yeni decimal kolonları doldur
       'location_lat'   => $data['location_lat'] ?? (
                                isset($data['latitude']) ? (float) $data['latitude'] : null
                            ),
        'location_lng'   => $data['location_lng'] ?? (
                                isset($data['longitude']) ? (float) $data['longitude'] : null
                            ),
        'location_lat'   => $data['location_lat'] ?? (
                                isset($data['latitude']) ? (float) $data['latitude'] : null
                            ),
        'location_lng'   => $data['location_lng'] ?? (
                                isset($data['longitude']) ? (float) $data['longitude'] : null
                            ),

        'address'             => $data['address'] ?? null,
        'iban'                => $data['iban'] ?? null,
        'bank_account_owner'  => $data['bank_account_owner'] ?? null,
        'vehicle_plate'       => $data['vehicle_plate'] ?? null,
        'commission_rate'     => $data['commission_rate'] ?? 0,
        'commission_type'     => $data['commission_type'] ?? 'percent',
        'can_take_orders'     => isset($data['can_take_orders']) ? (int) $data['can_take_orders'] : 1,
        'has_hadi_account'    => isset($data['has_hadi_account']) ? (int) $data['has_hadi_account'] : 0,
        'secret_note'         => $data['secret_note'] ?? null,

        'is_active'      => 1,
    ]);

    // Adres kaydı
    if (!empty($data['address'])) {
        UserAddress::create([
            'user_id'        => $user->id,
            'country_id'     => $data['country_id'] ?? null,
            'city_id'        => $data['city_id'] ?? null,
            'address'        => $data['address'],
            'latitude'       => $data['latitude'] ?? null,
            'longitude'      => $data['longitude'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
        ]);
    }

    // Dokümanlar – istersen burada da 'documents' adıyla bekle
    if ($request->hasFile('documents')) {
        foreach ($request->file('documents') as $file) {
            $file->store('documents/'.$user->id, 'public');
        }
    }

    return response()->json(['ok' => true, 'user_id' => $user->id], 201);
}

    // İŞLETME (client)
    public function storeClient2(StoreUserRequest $request)
    {
        $data = $request->validated();

    //    $dealerId = auth()->user()->id ?? auth()->id(); // istersen client’ı da dealer’a bağla

        $user = User::create([
            'name'           => $data['name'],
            'email'          => $data['email'],
            'username'       => $data['username'] ?? Str::slug($data['name']).'-'.Str::random(5),
            'password'       => Hash::make($data['password']),
            'contact_number' => $data['contact_number'] ?? null,
            'user_type'      => 'client',
            'dealer_id'      =>  $data['dealer_id'] ?? null, //$dealerId, // opsiyonel; istemezsen kaldır
            'country_id'     => $data['country_id'] ?? null,
            'city_id'        => $data['city_id'] ?? null,
            'address'        => $data['address'] ?? null,
            'latitude'       => $data['latitude'] ?? null,
            'longitude'      => $data['longitude'] ?? null,
            'is_active'      => 1,
        ]);

        if (!empty($data['address'])) {
            UserAddress::create([
                'user_id'        => $user->id,
                'country_id'     => $data['country_id'] ?? null,
                'city_id'        => $data['city_id'] ?? null,
                'address'        => $data['address'],
                'latitude'       => $data['latitude'] ?? null,
                'longitude'      => $data['longitude'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
            ]);
        }

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $file->store('documents/'.$user->id, 'public');
            }
        }

        return response()->json(['ok' => true, 'user_id' => $user->id], 201);
    }

    public function storeClients(StoreUserRequest $request)
{
    $data = $request->validated();

    $user = new User();
    $user->name           = $data['name'];
    $user->email          = $data['email'];
    $user->username       = $data['username'] ?? Str::slug($data['name']).'-'.Str::random(5);
    $user->password       = Hash::make($data['password']);
    $user->contact_number = $data['contact_number'] ?? null;
    $user->user_type      = 'client';
    $user->dealer_id      = $data['dealer_id'] ?? null; // ✅ validated’dan
    $user->country_id     = $data['country_id'] ?? null;
    $user->city_id        = $data['city_id'] ?? null;
    $user->address        = $data['address'] ?? null;
    $user->latitude       = $data['latitude'] ?? null;
    $user->longitude      = $data['longitude'] ?? null;
    $user->is_active      = 1;
    $user->save();

    if (!empty($data['address'])) {
        UserAddress::create([
            'user_id'        => $user->id,
            'country_id'     => $data['country_id'] ?? null,
            'city_id'        => $data['city_id'] ?? null,
            'address'        => $data['address'],
            'latitude'       => $data['latitude'] ?? null,
            'longitude'      => $data['longitude'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
        ]);
    }

    if ($request->hasFile('documents')) {
        foreach ($request->file('documents') as $file) {
            $file->store('documents/'.$user->id, 'public');
        }
    }

    return response()->json(['ok' => true, 'user_id' => $user->id], 201);
}


public function storeClient(StoreUserRequest $request)
{
    $data = $request->validated();

    $user = new User();
    $user->name           = $data['name'];
    $user->email          = $data['email'];
    $user->username       = $data['username'] ?? Str::slug($data['name']).'-'.Str::random(5);
    $user->password       = Hash::make($data['password']);
    $user->contact_number = $data['contact_number'] ?? null;
    $user->user_type      = 'client';
    $user->dealer_id      = $data['dealer_id'] ?? null;

    $user->country_id     = $data['country_id'] ?? null;
    $user->city_id        = $data['city_id'] ?? null;

    // ✅ City/district kolonları
    $user->city           = $data['city'] ?? null;
    $user->district       = $data['district'] ?? null;

 
 


 

      $user->latitude   = $data['location_lat'] ?? (
                                isset($data['latitude']) ? (float) $data['latitude'] : null
                            );
    $user->longitude   = $data['location_lng'] ?? (
                                isset($data['longitude']) ? (float) $data['longitude'] : null
                            );



    $user->location_lat   = $data['location_lat'] ?? (
                                isset($data['latitude']) ? (float) $data['latitude'] : null
                            );
    $user->location_lng   = $data['location_lng'] ?? (
                                isset($data['longitude']) ? (float) $data['longitude'] : null
                            );

    $user->address        = $data['address'] ?? null;
    $user->iban           = $data['iban'] ?? null;
    $user->bank_account_owner = $data['bank_account_owner'] ?? null;
    $user->commission_rate    = $data['commission_rate'] ?? 0;
    $user->commission_type    = $data['commission_type'] ?? 'percent';
    $user->can_take_orders    = isset($data['can_take_orders']) ? (int) $data['can_take_orders'] : 1;
    $user->has_hadi_account   = isset($data['has_hadi_account']) ? (int) $data['has_hadi_account'] : 0;
    $user->secret_note        = $data['secret_note'] ?? null;

    $user->is_active      = 1;
    $user->save();

    if (!empty($data['address'])) {
        UserAddress::create([
            'user_id'        => $user->id,
            'country_id'     => $data['country_id'] ?? null,
            'city_id'        => $data['city_id'] ?? null,
            'address'        => $data['address'],
            'latitude'       => $data['latitude'] ?? null,
            'longitude'      => $data['longitude'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
        ]);
    }

    if ($request->hasFile('documents')) {
        foreach ($request->file('documents') as $file) {
            $file->store('documents/'.$user->id, 'public');
        }
    }

    return response()->json(['ok' => true, 'user_id' => $user->id], 201);
}


   public function show(Request $request, int $id)
    {
        $dealerId = optional($request->user())->dealer_id;

        $q = User::query()
            ->where('id', $id)
            ->where('user_type', 'client');

        if (!empty($dealerId)) {
            $q->where('dealer_id', $dealerId);
        }

        $client = $q->firstOrFail();

        // Frontend’in kullandığı alanlar
        $payload = [
            'id'                      => $client->id,
            'name'                    => $client->name,
            'contact_number'          => $client->contact_number ?? $client->phone,
            'is_open'                 => (bool) $client->is_open,
            'require_pickup_photo'    => (bool) $client->require_pickup_photo,
            'require_delivery_photo'  => (bool) $client->require_delivery_photo,
            'commission_type'         => $client->commission_type, // 'km' | 'fixed'
            'km_opening_fee'          => $client->km_opening_fee,
            'km_price'                => $client->km_price,
            'pay_receiver'            => (bool) $client->pay_receiver,
            'pay_sender'              => (bool) $client->pay_sender,
            'pay_admin'               => (bool) $client->pay_admin,
            'created_at'              => $client->created_at?->toDateTimeString(),
        ];

        return response()->json(['success' => true, 'data' => $payload]);
    }

    public function update(UpdateClientRequest $request, int $id)
    {
        $dealerId = optional($request->user())->dealer_id;

        $q = User::query()
            ->where('id', $id)
            ->where('user_type', 'client');

        if (!empty($dealerId)) {
            $q->where('dealer_id', $dealerId);
        }

        $client = $q->firstOrFail();

        $data = $request->validated();

        // boolean alanları güvenli şekilde ata
        foreach ([
            'is_open',
            'require_pickup_photo',
            'require_delivery_photo',
            'pay_receiver',
            'pay_sender',
            'pay_admin',
        ] as $b) {
            if ($request->has($b)) {
                $data[$b] = $request->boolean($b);
            }
        }

        $client->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'Client updated',
            'data'    => [
                'id'   => $client->id,
                'name' => $client->name,
            ],
        ]);
    }
    


     public function CourierShow($id)
    {
        // Sadece kurye (delivery_man) kullanıcılarını döndür
        $u = User::query()
            ->where('user_type', 'delivery_man')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->transformCourier($u),
        ]);
    }

    /**
     * PUT /api/couriers/{id}
     * Flutter tarafı: name, contact_number, plate, bank_owner, iban, is_active gönderiyor.
     */
    public function CourierUpdate(Request $request, $id)
    {
        $u = User::query()
            ->where('user_type', 'delivery_man')
            ->findOrFail($id);

        $data = $request->validate([
            'name'            => ['sometimes','string','max:255'],
            'contact_number'  => ['sometimes','nullable','string','max:40'],
            'plate'           => ['sometimes','nullable','string','max:50'], // UI'da "Plaka"
            'bank_owner'      => ['sometimes','nullable','string','max:255'],
            'iban'            => ['sometimes','nullable','string','max:50'],
            'is_active'       => ['sometimes','boolean'],
        ]);

        // DB kolon eşlemeleri (kolon adlarınız farklı ise burayı uyarlayın)
        if (array_key_exists('name', $data))           $u->name              = $data['name'];
        if (array_key_exists('contact_number', $data)) $u->contact_number    = $data['contact_number'];

        // Plaka verisini vehicle_plate kolonuna yazıyoruz (ya da sizdeki isme)
        if (array_key_exists('plate', $data))          $u->vehicle_plate     = $data['plate'];
        // Banka hesap sahibi
        if (array_key_exists('bank_owner', $data))     $u->bank_owner        = $data['bank_owner'];
        // IBAN
        if (array_key_exists('iban', $data))           $u->iban              = $data['iban'];

        if (array_key_exists('is_active', $data))      $u->is_active         = (bool) $data['is_active'];

        $u->save();

        return response()->json([
            'success' => true,
            'data'    => $this->transformCourier($u),
        ]);
    }

    /**
     * Flutter’ın beklediği sade JSON.
     */
    private function transformCourier(User $u): array
    {
        return [
            'id'             => $u->id,
            'name'           => $u->name,
            'contact_number' => $u->contact_number ?? $u->phone,
            'is_active'      => (bool) $u->is_active,

            // UI “plate/bank_owner/iban” bekliyor
            'plate'          => $u->vehicle_plate ?? $u->plate ?? null,
            'bank_owner'     => $u->bank_owner ?? $u->bank_account_owner ?? null,
            'iban'           => $u->iban ?? null,

            // (Varsa) dokümanlar — ilişki yoksa boş dizi döner
            'documents'      => $u->relationLoaded('documents')
                ? $u->documents->map(fn($d) => [
                    'id'    => $d->id,
                    'title' => $d->title,
                    'url'   => $d->url,
                    'thumb' => $d->thumb,
                  ])->values()->all()
                : [],

            'created_at'     => optional($u->created_at)->toDateTimeString(),
            'updated_at'     => optional($u->updated_at)->toDateTimeString(),
        ];
    }
}
