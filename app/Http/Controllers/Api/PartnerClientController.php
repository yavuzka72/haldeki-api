<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\DeliveryOrder;

class PartnerClientController extends Controller
{
    public function me(Request $request)
    {
        $partner = $request->attributes->get('partner');

        if (!$partner) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated partner.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $partner,
        ]);
    }

    public function index2(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $active = $request->query('is_active'); // "1" / "0" / null

        $rows = PartnerClient::query()
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%")
                      ->orWhere('contact_number', 'like', "%{$q}%")
                      ->orWhere('partner_key', 'like', "%{$q}%");
                });
            })
            ->when($active !== null, function ($qq) use ($active) {
                // "0" -> false, "1" -> true
                $val = in_array((string)$active, ['1', 'true', 'True', 'TRUE'], true);
                $qq->where('is_active', $val);
            })
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }


public function index(Request $request)
{
    $authPartner = $request->attributes->get('partner');
    if ($authPartner) {
        return response()->json([
            'success' => true,
            'data' => $authPartner,
        ]);
    }

    $data = $request->validate([
        'page'     => ['nullable','integer','min:1'],
        'per_page' => ['nullable','integer','min:1','max:500'],
        'q'        => ['nullable','string','max:191'],
        'status'   => ['nullable','string','max:50'], // opsiyonel
        'dealer_id'=> ['nullable','integer','min:1'],
    ]);

    $perPage = (int)($data['per_page'] ?? 100);

    $qq = trim((string)($data['q'] ?? ''));

    $query = \App\Models\PartnerClient::query();

    // dealer filtre
    if (!empty($data['dealer_id'])) {
        $query->where('dealer_id', (int)$data['dealer_id']);
    }

    // arama
    if ($qq !== '') {
        $query->where(function($w) use ($qq){
            $w->where('name', 'like', "%{$qq}%")
              ->orWhere('email', 'like', "%{$qq}%")
              ->orWhere('phone', 'like', "%{$qq}%")
              ->orWhere('contact_number', 'like', "%{$qq}%");
        });
    }

    // is_active / status filtre istersen
    if (isset($data['status']) && $data['status'] !== '') {
        // örnek: status=active / passive
        if ($data['status'] === 'active') $query->where('is_active', 1);
        if ($data['status'] === 'passive') $query->where('is_active', 0);
    }

    $query->orderByDesc('id');

    return response()->json($query->paginate($perPage));
}

  public function show(Request $request, $id)
    {
        $authPartner = $request->attributes->get('partner');
        if (!$authPartner) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated partner.',
            ], 401);
        }

        if ((int) $authPartner->id !== (int) $id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: partner mismatch.',
            ], 403);
        }

        $partner = PartnerClient::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $partner,
        ]);
    }

    
    public function show2(Request $request, $id)
{
    $partner = $request->attributes->get('partner'); // middleware set ediyor demiştin
    $dealerId = $partner->dealer_id ?? null;

    $perPage = (int) $request->input('per_page', 50);
    $page    = (int) $request->input('page', 1);
    $status  = $request->input('status'); // optional

    $client = PartnerClient::query()
        ->where('id', $id)
        ->when($dealerId, fn($q) => $q->where('dealer_id', $dealerId))
        ->firstOrFail();

    // ✅ deliverOrders sorgusu: relation üzerinden
    $ordersQ = $client->deliverOrders()->orderByDesc('id');

    if (!empty($status)) {
        $ordersQ->where('status', $status);
    }

    $p = $ordersQ->paginate($perPage, ['*'], 'page', $page);

    // ✅ response içinde client + sayfalı orders
    return response()->json([
        'success' => true,
        'data' => [
            'client' => $client,
            'deliver_orders' => [
                'data' => $p->items(),
                'meta' => [
                    'current_page' => $p->currentPage(),
                    'per_page'     => $p->perPage(),
                    'total'        => $p->total(),
                    'last_page'    => $p->lastPage(),
                ],
            ],
        ],
    ]);
}

    public function store(Request $request)
    {
        $data = $request->validate([
            // işletme kimliği
            'name'           => ['required','string','max:150'],
            'email'          => ['nullable','email','max:191'],
            'phone'          => ['nullable','string','max:50'],
            'contact_number' => ['nullable','string','max:50'],

            // adres / konum
            'address'        => ['nullable','string'],
            'country_id'     => ['nullable','integer','min:1'],
            'city_id'        => ['nullable','integer','min:1'],
            'city'           => ['nullable','string','max:191'],
            'district'       => ['nullable','string','max:191'],
            'latitude'       => ['nullable','string','max:255'],
            'longitude'      => ['nullable','string','max:255'],
            'location_lat'   => ['nullable','numeric'],
            'location_lng'   => ['nullable','numeric'],

            'status'         => ['nullable','integer'],
             'dealer_id'         => ['nullable','integer'],
             'user_type'        => ['nullable','string'],
            // partner auth (opsiyonel; boş gelirse otomatik üretilecek)
            'partner_key'    => ['nullable','string','max:100', Rule::unique('partner_clients','partner_key')],
            'partner_secret' => ['nullable','string','max:150'],

            // webhook
            'webhook_url'    => ['nullable','string','max:255'],
            'webhook_secret' => ['nullable','string','max:150'],

            'is_active'      => ['nullable','boolean'],
            'meta'           => ['nullable','array'],
              'commission_rate' => ['nullable','numeric','min:0','max:100'],
  'commission_amount' => ['nullable','numeric','min:0'],
        ]);

        // Defaultlar
        $data['is_active'] = $data['is_active'] ?? true;
        $data['status']    = $data['status'] ?? 1;
       
        $data['dealer_id'] = $data['dealer_id'] ??  24 ;//$admin?->id;
        $data['user_type'] = $data['user_type'] ?? 'client' ;//$admin?->id;
        // partner_key otomatik üret (unique garantili)
        if (empty($data['partner_key'])) {
            $data['partner_key'] = $this->generateUniquePartnerKey();
        } else {
            // gönderilse bile normalize etmek istersen:
            $data['partner_key'] = trim($data['partner_key']);
        }

        // partner_secret otomatik üret
        if (empty($data['partner_secret'])) {
            $data['partner_secret'] = Str::random(48);
        }

        // ✅ partner token (API access)
        $plainPartnerToken = Str::random(80);                 // bunu bir kere gösterebilirsin
        $data['token'] = hash('sha256', $plainPartnerToken);  // DB’ye hash yaz
        $data['token_expires_at'] = now()->addDays(30);


        $partner = PartnerClient::create($data);

        return response()->json([
            'success' => true,
            'data' => $partner,
            // İstersen ilk oluştururken credential’ları göster (UI’de kopyalatmak için)
            'credentials' => [
                'partner_key' => $partner->partner_key,
                'partner_secret' => $partner->partner_secret
            
            ],
        ], 201);
    }


