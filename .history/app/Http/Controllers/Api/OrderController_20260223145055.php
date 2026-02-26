<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\DeliveryOrder;
use App\Services\DeliveryOrderImporter;
use App\Models\Cart;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Validator;
use App\Models\Payment;
 
use Illuminate\Validation\Rule;
 
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;
 
use Throwable;




class OrderController extends Controller {
	use ApiResponse;

	public function index() {
		$orders = Order::where('user_id', Auth::id())
			->with(['items.productVariant.product', 'items.seller'])
			->latest()
			->paginate(10);

		return $this->success($orders);
	}
public function store(Request $request)
{
    Log::info('ORDER_REQUEST_BODY', $request->all());


        


    try {

      
        // Hangi dal çalışıyor?
        $branch = $request->has('restaurant') ? 'restaurant' : 'regular';
        Log::info('ORDER_BRANCH', ['branch' => $branch]);

        $order = $branch === 'restaurant'
            ? $this->createRestaurantOrder($request)
            : $this->createRegularOrder($request);

        // Yanlışlıkla Response dönen eski kodları pasla (daha kapsamlı instanceof)
        if (
            $order instanceof \Illuminate\Http\JsonResponse ||
            $order instanceof \Illuminate\Http\Response ||
            $order instanceof \Symfony\Component\HttpFoundation\Response
        ) {
            Log::warning('ORDER_PASS_THROUGH_RESPONSE', ['class' => get_class($order)]);
            return $order;
        }

        Log::info('ORDER_RETURN_TYPE', ['type' => is_object($order) ? get_class($order) : gettype($order)]);

        if (!$order instanceof \App\Models\Order) {
            Log::error('ORDER_NOT_MODEL', ['value' => $order]);
            return response()->json([
                'success' => false,
                'message' => 'Sipariş oluşturulamadı.',
                'error'   => 'INTERNAL_ORDER_NULL'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Siparişiniz oluşturuldu.',
            'data'    => $order->toArray(),
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $ve) {
        return response()->json([
            'success' => false,
            'message' => 'Geçersiz veri.',
            'errors'  => $ve->errors(),
        ], 422);
    } catch (\Throwable $e) {
        Log::error('ORDER_STORE_FAILED', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Sipariş oluşturulurken bir hata oluştu.',
            'error'   => config('app.debug') ? $e->getMessage() : 'SERVER_ERROR',
        ], 500);
    }
}

 public function store2(Request $request)
    {
        Log::info('ORDER_REQUEST', $request->all());

        try {
            // restaurant varsa o akış
            $order = $request->has('restaurant')
                ? $this->createRestaurantOrder($request)
                : $this->createRegularOrder($request);

            // Eğer yanlışlıkla JsonResponse dönüyorsa (eski kodlar):
            if ($order instanceof JsonResponse) {
                Log::warning('Order method returned JsonResponse; passing through.');
                return $order;
            }

            // Model değilse logla
            if (!$order instanceof Order) {
                Log::error('Order is not instance of Order', [
                    'type' => is_object($order) ? get_class($order) : gettype($order)
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Sipariş oluşturulamadı.',
                    'error'   => 'INTERNAL_ORDER_NULL'
                ], 500);
            }

            // Başarılı cevap
            return response()->json([
                'success' => true,
                'message' => 'Siparişiniz oluşturuldu.',
                'data'    => $order->toArray(),
            ], 201);

        } catch (ValidationException $ve) {
            // 422: validasyon
            return response()->json([
                'success' => false,
                'message' => 'Geçersiz veri.',
                'errors'  => $ve->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('ORDER_STORE_FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sipariş oluşturulurken bir hata oluştu.',
                'error'   => config('app.debug') ? $e->getMessage() : 'SERVER_ERROR',
            ], 500);
        }
    }
public function itemsEnrichedByEmail(Request $request)
    {
        $data = $request->validate([
            'email'         => 'required|email',
            'order_number'  => 'sometimes|string',
            'per_page'      => 'sometimes|integer|min:1|max:100',
            'q'             => 'sometimes|string',
            'status'        => 'sometimes|string',     // orders.status
            'item_status'   => 'sometimes|string',     // order_items.status
            'sort'          => 'sometimes|string',
            'dir'           => 'sometimes|in:asc,desc',
        ]);

        $perPage = (int)($data['per_page'] ?? 20);
        $sort    = $data['sort'] ?? 'oi.id';
        $dir     = strtolower($data['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $allowedSort = ['oi.id','qty','unit_price','line_total','variant_name','product_name','oi.created_at'];
        if (!in_array($sort, $allowedSort, true)) $sort = 'oi.id';

        // 1) Kullanıcıyı bul
        $user = DB::table('users')->where('email', $data['email'])->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        // 2) Siparişi bul (order_number varsa ona göre, yoksa son sipariş)
        $orderQ = DB::table('orders')->where('user_id', $user->id);
        if (!empty($data['order_number'])) {
            $orderQ->where('order_number', $data['order_number']);
        } else {
            $orderQ->orderByDesc('id');
        }
        $order = $orderQ->first();
        if (!$order) {
            return response()->json(['status' => false, 'message' => 'Order not found'], 404);
        }

        // 3) Zengin kalemler (view’siz join)
        $q = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->where('oi.order_id', $order->id)
            ->selectRaw('
                oi.id                   as order_item_id,
                oi.order_id             as order_id,
                o.order_number          as order_number,
                pv.product_id           as product_id,
                p.name                  as product_name,
                oi.product_variant_id   as variant_id,
                pv.name                 as variant_name,
                oi.quantity             as qty,
                oi.unit_price           as unit_price,
                COALESCE(oi.total_price, oi.unit_price * oi.quantity) as line_total,
                p.image                 as image_path,
                o.status                as order_status,
                o.payment_status        as payment_status,
                oi.status               as item_status,
                o.created_at            as order_created_at,
                oi.created_at           as item_created_at
            ');

        if (!empty($data['status']))     $q->where('o.status', $data['status']);
        if (!empty($data['item_status']))$q->where('oi.status', $data['item_status']);
        if (!empty($data['q'])) {
            $term = $data['q'];
            $q->where(function ($w) use ($term) {
                $w->where('p.name', 'like', "%{$term}%")
                  ->orWhere('pv.name', 'like', "%{$term}%");
            });
        }

        $q->orderBy($sort, $dir);
        $paginator = $q->paginate($perPage);

        // 4) Görsel URL + cast’ler
        $items = collect($paginator->items())->map(function ($row) {
            $imageUrl = null;
            if (!empty($row->image_path)) {
                try { $imageUrl = Storage::disk('public')->url($row->image_path); }
                catch (\Throwable) { $imageUrl = url('storage/' . ltrim($row->image_path, '/')); }
            }
            return [
                'order_item_id'   => (int) $row->order_item_id,
                'order_id'        => (int) $row->order_id,
                'order_number'    => (string) $row->order_number,
                'product_id'      => $row->product_id !== null ? (int)$row->product_id : null,
                'product_name'    => (string)($row->product_name ?? '—'),
                'variant_id'      => $row->variant_id !== null ? (int)$row->variant_id : null,
                'variant_name'    => $row->variant_name,
                'qty'             => (int)$row->qty,
                'unit_price'      => $row->unit_price !== null ? (float)$row->unit_price : null,
                'line_total'      => $row->line_total !== null ? (float)$row->line_total : null,
                'image_url'       => $imageUrl,
                'order_status'    => $row->order_status,
                'payment_status'  => $row->payment_status,
                'item_status'     => $row->item_status,
                'order_created_at'=> $row->order_created_at,
                'item_created_at' => $row->item_created_at,
            ];
        });

        return response()->json([
            'status' => true,
            'data'   => [
                'order' => [
                    'id'            => (int)$order->id,
                    'order_number'  => $order->order_number,
                    'status'        => $order->status,
                    'payment_status'=> $order->payment_status,
                    'total_amount'  => $order->total_amount,
                    'created_at'    => $order->created_at,
                ],
                'data' => $items,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ],
        ]);
    }
     
 
    
 
private function createRestaurantOrder(Request $request): \App\Models\Order
{
    \Illuminate\Support\Facades\Validator::validate($request->all(), [
        'restaurant.name'     => ['required','string','max:255'],
        'restaurant.email'    => ['required','email','max:255'],
        'restaurant.phone'    => ['nullable','string','max:50'],
        'restaurant.address'  => ['nullable','string','max:500'],
        'total_amount'        => ['required','numeric','min:0'],
        'note'                => ['nullable','string','max:1000'],
        'reseller_id'         => ['nullable'],
        'items'               => ['required','array','min:1'],
        'items.*.product_variant_id' => ['required','integer'],
        'items.*.quantity'           => ['required','integer','min:1'],
        'items.*.unit_price'         => ['required','numeric','min:0'],
        'items.*.total_price'        => ['required','numeric','min:0'],
    ]);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
        $guestUser = \App\Models\User::firstOrCreate(
            ['email' => \Illuminate\Support\Arr::get($request->restaurant, 'email')],
            [
                'name'        => \Illuminate\Support\Arr::get($request->restaurant, 'name'),
                'phone'       => \Illuminate\Support\Arr::get($request->restaurant, 'phone'),
                'address'     => \Illuminate\Support\Arr::get($request->restaurant, 'address'),
                'password'    => bcrypt(\Illuminate\Support\Str::random(16)),
                'admin_level' => 0,
            ]
        );

        /** @var \App\Models\Order $order */
        $order = \App\Models\Order::create([
            'user_id'          => $guestUser->id,
             'dealer_id'          => $guestUser->dealer_id,
            'reseller_id'      => $request->input('reseller_id'),
            'total_amount'     => $request->input('total_amount'),
            'status'           => 'pending',
            'payment_status'   => 'pending',
            'note'             => $request->input('note'),
            'shipping_address' => \Illuminate\Support\Arr::get($request->restaurant, 'address'),
            'phone'            => \Illuminate\Support\Arr::get($request->restaurant, 'phone'),
            'is_guest_order'   => true,
        ]);

        foreach ((array) $request->input('items', []) as $item) {
            $order->items()->create([
                'product_variant_id' => \Illuminate\Support\Arr::get($item, 'product_variant_id'),
                'seller_id'          => null,
                'quantity'           => \Illuminate\Support\Arr::get($item, 'quantity'),
                'unit_price'         => \Illuminate\Support\Arr::get($item, 'unit_price'),
                'total_price'        => \Illuminate\Support\Arr::get($item, 'total_price'),
                'status'             => 'pending',
            ]);
        }

        $order->load(['items.productVariant.product', 'items.seller']);

        if (empty($order->order_number) && $order->id) {
            $order->order_number = 'ORD-' . now()->format('Ymd') . '-' . str_pad((string)$order->id, 6, '0', STR_PAD_LEFT);
            $order->save();
        }

        return $order; // <-- SADECE MODEL
    });
}

	private function createRegularOrder($request) {
		// Mevcut normal sipariş oluşturma mantığı
		// ... existing code ...
	}


public function orderDetailHaldeki(Request $request)
{
    // Body'den gelen veriler doğrulanıyor
    $request->validate([
        'order_number' => 'required|string',
        'email' => 'required|email',
    ]);

    // Email ile kullanıcıyı bul
    $user = \App\Models\User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Kullanıcı bulunamadı.',
        ], 404);
    }

    // Siparişi oluştururken sabit user_id=18 yerine dinamik kullanıcı id'sini kullan
    $orders = \App\Models\Order::where('user_id', $user->id)
        ->where('order_number', $request->order_number)
        ->with(['items.productVariant', 'items.seller', 'user']) // user ilişkisi eklendi
        ->latest()
        ->get();

    // Ürün isimlerini ve siparişi oluşturan kullanıcı adını ekle
    $orders->each(function ($order) {
        $order->items->each(function ($item) {
            $item->product_name = optional($item->productVariant->product)->name;
        });
        $order->created_by_name = $order->user ? $order->user->name : null;
    });

    return response()->json($orders);
}
public function orderDetailTedarik(Request $request)
{
  // Body'den gelen veriler doğrulanıyor
$request->validate([
    'order_number' => ['required', 'string'],
]);

$orderNumber = trim($request->order_number);

// SADECE order_number filtresi
$orders = \App\Models\Order::where('order_number', $orderNumber)
    ->with([
        'items.productVariant.product', // product'a da eager load
        'items.seller',
        'user',
    ])
    ->latest()
    ->get();

// Ürün isimlerini ve siparişi oluşturan kullanıcı adını ekle
$orders->each(function ($order) {
    $order->items->each(function ($item) {
        $item->product_name = optional(optional($item->productVariant)->product)->name;
    });
    $order->created_by_name = optional($order->user)->name;
});

return response()->json($orders);

}

public function orderDetailDealer(Request $request)
{
    // Body'den gelen veriler doğrulanıyor
    $request->validate([
        'order_number' => 'required|string',
        'email' => 'required|email',
    ]);

    // Email ile kullanıcıyı bul
    $user = \App\Models\User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Kullanıcı bulunamadı.',
        ], 404);
    }

    // Siparişi oluştururken sabit user_id=18 yerine dinamik kullanıcı id'sini kullan
    $orders = \App\Models\Order::where('dealer_id', $user->id)
        ->where('order_number', $request->order_number)
        ->with(['items.productVariant', 'items.seller', 'user']) // user ilişkisi eklendi
        ->latest()
        ->get();

    // Ürün isimlerini ve siparişi oluşturan kullanıcı adını ekle
    $orders->each(function ($order) {
        $order->items->each(function ($item) {
            $item->product_name = optional($item->productVariant->product)->name;
        });
        $order->created_by_name = $order->user ? $order->user->name : null;
    });

    return response()->json($orders);
}
  public function orderUpdateStatus(Request $request)
    {
        $data = $request->validate([
            'email'        => 'required|email',
            'order_number' => 'required|string',
            'status'       => 'required|string', // TR gelecek (örn: "onaylandı")
        ]);

        // 1) Bayi
        $dealer = User::where('email', $data['email'])->first();
        if (!$dealer) {
            return response()->json(['success' => false, 'message' => 'Bayi bulunamadı'], 404);
        }

        // 2) Sipariş (bu bayiye ait)
        $order = Order::where('order_number', $data['order_number'])
            ->where('dealer_id', $dealer->id)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Sipariş bulunamadı'], 404);
        }

        // 3) TR -> EN çeviri + izinli kontrol
        $statusEn = OrderStatus::toEnStatus($data['status']); // "onaylandı" -> "confirmed"
        $allowed  = ['pending','confirmed','cancelled']; // bayi–tedarikçi akışı
        if (!in_array($statusEn, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Geçersiz durum',
                'allowed' => array_map(fn($e) => OrderStatus::toTr($e), $allowed), // TR liste
            ], 422);
        }

        // 4) DB'ye yaz (dealer_status veya status — sen hangi kolonu kullanıyorsan onu güncelle)
        // Eğer bayi–tedarikçi akışını ayrı kolonla tutuyorsan:
        // $order->dealer_status = $statusEn;
        // Yok, tek kolon kullanıyorsan:
        $order->status = $statusEn;

        $order->save();

        return response()->json([
            'success' => true,
            'order'   => [
                'id'               => $order->id,
                'order_number'     => $order->order_number,
                'status'           => $order->status,               // EN
                'status_tr'        => OrderStatus::toTr($order->status), // TR
            ],
        ]);
    }
public function previousOrdersOfRestaurant(Request $request)
{
    $request->validate([
        'email' => 'required|email',
    ]);

    $user = \App\Models\User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Kullanıcı bulunamadı.',
        ], 404);
    }

    $orders = \App\Models\Order::where('user_id', $user->id)
       ->with(['items.productVariant'])  

       // ->with(['items.productVariant', 'items.seller'])
        ->latest()
        ->get();

    return response()->json($orders);
}


	public function show(Order $order) {
		if ($order->user_id !== Auth::id()) {
			return $this->error('Bu işlem için yetkiniz yok.', 403);
		}

		$order->load(['items.productVariant.product', 'items.seller']);

		return $this->success($order);
	}
	// app/Http/Controllers/Api/OrderController.php
	
	    public function getStatusByNumber(string $order_number)
    {
        $order = Order::where('order_number', $order_number)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'status'       => $order->status,
            ],
        ]);
    }
      public function setStatusByNumber(Request $request, string $order_number)
    {
         $order = Order::where('order_number', $order_number)->firstOrFail();

        // 1) Normalize: gelen alias/typo -> canonical
        $raw = strtolower(trim((string) $request->input('status', '')));
        $aliases = [
            // pending
            'pendig'   => 'pending',
            'pending'  => 'pending',
            'incoming' => 'pending',
            'awaiting' => 'pending',

            // confirmed
            'confirmed' => 'confirmed',
            'confirm'   => 'confirmed',
            'approved'  => 'confirmed',
            'approve'   => 'confirmed',

            // cancelled
            'cancelled' => 'cancelled',
            'canceled'  => 'cancelled',
            'cancel'    => 'cancelled',
        ];
        $normalized = $aliases[$raw] ?? $raw;

        // 2) Sadece bu 3 statüye izin ver
        $data = Validator::make(
            ['status' => $normalized, 'reason' => $request->input('reason')],
            [
                'status' => ['required', Rule::in(['pending','confirmed','cancelled'])],
                'reason' => ['nullable','string','max:500'],
            ]
        )->validate();

        // 3) Final statüden dönüş yok
        if ($order->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'İptal edilmiş siparişin durumu değiştirilemez',
            ], 422);
        }

        // 4) Kaydet
        $order->status = $data['status'];
        if ($data['status'] === 'cancelled' && filled($data['reason'] ?? null)) {
            $order->cancel_reason = $data['reason'];
        }
        $order->save();

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'status'       => $order->status,
            ],
        ]);
    }
    
 
 public function supplierOrdersorj(Request $request)
{
    $data = $request->validate([
        'email'     => 'sometimes|email',
        'dealer_id' => 'sometimes|integer',
        'user_id'   => 'sometimes|integer',
        'city'      => 'sometimes|string',
        'district'  => 'sometimes|string',
        'status'    => 'sometimes|string', // TR/EN gelebilir, normalize edeceğiz
    ]);

    // --- 1) dealer_id + buyer_id çöz
    $dealerId = null;
    $buyerId  = $data['user_id'] ?? null;

    if (!empty($data['dealer_id'])) {
        $dealerId = (int) $data['dealer_id'];
    } elseif (!empty($data['email'])) {
        $u = User::where('email', $data['email'])->first();
        if (!$u) {
            return response()->json(['success' => false, 'message' => 'Kullanıcı (email) bulunamadı.'], 404);
        }

        if (in_array($u->user_type, ['dealer','vendor','administrator'], true)) {
            // e-mail bir bayiye ait
            $dealerId = (int) $u->id;
        } else {
            // e-mail buyer’a ait -> vendor_id’den dealer bul
            if (!empty($u->vendor_id)) {
                $dealerId = (int) $u->vendor_id;
                $buyerId  = $buyerId ?: (int) $u->id;
            } else {
                // geçmiş siparişlerden çıkar
                $maybeDealer = Order::where('user_id', $u->id)->value('dealer_id');
                if ($maybeDealer) {
                    $dealerId = (int) $maybeDealer;
                    $buyerId  = $buyerId ?: (int) $u->id;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bu e-posta için bağlı bayi (vendor_id/dealer_id) bulunamadı.'
                    ], 422);
                }
            }
        }
    } else {
        return response()->json([
            'success' => false,
            'message' => 'dealer_id veya email göndermelisiniz.'
        ], 422);
    }

    // --- 2) dealer_status kolonu var mı?
    $hasDealerStatusCol = Schema::hasColumn('orders', 'dealer_status');

    // --- 3) Status filtresi (TR/EN normalize)
    $statusFilter = $data['status'] ?? null;
    if ($statusFilter) {
        $statusFilter = $this->toEnStatus($statusFilter); // pending/confirmed/away/delivered/cancelled
    }

    // --- 4) Sorgu
    $q = Order::query()
        ->where('orders.dealer_id', $dealerId)
        ->where('orders.status', 'confirmed') // SADECE confirmed
        ->when($buyerId, fn($qq) => $qq->where('orders.user_id', $buyerId))
        ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.user_id')
        ->leftJoin('users as d',     'd.id',     '=', 'orders.dealer_id')
        ->select([
            // siparişin temel kolonları
            'orders.id',
            'orders.order_number',
            'orders.user_id',
            'orders.dealer_id',
            'orders.status',
            DB::raw($hasDealerStatusCol ? 'orders.dealer_status' : 'orders.status as dealer_status'),
            'orders.payment_status',
            'orders.total_amount',
            'orders.created_at',

            // buyer alias'ları
            DB::raw('buyer.name     as buyer_name'),
            DB::raw('buyer.email    as buyer_email'),
            DB::raw('buyer.city     as buyer_city'),
            DB::raw('buyer.district as buyer_district'),

            // dealer alias'ları
            DB::raw('d.name         as dealer_name'),
            DB::raw('d.email        as dealer_email'),
            DB::raw('d.city         as dealer_city'),
            DB::raw('d.district     as dealer_district'),
            DB::raw('d.phone        as dealer_phone'),
        ])
        ->when(!empty($data['city']), function ($qq) use ($data) {
            $qq->where(function ($w) use ($data) {
                $w->where('buyer.city', $data['city'])
                  ->orWhere('orders.city', $data['city']); // orders.city varsa
            });
        })
        ->when(!empty($data['district']), function ($qq) use ($data) {
            $qq->where(function ($w) use ($data) {
                $w->where('buyer.district', $data['district'])
                  ->orWhere('orders.district', $data['district']); // orders.district varsa
            });
        })
        ->when($statusFilter, function ($qq, $s) use ($hasDealerStatusCol) {
            $col = $hasDealerStatusCol ? 'orders.dealer_status' : 'orders.status';
            $qq->where($col, $s);
        })
        ->with([
            'items' => function ($q) {
                $cols = [
                    'id','order_id','product_variant_id','seller_id',
                    'quantity','unit_price','total_price'
                ];
                if (Schema::hasColumn('order_items', 'dealer_status')) {
                    $cols[] = 'dealer_status';
                }
                if (Schema::hasColumn('order_items', 'supplier_status')) {
                    $cols[] = 'supplier_status';
                }

                $q->select($cols)
                  ->with([
                      'productVariant:id,name,product_id',
                      'seller:id,name',
                  ]);
            },
        ])
        ->orderByDesc('orders.id');

    $rows = $q->get();

    // --- 5) Yanıtı nested yapıya dönüştür
    $out = $rows->map(function ($r) {
        return [
            'id'            => (int)$r->id,
            'order_number'  => $r->order_number,
            'user_id'       => (int)$r->user_id,   // buyer id
            'dealer_id'     => (int)$r->dealer_id, // BAYİ id (düz alan)
            'status'        => $r->status,         // orders.status (EN)
            'dealer_status' => $r->dealer_status,  // dealer_status varsa, yoksa status alias (EN)
            'payment_status'=> $r->payment_status,
            'total_amount'  => (float)$r->total_amount,
            'created_at'    => $r->created_at,

            // nested buyer
            'buyer' => [
                'id'       => (int)$r->user_id,
                'name'     => $r->buyer_name,
                'email'    => $r->buyer_email,
                'city'     => $r->buyer_city,
                'district' => $r->buyer_district,
            ],

            // nested dealer (BAYİ) —> ekranda “hangi bayiden sipariş gelmiş” net görülsün
            'dealer' => [
                'id'       => (int)$r->dealer_id,
                'name'     => $r->dealer_name,
                'email'    => $r->dealer_email,
                'city'     => $r->dealer_city,
                'district' => $r->dealer_district,
                'phone'    => $r->dealer_phone,
            ],

            // ilişkiler
            'items' => $r->items, // eager-loaded
        ];
    })->values();

    return response()->json([
        'success'  => true,
        'resolved' => [
            'dealer_id' => $dealerId,
            'buyer_id'  => $buyerId,
        ],
        'data' => $out,
    ]);
}
public function supplierOrdersaq(Request $request)
{
    $data = $request->validate([
        'email'     => 'sometimes|email',
        'buyer_id'  => 'sometimes|integer',
        'status'    => 'sometimes|string',
        'city'      => 'sometimes|string',
        'district'  => 'sometimes|string',
    ]);

    // --- 1) buyer_id çöz
    $buyerId = $data['buyer_id'] ?? null;

    if (!$buyerId && !empty($data['email'])) {
        $u = User::where('email', $data['email'])->first();
        if (!$u) {
            return response()->json(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], 404);
        }
        $buyerId = (int) $u->id;
    }

    if (!$buyerId) {
        return response()->json([
            'success' => false,
            'message' => 'buyer_id veya email göndermelisiniz.'
        ], 422);
    }

    // --- 2) dealer_status kolonu var mı?
    $hasDealerStatusCol = Schema::hasColumn('orders', 'dealer_status');

    // --- 3) Status filtresi (normalize)
    $statusFilter = $data['status'] ?? null;
    if ($statusFilter) {
        $statusFilter = $this->toEnStatus($statusFilter);
    }

    // --- 4) Sorgu
    $q = Order::query()
        ->where('orders.buyer_id', $buyerId)
        ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.buyer_id')
        ->leftJoin('users as d',     'd.id',     '=', 'orders.dealer_id')
        ->select([
            'orders.id',
            'orders.order_number',
            'orders.buyer_id',
            'orders.dealer_id',
            'orders.status',
            DB::raw($hasDealerStatusCol ? 'orders.dealer_status' : 'orders.status as dealer_status'),
            'orders.payment_status',
            'orders.total_amount',
            'orders.created_at',
            'orders.supplier_status',
            DB::raw('buyer.name     as buyer_name'),
            DB::raw('buyer.email    as buyer_email'),
            DB::raw('buyer.city     as buyer_city'),
            DB::raw('buyer.district as buyer_district'),

            DB::raw('d.name         as dealer_name'),
            DB::raw('d.email        as dealer_email'),
            DB::raw('d.city         as dealer_city'),
            DB::raw('d.district     as dealer_district'),
            DB::raw('d.phone        as dealer_phone'),
        ])
        ->when(!empty($data['city']), function ($qq) use ($data) {
            $qq->where(function ($w) use ($data) {
                $w->where('buyer.city', $data['city'])
                  ->orWhere('orders.city', $data['city']);
            });
        })
        ->when(!empty($data['district']), function ($qq) use ($data) {
            $qq->where(function ($w) use ($data) {
                $w->where('buyer.district', $data['district'])
                  ->orWhere('orders.district', $data['district']);
            });
        })
        ->when($statusFilter, function ($qq, $s) use ($hasDealerStatusCol) {
            $col = $hasDealerStatusCol ? 'orders.dealer_status' : 'orders.status';
            $qq->where($col, $s);
        })
        ->with([
            'items' => function ($q) {
                $cols = ['id','order_id','product_variant_id','seller_id','quantity','unit_price','total_price','supplier_status'];
                if (Schema::hasColumn('order_items', 'dealer_status'))   $cols[] = 'dealer_status';
                if (Schema::hasColumn('order_items', 'supplier_status')) $cols[] = 'supplier_status';

                $q->select($cols)->with([
                    'productVariant:id,name,product_id',
                    'seller:id,name',
                ]);
            },
        ])
        ->orderByDesc('orders.id');

    $rows = $q->get();

    // --- 5) Yanıt
    $out = $rows->map(function ($r) {
        return [
            'id'            => (int)$r->id,
            'order_number'  => $r->order_number,
            'buyer_id'      => (int)$r->buyer_id,
            'dealer_id'     => (int)$r->dealer_id,
            'status'        => $r->status,
            'dealer_status' => $r->dealer_status,
            'payment_status'=> $r->payment_status,
            'supplier_status'=> $r->supplier_status,
            'total_amount'  => (float)$r->total_amount,
            'created_at'    => $r->created_at,

            'buyer' => [
                'id'       => (int)$r->buyer_id,
                'name'     => $r->buyer_name,
                'email'    => $r->buyer_email,
                'city'     => $r->buyer_city,
                'district' => $r->buyer_district,
            ],

            'dealer' => [
                'id'       => (int)$r->dealer_id,
                'name'     => $r->dealer_name,
                'email'    => $r->dealer_email,
                'city'     => $r->dealer_city,
                'district' => $r->dealer_district,
                'phone'    => $r->dealer_phone,
            ],

            'items' => $r->items,
        ];
    })->values();

    return response()->json([
        'success'  => true,
        'resolved' => [
            'buyer_id'  => $buyerId,
            'email'     => $data['email'] ?? null,
        ],
        'data' => $out,
    ]);
}