public function update(Request $request, $id)
{
    $client = PartnerClient::findOrFail($id);

    $client->update($request->all());

    return response()->json([
        'success' => true,
        'data' => $client,
    ]);
}


 public function deliverOrders(Request $request, $id)
    {
        $client = PartnerClient::findOrFail($id);

        $perPage = (int) $request->query('per_page', 50);
        if ($perPage <= 0) $perPage = 50;

        $query = $client->deliverOrders()
            ->select([
                'id',
                'customer_fcm_token',
                'from_name',
                'to_name',
                'total_amount',
                'status',
                'delivered_at',
                'created_at',
            ])
            ->latest('id');

        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }


public function update2(Request $request, $id)
{
    $c = PartnerClient::findOrFail($id);

    $data = $request->validate([
        'name' => ['required','string','max:191'],
        'contact_number' => ['nullable','string','max:191'],
        'is_open' => ['nullable','boolean'],

        'require_pickup_photo' => ['nullable','boolean'],
        'require_delivery_photo' => ['nullable','boolean'],

        'commission_amount' => ['nullable','numeric','min:0'],

        'pay_receiver' => ['nullable','boolean'],
        'pay_sender' => ['nullable','boolean'],
        'pay_admin' => ['nullable','boolean'],
    ]);

    $c->name = $data['name'];
    $c->phone = $data['contact_number'] ?? $c->phone;

    $c->is_open = $data['is_open'] ?? $c->is_open;

    $c->require_pickup_photo = $data['require_pickup_photo'] ?? $c->require_pickup_photo;
    $c->require_delivery_photo = $data['require_delivery_photo'] ?? $c->require_delivery_photo;

    if (array_key_exists('commission_amount', $data)) {
        $c->commission_amount = $data['commission_amount'] ?? 0;
    }

    $c->pay_receiver = $data['pay_receiver'] ?? $c->pay_receiver;
    $c->pay_sender   = $data['pay_sender'] ?? $c->pay_sender;
    $c->pay_admin    = $data['pay_admin'] ?? $c->pay_admin;

    $c->save();

    return response()->json([
        'ok' => true,
        'data' => $c,
    ]);
}


    public function destroy(PartnerClient $partnerClient)
    {
        $partnerClient->delete();

        return response()->json([
            'success' => true,
            'message' => 'Silindi',
        ]);
    }

    /**
     * Unique partner_key üretir: p_xxxxxxxxxxxxxxxxxxxxxxxx
     */
    private function generateUniquePartnerKey(): string
    {
        do {
            $key = 'p_' . Str::lower(Str::random(24));
        } while (PartnerClient::where('partner_key', $key)->exists());

        return $key;
    }
}