public function supplierOrdersyavuz(Request $request)
{
    $data = $request->validate([
        'email'     => 'sometimes|email',
        'buyer_id'  => 'sometimes|integer',
        'status'    => 'sometimes|string',
        'city'      => 'sometimes|string',
        'district'  => 'sometimes|string',
    ]);

    // --- 1) buyer_id çöz
    $buyerId = $data['buyer_id'] ?? null;

    if (!$buyerId && !empty($data['email'])) {
        $u = User::where('email', $data['email'])->first();
        if (!$u) {
            return response()->json(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], 404);
        }
        $buyerId = (int) $u->id;
    }

    if (!$buyerId) {
        return response()->json([
            'success' => false,
            'message' => 'buyer_id veya email göndermelisiniz.'
        ], 422);
    }

    // --- 2) dealer_status kolonu var mı?
    $hasDealerStatusCol = Schema::hasColumn('orders', 'dealer_status');

    // --- 3) Status filtresi (normalize)
    $statusFilter = $data['status'] ?? null;
    if ($statusFilter) {
        $statusFilter = $this->toEnStatus($statusFilter);
    }

    // --- 4) Sorgu
 // --- 4) Sorgu
$q = Order::query()
    ->where('orders.buyer_id', $buyerId)
    ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.buyer_id')
    ->leftJoin('users as d',     'd.id',     '=', 'orders.dealer_id')
    ->select([
        'orders.id',
        'orders.order_number',
        'orders.buyer_id',
        'orders.dealer_id',
        'orders.status',
        DB::raw($hasDealerStatusCol ? 'orders.dealer_status' : 'orders.status as dealer_status'),
        'orders.payment_status',
        'orders.total_amount',
        'orders.created_at',
        'orders.supplier_status',
        DB::raw('buyer.name     as buyer_name'),
        DB::raw('buyer.email    as buyer_email'),
        DB::raw('buyer.city     as buyer_city'),
        DB::raw('buyer.district as buyer_district'),
        DB::raw('d.name         as dealer_name'),
        DB::raw('d.email        as dealer_email'),
        DB::raw('d.city         as dealer_city'),
        DB::raw('d.district     as dealer_district'),
        DB::raw('d.phone        as dealer_phone'),
    ])

    // Şehir / ilçe
    ->when(!empty($data['city']), function ($qq) use ($data) {
        $qq->where(function ($w) use ($data) {
            $w->where('buyer.city', $data['city'])
              ->orWhere('orders.city', $data['city']);
        });
    })
    ->when(!empty($data['district']), function ($qq) use ($data) {
        $qq->where(function ($w) use ($data) {
            $w->where('buyer.district', $data['district'])
              ->orWhere('orders.district', $data['district']);
        });
    })

    // Status filtresi gelirse uygula (normalize edilmiş EN)
    ->when($statusFilter, function ($qq, $s) use ($hasDealerStatusCol) {
        $col = $hasDealerStatusCol ? 'orders.dealer_status' : 'orders.status';
        $qq->where($col, $s);
    })

    // Status parametresi GELMEDİYSE, pending'leri dışla
  ->when(!$statusFilter, function ($qq) {
    $qq->where('orders.status', '!=', 'pending');
})


    ->with([
        'items' => function ($q) {
            $cols = ['id','order_id','product_variant_id','seller_id','quantity','unit_price','total_price','supplier_status'];
            if (Schema::hasColumn('order_items', 'dealer_status'))   $cols[] = 'dealer_status';
            if (Schema::hasColumn('order_items', 'supplier_status')) $cols[] = 'supplier_status';
            $q->select($cols)->with([
                'productVariant:id,name,product_id',
                'seller:id,name',
            ]);
        },
    ])
    ->orderByDesc('orders.id');
 $rows = $q->get();

    // --- 5) Yanıt
    $out = $rows->map(function ($r) {
        return [
            'id'            => (int)$r->id,
            'order_number'  => $r->order_number,
            'buyer_id'      => (int)$r->buyer_id,
            'dealer_id'     => (int)$r->dealer_id,
            'status'        => $r->status,
            'dealer_status' => $r->dealer_status,
            'payment_status'=> $r->payment_status,
            'supplier_status'=> $r->supplier_status,
            'total_amount'  => (float)$r->total_amount,
            'created_at'    => $r->created_at,

            'buyer' => [
                'id'       => (int)$r->buyer_id,
                'name'     => $r->buyer_name,
                'email'    => $r->buyer_email,
                'city'     => $r->buyer_city,
                'district' => $r->buyer_district,
            ],

            'dealer' => [
                'id'       => (int)$r->dealer_id,
                'name'     => $r->dealer_name,
                'email'    => $r->dealer_email,
                'city'     => $r->dealer_city,
                'district' => $r->dealer_district,
                'phone'    => $r->dealer_phone,
            ],

            'items' => $r->items,
        ];
    })->values();

    return response()->json([
        'success'  => true,
        'resolved' => [
            'buyer_id'  => $buyerId,
            'email'     => $data['email'] ?? null,
        ],
        'data' => $out,
    ]);
}
public function supplierOrders(Request $request)
{
    $data = $request->validate([
        'email'     => 'sometimes|email',
        'buyer_id'  => 'sometimes|integer',
        'status'    => 'sometimes|string',
        'city'      => 'sometimes|string',
        'district'  => 'sometimes|string',
    ]);

    $buyerId  = !empty($data['buyer_id']) ? (int) $data['buyer_id'] : null;
    $dealerId = null;
    $actor    = null;

    if (!empty($data['email'])) {
        $actor = User::where('email', $data['email'])->first();
        if (!$actor) {
            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı bulunamadı.',
            ], 404);
        }
    }

    if ($buyerId === null && $actor !== null) {
        if (in_array($actor->user_type, ['dealer', 'vendor', 'administrator'], true)) {
            $dealerId = (int) $actor->id;
        } else {
            $buyerId = (int) $actor->id;
        }
    }

    if ($buyerId === null && $dealerId === null) {
        return response()->json([
            'success' => false,
            'message' => 'buyer_id veya email göndermelisiniz.',
        ], 422);
    }

    $hasDealerStatusCol   = Schema::hasColumn('orders', 'dealer_status');
    $hasSupplierStatusCol = Schema::hasColumn('orders', 'supplier_status');
    $hasBuyerIdCol        = Schema::hasColumn('orders', 'buyer_id');
    $ownerCol             = $hasBuyerIdCol ? 'orders.buyer_id' : 'orders.user_id';
    $statusFilter         = !empty($data['status'])
        ? $this->mapDashboardStatusFilter((string) $data['status'])
        : null;

    $q = Order::query()
        ->when($buyerId !== null, fn ($qq) => $qq->where($ownerCol, $buyerId))
        ->when($dealerId !== null, fn ($qq) => $qq->where('orders.dealer_id', $dealerId))
        ->leftJoin('users as buyer', 'buyer.id', '=', DB::raw($ownerCol))
        ->leftJoin('users as d',     'd.id',     '=', 'orders.dealer_id')
        ->select([
            'orders.id',
            'orders.order_number',
            DB::raw($ownerCol . ' as buyer_id'),
            'orders.dealer_id',
            'orders.status',
            DB::raw($hasDealerStatusCol
                ? 'orders.dealer_status'
                : 'orders.status as dealer_status'),
            'orders.payment_status',
            'orders.total_amount',
            'orders.created_at',
            'orders.shipping_address',
            'orders.ad_soyad',
            $hasSupplierStatusCol ? 'orders.supplier_status' : DB::raw("'pending' as supplier_status"),
            DB::raw('buyer.name     as buyer_name'),
            DB::raw('buyer.email    as buyer_email'),
            DB::raw('buyer.city     as buyer_city'),
            DB::raw('buyer.district as buyer_district'),

            DB::raw('d.name         as dealer_name'),
            DB::raw('d.email        as dealer_email'),
            DB::raw('d.city         as dealer_city'),
            DB::raw('d.district     as dealer_district'),
            DB::raw('d.phone        as dealer_phone'),
        ])
        ->when(!empty($data['city']), function ($qq) use ($data) {
            $qq->where(function ($w) use ($data) {
                $w->where('buyer.city', $data['city'])
                  ->orWhere('orders.city', $data['city']);
            });
        })
        ->when(!empty($data['district']), function ($qq) use ($data) {
            $qq->where(function ($w) use ($data) {
                $w->where('buyer.district', $data['district'])
                  ->orWhere('orders.district', $data['district']);
            });
        })
        ->when($statusFilter, function ($qq, $s) use ($hasDealerStatusCol, $hasSupplierStatusCol) {
            $qq->where(function ($w) use ($s, $hasDealerStatusCol, $hasSupplierStatusCol) {
                $w->where('orders.status', $s);
                if ($hasDealerStatusCol) {
                    $w->orWhere('orders.dealer_status', $s);
                }
                if ($hasSupplierStatusCol) {
                    $w->orWhere('orders.supplier_status', $s);
                }
            });
        })
        ->with([
            'items' => function ($q) {
                $cols = [
                    'id',
                    'order_id',
                    'product_variant_id',
                    'seller_id',
                    'quantity',
                    'unit_price',
                    'total_price',
                ];

                if (Schema::hasColumn('order_items', 'dealer_status')) {
                    $cols[] = 'dealer_status';
                }
                if (Schema::hasColumn('order_items', 'supplier_status')) {
                    $cols[] = 'supplier_status';
                }

                $q->select($cols)->with([
                    'productVariant:id,name,product_id',
                    'productVariant.product:id,name',
                    'seller:id,name',
                ]);
            },
        ])
        ->orderByDesc('orders.id');

    $rows = $q->get();

    $out = $rows->map(fn ($r) => $this->mapOrderForDashboard($r))->values();

    return response()->json([
        'success'  => true,
        'resolved' => [
            'buyer_id' => $buyerId,
            'dealer_id'=> $dealerId,
            'email'    => $data['email'] ?? null,
        ],
        'data' => $out,
    ]);
}
  /**
     * POST /api/v1/dealer-order-update-status
     * Body: { email, order_number, status, update_items?=true, note? }
     */
      private function mapSupplierStatusToEno(string $input): string
{
    $t = mb_strtolower(trim($input), 'UTF-8');
    $t = str_replace(['ı','İ','Ğ','ğ','Ş','ş','Ü','ü','Ö','ö','Ç','ç'], ['i','i','g','g','s','s','u','u','o','o','c','c'], $t);

    // EN doğrudan geldiyse olduğu gibi kabul
    if (in_array($t, ['pending','confirmed','away','delivered','cancelled','canceled'], true)) {
        return $t === 'canceled' ? 'cancelled' : $t;
    }

    // TR -> EN normalize
    if (str_contains($t, 'hazir'))   return 'pending';     // hazırlanıyor
    if (str_contains($t, 'onay'))    return 'confirmed';   // onaylandı
    if (str_contains($t, 'sevk') ||
        str_contains($t, 'yol')  ||
        str_contains($t, 'transit')) return 'away';        // sevk/yolda tek başlıkta
    if (str_contains($t, 'teslim'))  return 'delivered';
    if (str_contains($t, 'iptal') ||
        str_contains($t, 'cancel'))  return 'cancelled';

    return $t; // tanınmazsa ham döndür (sonra allowed check yakalar)
}

private function mapSupplierStatusToTro(string $en): string
{
    $e = mb_strtolower(trim($en), 'UTF-8');
    if ($e === 'canceled') $e = 'cancelled';

    return match ($e) {
        'pending'   => 'hazırlanıyor',
        'confirmed' => 'onaylandı',
        'away'      => 'sevk edildi',
        'delivered' => 'teslim edildi',
        'cancelled' => 'iptal',
        default     => $en,
    };
}
private function mapSupplierStatusToEn(string $input): string
{
    $t = mb_strtolower(trim($input), 'UTF-8');

    // TR harflerini sadeleştir
    $t = str_replace(
        ['ı','İ','Ğ','ğ','Ş','ş','Ü','ü','Ö','ö','Ç','ç'],
        ['i','i','g','g','s','s','u','u','o','o','c','c'],
        $t
    );

    // EN varyasyonlarını tekilleştir (allowed set’e indir)
    // Not: 'canceled' -> 'cancelled'
    if (in_array($t, [
        'waiting','wait','on_hold',
        'pending','prepare','preparing',
        'shipped','ship','shipping','in_transit','on_the_way','transit','away',
        'delivered','complete','completed',
        'cancelled','canceled','cancel'
    ], true)) {
        return match ($t) {
            'wait','on_hold'                  => 'waiting',
            'prepare','preparing'             => 'pending',
            'ship','shipping','in_transit','on_the_way','transit','away'
                                            => 'shipped',
            'complete','completed'            => 'delivered',
            'canceled','cancel'               => 'cancelled',
            default                           => $t, // waiting|pending|shipped|delivered|cancelled
        };
    }

    // TR -> EN (senin akışına göre)
    if (str_contains($t, 'bekli'))           return 'waiting';   // bekliyor
    if (str_contains($t, 'hazir'))           return 'pending';   // hazırlanıyor
    if (str_contains($t, 'onay'))            return 'pending';   // onaylandı -> en yakın aşama
    if (str_contains($t, 'sevk') || str_contains($t, 'yol') || str_contains($t, 'transit'))
                                                return 'shipped';   // sevk/yolda
    if (str_contains($t, 'teslim'))          return 'delivered'; // teslim edildi
    if (str_contains($t, 'iptal') || str_contains($t, 'cancel'))
                                                return 'cancelled';

    // Tanınmazsa ham döndür (allowed check yakalar)
    return $t;
}


private function mapSupplierStatusToTr(string $en): string
{
    $e = mb_strtolower(trim($en), 'UTF-8');
    if ($e === 'canceled') $e = 'cancelled';

    return match ($e) {
        'waiting'   => 'bekliyor',
        'pending'   => 'hazırlanıyor',
        'shipped'   => 'sevk edildi',
        'delivered' => 'teslim edildi',
        'cancelled' => 'iptal',
        default     => $en,
    };
}

public function supplierUpdateStatuso(Request $request)
{
    $data = $request->validate([
        'email'           => ['required','email'],   // TEDARİKÇİ e-postası
        'order_number'    => ['required','string'],
        'supplier_status' => ['required','string'],  // TR (örn: "onaylandı") ya da EN (örn: "confirmed")
        'note'            => ['sometimes','string'],
        'update_items'    => ['sometimes','boolean'], // default: true
    ]);

    // 1) Kullanıcıyı bul
    /** @var User|null $actor */
    $actor = User::where('email', $data['email'])->first();
    if (!$actor) {
        return response()->json(['success'=>false,'message'=>'User not found'], 404);
    }

    // 2) Sadece TEDARİKÇİ/SATAN kullanıcılar güncelleyebilsin
    // (İsterseniz admin’i de ekleyebilirsiniz.)
    $isSupplier = in_array($actor->user_type, ['supplier','seller'], true);
    $isAdmin    = in_array($actor->user_type, ['administrator'], true);
   /* if (!$isSupplier && !$isAdmin) {
        return response()->json(['success'=>false,'message'=>'Not authorized as supplier'], 403);
    }
*/
    // 3) Siparişi bul
    /** @var Order|null $order */
    $order = Order::where('order_number', $data['order_number'])->first();
    if (!$order) {
        return response()->json(['success'=>false,'message'=>'Order not found'], 404);
    }

    // 4) Bu tedarikçinin bu siparişte kalemi var mı? (admin ise atla)
    if (!$isAdmin) {
        $hasItems = DB::table('order_items')
            ->where('order_id', $order->id)
           
            ->exists();

        if (!$hasItems) {
            return response()->json([
                'success'=>false,
                'message'=>'This order has no items for this supplier'
            ], 403);
        }
    }

    // 5) TR/EN -> EN normalize + izinli set kontrolü
    $statusCode = $this->mapSupplierStatusToEn($data['supplier_status']);
    $allowed = ['pending','confirmed','away','delivered','cancelled'];
    if (!in_array($statusCode, $allowed, true)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid status',
            'allowed' => array_map([$this,'mapSupplierStatusToTr'], $allowed),
        ], 422);
    }

    // 6) Yazılacak kolon (orders.supplier_status varsa oraya, yoksa orders.status’a)
    $updateItems = (bool)($data['update_items'] ?? true);

    DB::transaction(function () use ($order, $statusCode, $updateItems, $actor, $isAdmin, $data) {
        if (Schema::hasColumn('orders', 'supplier_status')) {
            $order->supplier_status = $statusCode;
        } else {
            // UYARI: Listeleriniz orders.status = 'confirmed' ile kısıtlıysa,
            // supplier akışını ayrı kolon (supplier_status) ile yönetmeniz tavsiye edilir.
            $order->supplier_status = $statusCode;
        }

        if (!empty($data['note']) && Schema::hasColumn('orders','note')) {
            $order->note = trim((string)$data['note']);
        }

        $order->save();

        // Kalem güncelle (sadece bu tedarikçinin kalemleri)
        if ($updateItems && Schema::hasColumn('order_items','supplier_status')) {
            $q = DB::table('order_items')->where('order_id', $order->id);
            if (!$isAdmin) {
                $q->where('seller_id', $actor->id);
            }
            $q->update(['supplier_status' => $statusCode]);
        }
    });

    // 7) Yanıt (EN + TR)
    $statusEn = Schema::hasColumn('orders','supplier_status') ? $order->supplier_status : $order->status;
    return response()->json([
        'success' => true,
        'message' => 'Supplier status updated',
        'data'    => [
            'order_id'         => (int)$order->id,
            'order_number'     => $order->order_number,
            'supplier_status'  => $statusEn,                          // EN
            'status_label_tr'  => $this->mapSupplierStatusToTr($statusEn), // TR
        ],
    ]);
}
public function supplierUpdateStatus(Request $request)
{
    $data = $request->validate([
        'email'           => ['required','email'],
        'order_number'    => ['required','string'],
        'supplier_status' => ['required','string'],  // TR ya da EN gelebilir
        'note'            => ['sometimes','string'],
    ]);

    // 1) User
    $actor = User::where('email', $data['email'])->first();
    if (!$actor) {
        return response()->json(['success'=>false,'message'=>'User not found'], 404);
    }

    // 2) Order
    $order = Order::where('order_number', $data['order_number'])->first();
    if (!$order) {
        return response()->json(['success'=>false,'message'=>'Order not found'], 404);
    }

    // 3) Normalize to allowed set
    $statusCode = $this->mapSupplierStatusToEn($data['supplier_status']);
    $allowed = ['waiting','pending','shipped','delivered','cancelled'];
    if (!in_array($statusCode, $allowed, true)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid status',
            'allowed' => array_map([$this,'mapSupplierStatusToTr'], $allowed),
        ], 422);
    }

    // 4) Kolon var mı?
    if (!\Schema::hasColumn('orders','supplier_status')) {
        return response()->json([
            'success' => false,
            'message' => 'orders.supplier_status column not found.',
        ], 500);
    }

    // 5) Sadece supplier_status’u güncelle
    \DB::table('orders')
        ->where('id', $order->id)
        ->update([
            'supplier_status'            => $statusCode,   // waiting|pending|shipped|delivered|cancelled
            'supplier_status_updated_at' => now(),
            'updated_at'                 => now(),
            'note'                       => $data['note'] ?? $order->note,
        ]);

    $order->refresh();

    return response()->json([
        'success' => true,
        'message' => 'Supplier status updated',
        'data'    => [
            'order_id'         => (int)$order->id,
            'order_number'     => $order->order_number,
            'supplier_status'  => $order->supplier_status,                      // EN
            'status_label_tr'  => $this->mapSupplierStatusToTr($order->supplier_status), // TR
        ],
    ]);
}


public function dealerw(Request $request)
{
    $request->validate([
        'email' => 'required|email',
    ]);

    $dealer = \App\Models\User::where('email', $request->email)->first();

    if (!$dealer) {
        return response()->json([
            'success' => false,
            'message' => 'Kullanıcı (bayi) bulunamadı.',
        ], 404);
    }

    $orders = \App\Models\Order::query()
        ->where('orders.dealer_id', $dealer->id)

        // ✅ buyer JOIN
        ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.user_id')

        // ✅ partner_client JOIN (orders.partner_client_id -> partner_clients.id)
        ->leftJoin('partner_clients as pc', 'pc.id', '=', 'orders.partner_client_id')

        ->select([
            'orders.id',
            'orders.order_number',
            'orders.user_id',
            'orders.dealer_id',
             'orders.user_type',
               'orders.ad_soyad',
            'orders.partner_client_id', 
             'orders.partner_order_id', // ✅ bunu da seçelim
            'orders.status',
            'orders.dealer_status',
            'orders.supplier_status',
            'orders.payment_status',
            'orders.delivery_status',
            'orders.total_amount',
            'orders.created_at',
            'orders.shipping_address',
            DB::raw('buyer.name      as buyer_name'),
            DB::raw('buyer.city      as buyer_city'),
            DB::raw('buyer.district  as buyer_district'),
            DB::raw('buyer.user_type as buyer_type'),

            // ✅ partner_client alanları
            DB::raw('pc.name    as partner_client_name'),
            DB::raw('pc.address as partner_client_address'),
            DB::raw('pc.latitude     as partner_client_lat'),
            DB::raw('pc.longitude    as partner_client_long'),
        ])
        ->with([
            'items' => function ($q) {
                $q->select('id','order_id','product_variant_id','seller_id','quantity','unit_price','total_price','status','dealer_status','supplier_status')
                  ->with([
                      'productVariant:id,name,product_id',
                      'productVariant.product:id,name',
                      'seller:id,name',
                  ]);
            },
        ])
        ->orderByDesc('orders.id')
        ->get();

    return response()->json(
        $orders->map(fn ($order) => $this->mapOrderForDashboard($order))->values()
    );
}

 
public function dealer(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'page' => 'nullable|integer|min:1',
        'per_page' => 'nullable|integer|min:1|max:100',
    ]);

    try {
        $dealer = User::where('email', $request->email)->first();

        if (!$dealer) {
            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı (bayi) bulunamadı.',
            ], 404);
        }

        $perPage = (int) ($request->input('per_page', 20));

        $orders = Order::query()
            ->where('orders.dealer_id', $dealer->id)

            ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.user_id')
            ->leftJoin('partner_clients as pc', 'pc.id', '=', 'orders.partner_client_id')

            ->select([
                'orders.id',
                'orders.order_number',
                'orders.user_id',
                'orders.dealer_id',
        //        'orders.user_type',
         //       'orders.ad_soyad',
                'orders.partner_client_id',
                'orders.partner_order_id',
                'orders.status',
                'orders.dealer_status',
                'orders.supplier_status',
                'orders.payment_status',
                'orders.delivery_status',
                'orders.total_amount',
                'orders.created_at',
                'orders.shipping_address',
                DB::raw('buyer.name as buyer_name'),
                DB::raw('buyer.city as buyer_city'),
                DB::raw('buyer.district as buyer_district'),
                DB::raw('buyer.user_type as buyer_type'),
                DB::raw('pc.name as partner_client_name'),
                DB::raw('pc.address as partner_client_address'),
                DB::raw('pc.latitude as partner_client_lat'),
                DB::raw('pc.longitude as partner_client_long'),
            ])
            ->with([
                'items' => function ($q) {
                    $q->select(
                        'id',
                        'order_id',
                        'product_variant_id',
                        'seller_id',
                        'quantity',
                        'unit_price',
                        'total_price',
                        'status',
                        'dealer_status',
                        'supplier_status'
                    )->with([
                        'productVariant:id,name,product_id',
                        'productVariant.product:id,name',
                        'seller:id,name',
                    ]);
                },
            ])
            ->orderByDesc('orders.id')
            ->paginate($perPage);

        // mapOrderForDashboard null-safe değilse tek tek try/catch ile koru
        $orders->setCollection(
            $orders->getCollection()->map(function ($order) {
                return $this->mapOrderForDashboard($order);
            })->values()
        );

        return response()->json($orders);
    } catch (\Throwable $e) {
        Log::error('dealer-orders failed', [
            'email' => $request->email,
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Sunucu hatası.',
        ], 500);
    }
}


private function mapDashboardStatusFilter(string $status): string
{
    $value = mb_strtolower(trim($status), 'UTF-8');

    if (str_contains($value, 'haz') || in_array($value, ['pending', 'waiting'], true)) {
        return 'pending';
    }
    if (
        str_contains($value, 'sevk') ||
        str_contains($value, 'yol') ||
        str_contains($value, 'kurye') ||
        in_array($value, ['courier', 'shipped', 'away'], true)
    ) {
        return 'courier';
    }
    if (str_contains($value, 'teslim') || in_array($value, ['delivered', 'closed'], true)) {
        return 'delivered';
    }
    if (str_contains($value, 'iptal') || str_contains($value, 'cancel')) {
        return 'cancelled';
    }

    return $value;
}

private function mapOrderForDashboard($row): array
{
    $items = collect($row->items ?? [])->map(function ($item) {
        $productName = optional(optional($item->productVariant)->product)->name
            ?? optional($item->productVariant)->name
            ?? 'Ürün';
        $variantName = optional($item->productVariant)->name;
        $qty = (float) ($item->quantity ?? 0);
        $lineTotal = (float) ($item->total_price ?? 0);
        $unitPrice = (float) ($item->unit_price ?? 0);

        return [
            'id'          => (int) ($item->id ?? 0),
            'order_item_id' => (int) ($item->id ?? 0),
            'product_variant_id' => $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
            'product_name' => $productName,
            'productName'  => $productName,
            'variant_name' => $variantName,
            'variantName'  => $variantName,
            'qty'          => (int) $qty,
            'quantity'     => (int) $qty,
            'qtyCases'     => $qty,
            'unit_price'   => $unitPrice,
            'price'        => $unitPrice,
            'total_price'  => $lineTotal,
            'line_total'   => $lineTotal,
            'lineTotal'    => $lineTotal,
            'status'       => $item->status ?? null,
            'dealer_status'=> $item->dealer_status ?? null,
            'supplier_status' => $item->supplier_status ?? null,
            'seller_name'  => optional($item->seller)->name,
        ];
    })->values();

    $buyerId = $row->buyer_id ?? $row->user_id ?? null;
    $createdBy = $row->created_by_name
        ?? $row->buyer_name
        ?? $row->ad_soyad
        ?? null;

    return [
        'id'               => (int) ($row->id ?? 0),
        'order_number'     => (string) ($row->order_number ?? ''),
        'buyer_id'         => $buyerId !== null ? (int) $buyerId : null,
        'user_id'          => $buyerId !== null ? (int) $buyerId : null,
        'dealer_id'        => isset($row->dealer_id) ? (int) $row->dealer_id : null,
        'status'           => $row->status ?? null,
        'dealer_status'    => $row->dealer_status ?? null,
        'supplier_status'  => $row->supplier_status ?? null,
        'payment_status'   => $row->payment_status ?? null,
        'delivery_status'  => $row->delivery_status ?? null,
        'total_amount'     => (float) ($row->total_amount ?? 0),
        'shipping_address' => $row->shipping_address ?? null,
        'created_at'       => $row->created_at ?? null,
        'created_by_name'  => $createdBy,
        'buyer_name'       => $row->buyer_name ?? $createdBy,
        'buyer' => [
            'id'       => $buyerId !== null ? (int) $buyerId : null,
            'name'     => $row->buyer_name ?? $createdBy,
            'email'    => $row->buyer_email ?? null,
            'city'     => $row->buyer_city ?? null,
            'district' => $row->buyer_district ?? null,
        ],
        'dealer' => [
            'id'       => isset($row->dealer_id) ? (int) $row->dealer_id : null,
            'name'     => $row->dealer_name ?? null,
            'email'    => $row->dealer_email ?? null,
            'city'     => $row->dealer_city ?? null,
            'district' => $row->dealer_district ?? null,
            'phone'    => $row->dealer_phone ?? null,
        ],
        'items' => $items,
    ];
}


public function dealerOld(Request $request)
{
    $request->validate([
        'email' => 'required|email',
    ]);

    $dealer = \App\Models\User::where('email', $request->email)->first();

    if (!$dealer) {
        return response()->json([
            'success' => false,
            'message' => 'Kullanıcı (bayi) bulunamadı.',
        ], 404);
    }

    $orders = \App\Models\Order::query()
        ->where('orders.dealer_id', $dealer->id)                      // bayiye ait siparişler
        ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.user_id') // buyer JOIN
        ->select([
            'orders.id',
            'orders.order_number',
            'orders.user_id',
            'orders.dealer_id',
            'orders.status',
            'orders.dealer_status',
             'orders.supplier_status',
            'orders.payment_status',
            'orders.delivery_status',
            'orders.total_amount',
            'orders.created_at',
         
            DB::raw('buyer.name    as buyer_name'),
            DB::raw('buyer.city    as buyer_city'),
            DB::raw('buyer.district as buyer_district'),
                DB::raw('buyer.user_type    as buyer_type'),
        ])
        ->with([
            // kalemleri yine eager-load edelim (JOIN'i sadece buyer için kullandık)
            'items' => function ($q) {
                $q->select('id','order_id','product_variant_id','seller_id','quantity','unit_price','total_price','status','dealer_status')
                  ->with([
                      'productVariant:id,name,product_id',
                      'seller:id,name',
                  ]);
            },
        ])
        ->orderByDesc('orders.id')
        ->get();

    return response()->json($orders);
}


  /**
     * POST /api/v1/dealer-order-update-status
     * Body: { email, order_number, status, update_items?=true, note? }
     */
public function dealerUpdateStatus2(Request $request)
{
    $data = $request->validate([
        'email'         => ['required','email'],
        'order_number'  => ['required','string'],
        'dealer_status' => ['required','string'],   // TR ya da EN gelebilir
        'update_items'  => ['sometimes','boolean'],
        'note'          => ['sometimes','nullable','string','max:1000'],
    ]);

    // 1) Bayi/vendor/admin kullanıcıyı bul
    /** @var User|null $dealer */
    $dealer = User::where('email', $data['email'])->first();
    if (!$dealer) {
        return response()->json(['dealer_status'=>false,'message'=>'Dealer user not found'], 404);
    }
    if (!in_array($dealer->user_type, ['dealer','vendor','administrator'], true)) {
        return response()->json(['dealer_status'=>false,'message'=>'Not authorized as dealer'], 403);
    }

    // 2) Gelen statüyü kanonik EN koda çevir (ENUM)
    $statusCode = $this->mapToDbStatus($data['dealer_status']); // -> en kod
    $allowed = ['pending','courier','delivered','closed','cancelled'];
    if (!in_array($statusCode, $allowed, true)) {
        return response()->json([
            'dealer_status' => false,
            'message'       => 'Invalid status. Allowed: '.implode(', ', $allowed),
        ], 422);
    }

    // 3) Siparişi bul (dealer bağ ile)
    /** @var Order|null $order */
    $order = Order::query()
        ->where('order_number', $data['order_number'])
        ->where('dealer_id', $dealer->id)
        ->first();

    if (!$order) {
        return response()->json(['dealer_status'=>false,'message'=>'Order not found for this dealer'], 404);
    }

    $updateItems = (bool)($data['update_items'] ?? true);

    // 4) Güncelle
    DB::transaction(function () use ($order, $statusCode, $updateItems, $data) {
        $order->dealer_status = $statusCode; // DB'ye EN kod yaz
        if (!empty($data['note']) && property_exists($order, 'note')) {
            $order->note = trim((string)$data['note']);
        }
        $order->save();

        if ($updateItems) {
            $order->items()->update(['dealer_status' => $statusCode]);
        }
    });

    // 5) Yanıtta hem kodu hem TR etiketi ver
    return response()->json([
        'dealer_status' => true,
        'message'       => 'Order status updated',
        'data'          => [
            'order_id'      => (int)$order->id,
            'order_number'  => $order->order_number,
            'dealer_id'     => (int)$order->dealer_id,
            'dealer_status' => $order->dealer_status,              // EN kod (enum)
            'status_label'  => $this->dbStatusToTr($order->dealer_status), // TR etiket
            'updated_items' => $updateItems,
        ],
    ]);
}

public function dealerUpdateStatus(Request $request)
{
    // 1) Girdi doğrulama
    $data = $request->validate([
        'email'         => ['required','email'],
        'order_number'  => ['required','string'],
        'dealer_status' => ['required','string'],   // TR/EN gelebilir; birazdan map'leyeceğiz
        'update_items'  => ['sometimes','boolean'],
        'note'          => ['sometimes','nullable','string','max:1000'],
    ]);

    // 2) Dealer kullanıcıyı bul ve yetkisini doğrula
    /** @var \App\Models\User|null $dealer */
    $dealer = \App\Models\User::where('email', $data['email'])->first();
    if (!$dealer) {
        return response()->json(['dealer_status'=>false,'message'=>'Dealer user not found'], 404);
    }
    if (!in_array($dealer->user_type, ['dealer','vendor','administrator'], true)) {
        return response()->json(['dealer_status'=>false,'message'=>'Not authorized as dealer'], 403);
    }

    // 3) Gelen statüyü kanonik EN enum'a çevir ve doğrula
    $statusCode = $this->mapToDbStatusDealer($data['dealer_status']); // 'pending','courier',...
    $allowed = ['pending','courier','delivered','closed','cancelled'];
    // İkinci bir güvenlik katmanı:
    validator(['status'=>$statusCode], [
        'status' => ['required', Rule::in($allowed)],
    ])->validate();

    // 4) Siparişi bul (dealer scope’u ile)
    /** @var \App\Models\Order|null $order */
    $order = \App\Models\Order::query()
        ->where('order_number', $data['order_number'])
        ->where('dealer_id', $dealer->id)
        ->first();

    if (!$order) {
        return response()->json(['dealer_status'=>false,'message'=>'Order not found for this dealer'], 404);
    }

    $updateItems = (bool)($data['update_items'] ?? true);

    // 5) Güncelleme (transaction)
    DB::transaction(function () use ($order, $statusCode, $updateItems, $data) {
        $order->dealer_status = $statusCode;             // ENUM alan: 'courier' vb.
        if (!empty($data['note'])) {
            // Kolonda 'note' yoksa Eloquent ekstra attribute'u yoksayar; sorun olmaz.
            $order->setAttribute('note', trim((string)$data['note']));
        }
        $order->save();                                  // updated_at otomatik

        if ($updateItems) {
            $order->items()->update([
                'dealer_status' => $statusCode,
                'updated_at'    => now(),
            ]);
        }
    });

    // 6) Yanıt
    return response()->json([
        'dealer_status' => true,
        'message'       => 'Order status updated',
        'data'          => [
            'order_id'      => (int)$order->id,
            'order_number'  => $order->order_number,
            'dealer_id'     => (int)$order->dealer_id,
            'dealer_status' => $order->dealer_status,                 // EN enum
            'status_label'  => $this->dbStatusToTrDealer($order->dealer_status), // TR etiket
            'updated_items' => $updateItems,
        ],
    ]);
}


private function mapToDbStatusDealer(string $in): string
{
    $s = mb_strtolower(trim($in));
    $map = [
        // pending
        'pending'   => 'pending',
        'beklemede' => 'pending',
        'hazırlanıyor' => 'pending',

        // courier
        'courier'   => 'courier',
        'kurye'     => 'courier',
        'kuryede'   => 'courier',
        'yolda'     => 'courier',

        // delivered
        'delivered' => 'delivered',
        'teslim'    => 'delivered',
        'teslim edildi' => 'delivered',

        // closed
        'closed'    => 'closed',
        'kapalı'    => 'closed',
        'tamamlandı'=> 'closed',

        // cancelled
        'canceled'  => 'cancelled', // US/UK eşitleme
        'cancelled' => 'cancelled',
        'iptal'     => 'cancelled',
    ];
    return $map[$s] ?? $s; // bilinmiyorsa aynen döndür; Rule::in engeller
}

/** EN enum -> TR label */
private function dbStatusToTrDealer(string $code): string
{
    return [
        'pending'   => 'Beklemede',
        'courier'   => 'Kuryede',
        'delivered' => 'Teslim Edildi',
        'closed'    => 'Kapalı',
        'cancelled' => 'İptal',
    ][$code] ?? $code;
}


/**
 * Girdi (TR/EN karışık) -> DB kanonik EN kod (ENUM)
 * Hedef enum: pending | courier | delivered | closed | cancelled
 */
private function mapToDbStatus(string $raw): string
{
    $t = mb_strtolower(trim($raw), 'UTF-8');

    // --- Türkçe → EN (anahtar kelime içeriyorsa) ---
    if (str_contains($t, 'hazır') || str_contains($t, 'bekle'))  return 'pending';
      if (str_contains($t, 'hakuryezır') || str_contains($t, 'kurye'))  return 'courier';
 
    if (str_contains($t, 'teslim'))                               return 'delivered';
 
  
    if (str_contains($t, 'iptal'))                                return 'cancelled';

    // --- İngilizce varyasyonları normalize et ---
    $map = [
        // pending
        'pending'     => 'pending',
        'prepare'     => 'pending',
        'preparing'   => 'pending',
        'waiting'     => 'pending',
        'awaiting'    => 'pending',

        // courier (eski 'confirmed' / 'away' birleşti)
        'courier'     => 'courier',
        'confirmed'   => 'courier',
        'confirm'     => 'courier',
        'approved'    => 'courier',
        'accepted'    => 'courier',
        'shipped'     => 'courier',
        'in_transit'  => 'courier',
        'on_the_way'  => 'courier',
        'transit'     => 'courier',
        'out_for_delivery' => 'courier',
        'dispatch'    => 'courier',
        'dispatched'  => 'courier',
        'away'        => 'courier',

        // delivered
        'delivered'   => 'delivered',
        'complete'    => 'delivered',
        'completed'   => 'delivered',

        // closed
        'closed'      => 'closed',
        'done'        => 'closed',
        'finished'    => 'closed',
        'archived'    => 'closed',
        'resolved'    => 'closed',

        // cancelled
        'cancel'      => 'cancelled',
        'canceled'    => 'cancelled',
        'cancelled'   => 'cancelled',
        'void'        => 'cancelled',
    ];

    return $map[$t] ?? 'pending'; // tanınmazsa güvenli varsayılan
}

/**
 * DB EN kod → TR label (UI)
 * pending | courier | delivered | closed | cancelled
 */
private function dbStatusToTr(string $code): string
{
    return match ($code) {
        'pending'   => 'Hazırlanıyor',
        'courier'   => 'Sevk edildi',   // ya da "Kuryede"
        'delivered' => 'Teslim edildi',
        'closed'    => 'Kapatıldı',
        'cancelled' => 'İptal',
        default     => $code,
    };
}

    /**
     * Serbest yazılmış statüyü standart hale getirir.
     */
    private function normalizeStatus(string $raw): string
    {
        $t = mb_strtolower(trim($raw), 'UTF-8');

        // eşanlamlı / yazım varyasyonları
        $map = [
            'hazır' => 'hazırlanıyor',
            'hazirlaniyor' => 'hazırlanıyor',
            'hazırlanıyor' => 'hazırlanıyor',

            'sevk' => 'sevk edildi',
            'sevke_dildi' => 'sevk edildi',
            'sevk edildi' => 'sevk edildi',

            'yolda' => 'yolda',

            'teslim' => 'teslim edildi',
            'teslim edil' => 'teslim edildi',
            'teslim edildi' => 'teslim edildi',
            'delivered' => 'teslim edildi',

            'iptal' => 'iptal',
            'cancel' => 'iptal',
            'cancelled' => 'iptal',
        ];

        // doğrudan eşleşirse
        if (isset($map[$t])) {
            return $map[$t];
        }

        // içeriyorsa
        if (str_contains($t, 'hazır')) return 'hazırlanıyor';
        if (str_contains($t, 'sevk'))  return 'sevk edildi';
        if (str_contains($t, 'yol'))   return 'yolda';
        if (str_contains($t, 'teslim') || str_contains($t, 'deliver')) return 'teslim edildi';
        if (str_contains($t, 'iptal')  || str_contains($t, 'cancel'))  return 'iptal';

        // tanınmıyorsa olduğu gibi döndür
        return $raw;
    }

    /**
     * TEDARİKÇİ (SUPPLIER) sipariş listesi
     * GET /api/orders/supplier?dealer_id=&status=&q=&per_page=20
     * - Sadece bu tedarikçiye atanmış kalemleri olan siparişler
     * - İstenirse bayi (dealer_id) filtresi
     */
    public function supplier3(Request $request)
    {
        $auth = $request->user();
        // Sadece supplier veya admin erişsin
        abort_unless(in_array($auth->user_type, ['supplier','administrator']), 403);

        $dealerId = $request->query('dealer_id'); // opsiyonel
        $status   = $request->query('status');
        $q        = trim((string)$request->query('q', ''));
        $perPage  = (int)$request->query('per_page', 20);

        $rows = Order::query()
            // Bu tedarikçiye ait en az bir kalemi olsun
            ->whereHas('items', fn($iq)=>$iq->where('seller_id', $auth->id))
            // Opsiyonel bayi filtresi
            ->when($dealerId, fn($qq,$d)=>$qq->where('dealer_id', (int)$d))
            ->when($status,   fn($qq,$s)=>$qq->where('status',$s))
            ->when($q !== '', function($qq) use ($q) {
                $qq->where('order_number','like',"%$q%")
                   ->orWhereHas('buyer', fn($w)=>$w->where('name','like',"%$q%"));
            })
            ->with([
                'dealer:id,name',       // bayinin adı
                'buyer:id,name',        // alıcı adı
                // sadece bu tedarikçiye ait kalemleri getir
                'items' => fn($iq)=>$iq->where('seller_id', $auth->id)->with('productVariant')
            ])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($rows);
    }
    
   public function handoffToCouriersyavuz(Request $request, DeliveryOrderImporter $importer)
{
    $data = $request->validate([
        'order_id'     => ['sometimes','integer'],
        'order_number' => ['sometimes','string'],
        'client_id'             => ['sometimes','integer'],
        'payment_collect_from'  => ['sometimes', Rule::in(['on_pickup','on_delivery'])],
        'vehicle_id'            => ['sometimes','integer'],
        'auto_assign'           => ['sometimes','boolean'],
    ]);

    // Siparişi user ilişkisiyle birlikte çek (sadece gereken kolonlar)
    $q = Order::query()->with([
        'user:id,name,country_id,city_id',
        // istersen diğerleri:
        // 'buyer:id,name,country_id,city_id',
        // 'dealer:id,name,country_id,city_id',
        'items.productVariant.product',
    ]);

    $src = !empty($data['order_id'])
        ? $q->find($data['order_id'])
        : (!empty($data['order_number'])
            ? $q->where('order_number', $data['order_number'])->first()
            : null);

    if (!$src) {
        return response()->json(['success'=>false,'message'=>'Sipariş bulunamadı'], 404);
    }

      if ($src->delivery_status == 1) {
        return response()->json([
            'success' => false,
            'message' => 'Bu sipariş zaten kuryeye aktarılmış.'
        ], 400);
    }

    // client_id boşsa login olanı ata (opsiyonel)
    if (empty($data['client_id']) && $request->user()) {
        $data['client_id'] = (int) $request->user()->id;
    }

    // ✅ orders.user_id → users.country_id / city_id
    $orderOwner = $src->user; // belongsTo
    $data['country_id'] = optional($orderOwner)->country_id ?? null;
    $data['city_id']    = optional($orderOwner)->city_id    ?? null;

    // upsert
    $delivery = $importer->importByOrder($src, $data);

    //  $src->update(['delivery_status' => 1]);

          $src->update([
        'delivery_status' => 1,
        'dealer_status'   => 'courier',
        'dealer_status_updated_at' => now(),
    ]);




    return response()->json([
        'success' => true,
        'message' => 'Sipariş kuryelere aktarıldı.',
        'data'    => [
            'delivery_order_id' => $delivery->id,
            'parent_order_id'   => $delivery->parent_order_id,
            'order_number'      => $src->order_number,
            'status'            => $delivery->status,
        ],
    ]);
}
public function handoffToCouriers(Request $request, DeliveryOrderImporter $importer)
{
    $data = $request->validate([
        'order_id'             => ['sometimes', 'integer'],
        'order_number'         => ['sometimes', 'string'],
        'client_id'            => ['sometimes', 'integer'],
        'country_id'           => ['nullable','integer'],
        'city_id'              => ['nullable','integer'],
        'payment_collect_from' => ['sometimes', Rule::in(['on_pickup', 'on_delivery'])],
        'vehicle_id'           => ['sometimes', 'integer'],
        'auto_assign'          => ['sometimes', 'boolean'],

    ]);

    if (empty($data['order_id']) && empty($data['order_number'])) {
        return response()->json([
            'success' => false,
            'message' => 'order_id veya order_number zorunludur.',
        ], 422);
    }

    $q = Order::query()->with([
        'user:id,name,country_id,city_id',
        'items.productVariant.product',
    ]);

    $src = !empty($data['order_id'])
        ? $q->find($data['order_id'])
        : $q->where('order_number', $data['order_number'])->first();

    if (!$src) {
        return response()->json([
            'success' => false,
            'message' => 'Sipariş bulunamadı.',
        ], 404);
    }

    // Zaten kuryelere aktarılmışsa
    if ($src->delivery_status == 1) {
        return response()->json([
            'success' => true,
            'message' => 'Bu sipariş zaten kuryelere aktarılmış.',
            'data'    => [
                'delivery_order_id' => optional($src->deliveryOrder)->id ?? null,
                'parent_order_id'   => null,
                'order_number'      => $src->order_number,
                'status'            => 'already_handed_off',
            ],
        ]);
    }

    // ❗ Burada iş kuralın: sadece onaylandı ise kurye tarafına gidebilir
    if ($src->status !== 'confirmed') {
        return response()->json([
            'success' => false,
            'message' => 'Bu sipariş henüz onaylanmadığı için kuryeye aktarılamaz.',
        ], 400);
    }

    if (empty($data['client_id']) && $request->user()) {
        $data['client_id'] = (int) $request->user()->id;
    }

  /*  $orderOwner         = $src->user;
    $data['country_id'] = optional($orderOwner)->country_id;
    $data['city_id']    = optional($orderOwner)->city_id;
*/
    $delivery = $importer->importByOrder($src, $data);

    $src->update([
        'delivery_status'          => 1,
        'dealer_status'            => 'courier',
        'dealer_status_updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Sipariş kuryelere aktarıldı.',
        'data'    => [
            'delivery_order_id' => $delivery->id,
            'parent_order_id'   => $delivery->parent_order_id,
            'order_number'      => $src->order_number,
            'status'            => $delivery->status,
        ],
    ]);
}

public function handoffToCouriers222(Request $request, DeliveryOrderImporter $importer)
{
    $data = $request->validate([
        'order_id'     => ['sometimes','integer'],
        'order_number' => ['sometimes','string'],
        // opsiyonel override'lar:
        'client_id'             => ['sometimes','integer'],
             'country_id'            => ['sometimes','integer'],
        'city_id'               => ['sometimes','integer'],
        'payment_collect_from'  => ['sometimes', Rule::in(['on_pickup','on_delivery'])],
        'vehicle_id'            => ['sometimes','integer'],
        'auto_assign'           => ['sometimes','boolean'],
    ]);

    // Kaynak siparişi bul
    $q = Order::query()->with(['items.productVariant.product','buyer','dealer']);
    if (!empty($data['order_id'])) {
        $src = $q->find($data['order_id']);
    } elseif (!empty($data['order_number'])) {
        $src = $q->where('order_number', $data['order_number'])->first();
    } else {
        return response()->json(['success'=>false,'message'=>'order_id veya order_number gerekli'], 422);
    }

    if (!$src) {
        return response()->json(['success'=>false,'message'=>'Sipariş bulunamadı'], 404);
    }


    // ⬇️ client_id yoksa login olan kullanıcıyı client olarak ata (isteğe bağlı)
    if (empty($data['client_id']) && $request->user()) {
        $data['client_id'] = (int) $request->user()->id;
    }

    // ⬇️ country_id / city_id otomatik (öncelik: order.user -> buyer -> login user)
    $data['country_id'] = $src->user->country_id
      
        ?? $request->user()?->country_id
        ?? null;

    $data['city_id'] = $src->user->city_id
        ?? $src->buyer->city_id
        ?? $request->user()?->city_id
        ?? null;





    // delivery_orders’a upsert etx
    $delivery = $importer->importByOrder($src, $data);

    return response()->json([
        'success' => true,
        'message' => 'Sipariş kuryelere aktarıldı.',
        'data'    => [
            'delivery_order_id' => $delivery->id,
            'parent_order_id'   => $delivery->parent_order_id,
            'order_number'      => $src->order_number,
            'status'            => $delivery->status,
        ],
    ]);
}

public function vendor(Request $request)
{
    $request->validate([
        'email' => 'required|email',
    ]);

    $user = \App\Models\User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Kullanıcı bulunamadı.',
        ], 404);
    }

    $hasBuyerId           = Schema::hasColumn('orders', 'buyer_id');
    $hasResellerId        = Schema::hasColumn('orders', 'reseller_id');
    $hasItemDealerStatus  = Schema::hasColumn('order_items', 'dealer_status');

    $orders = \App\Models\Order::query()
        ->where(function ($q) use ($user, $hasBuyerId, $hasResellerId) {
            // Farklı şema/akışlar için tüm olası eşleşmeleri kapsa.
            $q->where('user_id', $user->id);

            if ($hasBuyerId) {
                $q->orWhere('buyer_id', $user->id);
            }

            if ($hasResellerId) {
                $q->orWhere('reseller_id', $user->id);
            }
        })
        ->orWhereHas('items', function ($q) use ($user) {
            // Vendor/supplier kullanıcılar için item bazlı sahiplik.
            $q->where('seller_id', $user->id);
        })
        ->with([
            'items' => function ($q) use ($hasItemDealerStatus) {
                $columns = [
                    'id',
                    'order_id',
                    'product_variant_id',
                    'seller_id',
                    'quantity',
                    'unit_price',
                    'total_price',
                    'status',
                ];

                if ($hasItemDealerStatus) {
                    $columns[] = 'dealer_status';
                }

                $q->select($columns)->with([
                    'productVariant:id,name,product_id',
                    'productVariant.product:id,name',
                    'seller:id,name',
                ]);
            },
        ])
        ->orderByDesc('id')
        ->get();

    // item içine product_name ekle
    $orders->each(function ($order) {
        $order->items->each(function ($item) {
            $item->product_name = optional($item->productVariant->product)->name;
        });
    });

    return response()->json([
        'success' => true,
        'user_id' => $user->id,
        'user_type' => $user->user_type,
        'order_count' => $orders->count(),
        'orders' => $orders,
    ]);
    
}

// app/Http/Controllers/Api/OrderController.php

public function siparisduzenle(Request $request)
{
    $data = $request->validate([
        'order_number' => ['required', 'string'],
        'email'        => ['sometimes','email'],       // isteğe bağlı: kim düzenliyor kontrolü

        'items'                        => ['required','array','min:1'],
        'items.*.product_variant_id'   => ['required','integer','exists:product_variants,id'],
        'items.*.quantity'             => ['required','numeric','min:0.01'],
        'items.*.unit_price'           => ['required','numeric','min:0'],
        'items.*.total_price'          => ['required','numeric','min:0'],

        // Sağ taraftaki özet kutusundan gelen rakamlar
        'subtotal'     => ['sometimes','numeric','min:0'],
        'vat'          => ['sometimes','numeric','min:0'],
        'grand_total'  => ['sometimes','numeric','min:0'],
        'total_amount' => ['sometimes','numeric','min:0'], // eski kolon adı için
    ]);

    // --- 1) İsteğe bağlı email ile yetki kontrolü (dealer / buyer) ---
    $user = null;
    if (!empty($data['email'])) {
        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı bulunamadı.',
            ], 404);
        }
    }

    // --- 2) Siparişi bul ---
    $orderQuery = Order::query()->where('order_number', $data['order_number']);

    // Eğer mail bayi ise sadece kendi siparişini düzenlesin
    if ($user && in_array($user->user_type, ['dealer','vendor'], true)) {
        $orderQuery->where('dealer_id', $user->id);
    }
    // Eğer normal müşteri ise kendi siparişini düzenlesin
    if ($user && in_array($user->user_type, ['buyer','user'], true)) {
        $orderQuery->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
              ->orWhere('buyer_id', $user->id);
        });
    }

    /** @var \App\Models\Order|null $order */
    $order = $orderQuery->first();
    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Sipariş bulunamadı.',
        ], 404);
    }

    // Teslim edilmiş / kapatılmış siparişi oynatma
    if (in_array($order->status, ['delivered','closed','cancelled'], true)) {
        return response()->json([
            'success' => false,
            'message' => 'Bu statüdeki sipariş düzenlenemez.',
        ], 422);
    }

    // --- 3) Satırları komple sil ve yeni listeyi ekle ---
    DB::transaction(function () use (&$order, $data) {

        // 3.1 Eski kalemleri sil
        $order->items()->delete();

        // 3.2 Yeni kalemleri ekle
        foreach ($data['items'] as $row) {
            $order->items()->create([
                'product_variant_id' => $row['product_variant_id'],
                'seller_id'          => null, // istersen burada dealer id vs set edebilirsin
                'quantity'           => $row['quantity'],
                'unit_price'         => $row['unit_price'],
                'total_price'        => $row['total_price'],
                'status'             => $order->status ?? 'pending',
            ]);
        }

        // 3.3 Toplamları güncelle
        $subtotal = $data['subtotal'] ?? $order->items()->sum('total_price');
        $vat      = $data['vat']      ?? 0;
        $grand    = $data['grand_total']
            ?? $data['total_amount']
            ?? round($subtotal + $vat, 2);

        // Orders tablosunda hangi kolon varsa ona yaz:
        if (\Schema::hasColumn('orders','subtotal')) {
            $order->subtotal = $subtotal;
        }
        if (\Schema::hasColumn('orders','vat')) {
            $order->vat = $vat;
        }

        // Eski yapıyı da destekle (total_amount)
        if (\Schema::hasColumn('orders','total_amount')) {
            $order->total_amount = $grand;
        }
        if (\Schema::hasColumn('orders','grand_total')) {
            $order->grand_total = $grand;
        }

        $order->save();
    });

    $order->load(['items.productVariant.product','items.seller']);

    return response()->json([
        'success' => true,
        'message' => 'Sipariş kalemleri güncellendi.',
        'data'    => $order,
    ]);
}

 public function dealerPending(Request $request)
{
    $request->validate([
        'email' => 'required|email',
    ]);

    $dealer = \App\Models\User::where('email', $request->email)->first();

    if (!$dealer) {
        return response()->json([
            'success' => false,
            'message' => 'Kullanıcı (bayi) bulunamadı.',
        ], 404);
    }

    $orders = \App\Models\Order::query()
        ->where('orders.dealer_id', $dealer->id)     // bayiye ait
        ->where('orders.status', 'pending')          // ✅ SADECE PENDING
        ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.user_id')
        ->select([
            'orders.id',
            'orders.order_number',
            'orders.user_id',
            'orders.dealer_id',
            'orders.status',
            'orders.dealer_status',
            'orders.supplier_status',
            'orders.payment_status',
            'orders.delivery_status',
            'orders.total_amount',
            'orders.created_at',

            DB::raw('buyer.name        as buyer_name'),
            DB::raw('buyer.city        as buyer_city'),
            DB::raw('buyer.district    as buyer_district'),
            DB::raw('buyer.user_type   as buyer_type'),
        ])
        ->with([
            'items' => function ($q) {
                $q->select(
                        'id',
                        'order_id',
                        'product_variant_id',
                        'seller_id',
                        'quantity',
                        'unit_price',
                        'total_price',
                        'status',
                        'dealer_status'
                    )
                    ->with([
                        'productVariant:id,name,product_id',
                        'seller:id,name',
                    ]);
            },
        ])
        ->orderByDesc('orders.id')
        ->get();

    return response()->json([
        'success' => true,
        'orders' => $orders,
    ]);
}


  public function byPartnerOrderId($partnerOrderId)
    {
        // 1️⃣ Orders tablosundan partner_order_id ile sipariş bul
        $order = Order::where('partner_order_id', $partnerOrderId)->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Sipariş bulunamadı'
            ], 404);
        }

        // 2️⃣ order_number al
        $orderNumber = $order->order_number;
       $orderNumberPartner = $order->partner_order_id;
        // 3️⃣ delivery_orders tablosunda customer_fcm_token = order_number filtrele
        $deliveryOrder = DeliveryOrder::where('customer_fcm_token', $orderNumber)->first();
       $orderStatus = $deliveryOrder->status;
        if (!$deliveryOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Sipariş kaydı bulunamadı',
                'order_number' => $orderNumber
                
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order_status' => $orderStatus,
            'order_number' => $orderNumberPartner
            ]);
    }
}
