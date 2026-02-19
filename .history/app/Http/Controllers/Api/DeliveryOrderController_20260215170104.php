<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Order;
use App\Http\Resources\DeliveryManOrderResource;
use App\Http\Resources\DeliveryManOrderDetailResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\UserResource;
use App\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\DeliveryOrderImporter;



class DeliveryOrderController extends Controller
{
    /**
     * GET /api/delivery-orders
     * Query: page, per_page, client_id, status, date_from, date_to, q
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 20);
        $clientId = $request->input('client_id');
        $status   = $request->input('status');
        $from     = $request->input('date_from');
        $to       = $request->input('date_to');
        $q        = $request->input('q');

        $query = DeliveryOrder::query()
            ->with(['delivery_man']) // accessor için N+1 olmasın
            ->client($clientId)
            ->status($status)
            ->dateBetween($from, $to)
            ->search($q)
            ->orderByDesc('date')
            ->orderByDesc('id');

        $data = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/delivery-orders/{id}
     */
    public function show(DeliveryOrder $deliveryOrder)
    {
        return response()->json([
            'success' => true,
            'data'    => $deliveryOrder,
        ]);
    }

    /**
     * POST /api/delivery-orders
     * Body: tüm fillable alanlar (JSON alanları dizi/obje verilebilir)
     * Foto: pick_photo / delivery_photo / order_photo   (multipart dosya veya *_url string)
     */
    public function store(Request $request)
    {
        $payload = $this->validatePayload($request);

        // reason dizi gelirse JSON’a çevir
        if (isset($payload['reason']) && is_array($payload['reason'])) {
            $payload['reason'] = json_encode($payload['reason'], JSON_UNESCAPED_UNICODE);
        }

        $row = null;
        DB::transaction(function () use (&$row, $payload, $request) {
            // 1) Satırı oluştur
            $row = DeliveryOrder::create($payload);

            // 2) Fotoğraflar (dosya veya URL)
            $changed = false;
            foreach (['pick_photo','delivery_photo','order_photo'] as $fld) {
                $stored = $this->saveUploadedImage($request, $fld, (int)$row->id);
                if ($stored) {
                    $row->{$fld} = $stored; // local path veya URL
                    $changed = true;
                }
            }
            if ($changed) {
                $row->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Delivery order oluşturuldu.',
            'data'    => $row,
        ], 201);
    }

    /**
     * PUT/PATCH /api/delivery-orders/{id}
     * Body: tüm alanlar; foto için multipart dosya veya *_url
     */
    public function update(Request $request, DeliveryOrder $deliveryOrder)
    {
        $payload = $this->validatePayload($request, $deliveryOrder->id);

        if (isset($payload['reason']) && is_array($payload['reason'])) {
            $payload['reason'] = json_encode($payload['reason'], JSON_UNESCAPED_UNICODE);
        }

        DB::transaction(function () use (&$deliveryOrder, $payload, $request) {
            // 1) Alanları güncelle
            $deliveryOrder->update($payload);

            // 2) Fotoğraflar
            $changed = false;
            foreach (['pick_photo','delivery_photo','order_photo'] as $fld) {
                $new = $this->saveUploadedImage($request, $fld, (int)$deliveryOrder->id);
                if ($new) {
                    // eski local dosyayı temizle (URL ise silme)
                    $this->deleteIfLocal($deliveryOrder->{$fld});
                    $deliveryOrder->{$fld} = $new;
                    $changed = true;
                }
            }
            if ($changed) {
                $deliveryOrder->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Delivery order güncellendi.',
            'data'    => $deliveryOrder->fresh(),
        ]);
    }

    /**
     * Sipariş durumu ve alan güncelleme + foto yükleme
     * POST /api/delivery-orders/{id}/update-delivery
     */
    public function updateDelivery(Request $request, $id)
    {
        // Kimlik doğrulama (Sanctum)
        $actor = auth('sanctum')->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Bu statülerde kurye ataması yapmak mantıklı
        $assignStatuses = ['active','courier_picked_up','courier_arrived','courier_departed'];

        try {
            DB::beginTransaction();

            /** @var \App\Models\DeliveryOrder $order */
            $order = DeliveryOrder::where('id', (int)$id)->lockForUpdate()->firstOrFail();

            $old_status = $order->status;

            // İstekten gelecek "manuel" delivery_man_id varsa onu al (örn. admin ataması)
            $requestedDmId = $request->input('delivery_man_id');

            // 1) Önce diğer alanları doldur (delivery_man_id hariç, onu birazdan kural ile yazacağız)
            $fillData = $request->except(['delivery_man_id']);
            $order->fill($fillData);

            // 2) Kurye atama kararı
            $shouldAssignByStatus = $request->filled('status') && in_array($request->input('status'), $assignStatuses, true);
            $shouldAssignToActor  = $request->boolean('assign_to_me', false); // mobil "assign_to_me=1" gelebilir

            if (!is_null($requestedDmId)) {
                // İstek delivery_man_id gönderiyorsa onu yaz (admin/use-case)
                $order->delivery_man_id = (int) $requestedDmId;
            } else {
                // Aksi halde; atama yoksa ve (status atamayı gerektiriyorsa veya assign_to_me=1 geldiyse) kurye = actor
                if (is_null($order->delivery_man_id) && ($shouldAssignByStatus || $shouldAssignToActor)) {
                    $order->delivery_man_id       = (int) $actor->id;
                    $order->deliveryman_fcm_token = $actor->fcm_token ?? $order->deliveryman_fcm_token;
                }
            }

            // 3) Fotoğraflar (dosya veya URL)
            $changedPhotos = false;
            foreach (['pick_photo','delivery_photo','order_photo'] as $fld) {
                $new = $this->saveUploadedImage($request, $fld, (int)$order->id);
                if ($new) {
                    $this->deleteIfLocal($order->{$fld});
                    $order->{$fld} = $new;
                    $changedPhotos = true;
                }
            }

            // 4) Kaydet + dealer_status senkronu
            $order->save();

            \App\Models\Order::query()
                ->when($order->parent_order_id, fn($q) => $q->where('id', $order->parent_order_id))
                ->when(!$order->parent_order_id && $order->customer_fcm_token, fn($q) => $q->where('order_number', $order->customer_fcm_token))
                ->update([
                    'dealer_status' => $this->mapDeliveryToDealer($order->status),
                ]);

            // 5) Ödeme ve cüzdan
            $payment = Payment::where('order_id', $order->id)->first();
            if ($payment && $payment->payment_status === 'paid' && $order->status === 'completed') {
                $this->walletTransactionCompleted($order->id);
            }
            if ($order->status === 'cancelled') {
                // $this->walletTransactionCancelled($order->id);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::info('updateDelivery error - ' . $e->getMessage());
            return $this->json_custom_response(['message' => 'Update failed', 'error' => $e->getMessage()], 500);
        }

        $message = __('message.update_form', ['form' => __('message.order')]);

        if (in_array($request->input('status'), ['delayed', 'cancelled', 'failed'], true)) {
            $history_data = [
                'history_type' => $request->input('status'),
                'order_id'     => $id,
                'order'        => $order,
            ];
            // saveOrderHistory($history_data);
        }

        if (in_array($request->input('status'), ['courier_picked_up', 'courier_arrived', 'completed', 'courier_departed'], true)) {
            $history_data = [
                'history_type' => $request->input('status'),
                'order_id'     => $id,
                'order'        => $order,
            ];
            // saveOrderHistory($history_data);
        }

        if ($request->is('api/*')) {
            return $this->json_message_response($message);
        }
    }

    private function mapDeliveryToDealer(?string $s): string
    {
        $s = strtolower((string)$s);
        // delivery_orders.status → orders.dealer_status
        return match ($s) {
            'pending'         => 'pending',
            'courier'         => 'courier',   // Teslim Aldı / Kuryede
            'delivered'       => 'delivered',
            'closed'          => 'closed',
            'cancelled','canceled' => 'cancelled',
            default           => 'pending',
        };
    }

    /**
     * (Opsiyonel) Eski endpoint – bıraktım; upload da destekliyor.
     */
    public function updateDeliverya(Request $request, $id)
    {
        $order = DeliveryOrder::findOrFail($id);
        $old_status = $order->status;

        try {
            DB::beginTransaction();

            $order->fill($request->all())->update();

            // Fotoğraflar
            $changed = false;
            foreach (['pick_photo','delivery_photo','order_photo'] as $fld) {
                $new = $this->saveUploadedImage($request, $fld, (int)$order->id);
                if ($new) {
                    $this->deleteIfLocal($order->{$fld});
                    $order->{$fld} = $new;
                    $changed = true;
                }
            }
            if ($changed) {
                $order->save();
            }

            $payment = Payment::where('order_id', $id)->first();

            if ($payment != null && $payment->payment_status == 'paid' && $order->status == 'completed') {
                $this->walletTransactionCompleted($order->id);
            }

            if ($order->status == 'cancelled') {
                // $this->walletTransactionCancelled($order->id);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('error-' . $e);
            return $this->json_custom_response($e);
        }

        $message = __('message.update_form', ['form' => __('message.order')]);

        if (in_array(request('status'), ['delayed', 'cancelled', 'failed'])) {
            $history_data = [
                'history_type' => request('status'),
                'order_id' => $id,
                'order' => $order,
            ];
            // saveOrderHistory($history_data);
        }

        if (in_array(request('status'), ['courier_picked_up', 'courier_arrived', 'completed', 'courier_departed'])) {
            $history_data = [
                'history_type' => request('status'),
                'order_id' => $id,
                'order' => $order,
            ];
            // saveOrderHistory($history_data);
        }

        if ($request->is('api/*')) {
            return $this->json_message_response($message);
        }
    }

    /**
     * DELETE /api/delivery-orders/{id}
     * Soft delete
     */
    public function destroy(DeliveryOrder $deliveryOrder)
    {
        // Varsa local dosyaları sil (URL ise dokunmaz)
        $this->deleteIfLocal($deliveryOrder->pick_photo ?? null);
        $this->deleteIfLocal($deliveryOrder->delivery_photo ?? null);
        $this->deleteIfLocal($deliveryOrder->order_photo ?? null);

        $deliveryOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Delivery order silindi.',
        ]);
    }

    /* --------------------- Helpers --------------------- */

    /**
     * Fotoğraf alanı için; multipart dosya varsa public disk’e kaydeder,
     * *_url string gelmişse direkt onu döner. Hiçbiri yoksa null döner.
     * Dönen string; local ise "pick_photo/.." gibi path, URL ise doğrudan URL olur.
     */
    private function saveUploadedImage(Request $request, string $field, int $orderId): ?string
    {
        // 1) Multipart dosya
        if ($request->hasFile($field)) {
            $file = $request->file($field);
            $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');

            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                abort(422, "Invalid file type for {$field}");
            }

            $name = sprintf(
                '%d_%s_%s.%s',
                $orderId,
                $field,
                now()->format('YmdHis') . '_' . Str::random(6),
                $ext
            );

            // klasör = field adı: pick_photo / delivery_photo / order_photo
            $path = $file->storeAs($field, $name, 'public'); // storage/app/public/{field}/{name}
            return $path; // DB'de relative path olarak tutuyoruz
        }

        // 2) URL/string
        $urlField = $field . '_url';
        if ($request->filled($urlField)) {
            return $request->input($urlField); // DB’de URL sakla
        }

        return null;
    }

    /**
     * Local path ise public diskten siler, URL ise dokunmaz.
     */
    private function deleteIfLocal(?string $path): void
    {
        if (!$path) return;
        if (str_starts_with($path, 'http')) return;

        try {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('deleteIfLocal error: ' . $e->getMessage(), ['path' => $path]);
        }
    }

    private function validatePayload(Request $request, $id = null): array
    {
        return $request->validate([
            'client_id'   => ['nullable','integer'],
            'reseller_id' => ['nullable','integer'],

            'pickup_point'   => ['nullable','array'], // JSON cast → array bekliyoruz
            'delivery_point' => ['nullable','array'],

            'country_id' => ['nullable','integer'],
            'city_id'    => ['nullable','integer'],
            'parcel_type'=> ['nullable','string','max:255'],

            'total_weight'   => ['nullable','numeric'],
            'total_distance' => ['nullable','numeric'],

            'date'             => ['nullable','date'],
            'pickup_datetime'  => ['nullable','date'],
            'delivery_datetime'=> ['nullable','date'],

            'parent_order_id'  => ['nullable','integer'],
            'payment_id'       => ['nullable','integer'],

            // reason metin ama JSON da gelebilir (store/update içinde handle ediyoruz)
            'reason'           => ['nullable'],

            'status' => ['nullable','string','max:255'],
            'payment_collect_from' => ['nullable', Rule::in(['on_pickup','on_delivery'])],

            'delivery_man_id'       => ['nullable','integer'],
            'deliveryman_fcm_token' => ['nullable','string','max:255'],

            'fixed_charges'    => ['nullable','numeric'],
            'weight_charge'    => ['nullable','numeric'],
            'distance_charge'  => ['nullable','numeric'],

            'extra_charges'    => ['nullable','array'], // JSON cast
            'total_amount'     => ['nullable','numeric'],

            'pickup_confirm_by_client'       => ['nullable','boolean'],
            'pickup_confirm_by_delivery_man' => ['nullable','boolean'],

            'total_parcel' => ['nullable','numeric'],
            'vehicle_id'   => ['nullable','integer'],
            'vehicle_data' => ['nullable','array'], // JSON cast
            'auto_assign'  => ['nullable','boolean'],

            'cancelled_delivery_man_ids' => ['nullable','string'], // text

            // FOTOĞRAFLAR — dosya veya URL’yi destekle
            'delivery_photo'     => ['nullable','file','image','mimes:jpg,jpeg,png,webp','max:5120'],
            'order_photo'        => ['nullable','file','image','mimes:jpg,jpeg,png,webp','max:5120'],
            'pick_photo'         => ['nullable','file','image','mimes:jpg,jpeg,png,webp','max:5120'],

            'delivery_photo_url' => ['nullable','string','max:2048'],
            'order_photo_url'    => ['nullable','string','max:2048'],
            'pick_photo_url'     => ['nullable','string','max:2048'],

            'customer_fcm_token' => ['nullable','string','max:255'],
        ]);
    }

    public function getDetail(Request $request)
    {
        // 1) Auth kontrolü (Sanctum)
        $current_user = auth('sanctum')->user();
        if (!$current_user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // 2) ID doğrulama
        $id = (int) $request->id;
        if (!$id) {
            return response()->json(['message' => 'Order id is required'], 422);
        }

        // 3) Sipariş çekme (görünürlük için myOrder scope’u iyi bir güvenlik katmanı)
        $order = DeliveryOrder::query()
            ->withTrashed()
            ->find($id);

        if (!$order) {
            return $this->json_message_response(__('message.not_found_entry',['name' => __('message.order')]), 404);
        }

        // 4) Detay resource
        $order_detail = new DeliveryManOrderDetailResource($order);

        // 5) Geçmiş
        $order_history = $order->orderHistory ?? [];

        // 6) Ödeme
        $payment = Payment::where('order_id', $id)->first();
        $paymentResource = $payment ? new PaymentResource($payment) : null;

        // 7) Bildirimleri okundu işaretle (ILIŞKI METODU!) => data->id JSON yoluna göre filtrele
        $current_user->unreadNotifications()
            ->where('data->id', $id)
            ->update(['read_at' => now()]);

        // 8) İlişkili kullanıcılar
        $client_detail = null;
        if ($order->client_id) {
            $client = User::withTrashed()->find($order->client_id);
            $client_detail = $client ? new UserResource($client) : null;
        }

        $delivery_man_detail = null;
        if ($order->delivery_man_id) {
            $dm = User::withTrashed()->find($order->delivery_man_id);
            $delivery_man_detail = $dm ? new UserResource($dm) : null;
        }

        // 9) Response
        $response = [
            'data' => $order_detail,
            'payment' => $paymentResource,
            'order_history' => $order_history,
            'client_detail' => $client_detail,
            'delivery_man_detail' => $delivery_man_detail,
        ];

        return $this->json_custom_response($response);
    }

    public function getDetail2(Request $request)
    {
        $id = $request->id;
        $order = DeliveryOrder::where('id',$id)->withTrashed()->first();

        if($order == null){
            return $this->json_message_response(__('message.not_found_entry',['name' => __('message.order')]),400);
        }
        $order_detail = new DeliveryManOrderDetailResource($order);

        $order_history = optional($order)->orderHistory;

        $payment = Payment::where('order_id',$id)->first();
        if( $payment != null ) {
            $payment = new PaymentResource($payment);
        }
        $current_user = auth()->user();
        if(count($current_user->unreadNotifications) > 0 ) {
            $current_user->unreadNotifications->where('data.id',$id)->markAsRead();
        }

        if($order->client_id != null){
            $client_detail =  User::where('id', $order->client_id)->withTrashed()->first();
        }
        if($order->delivery_man_id != null){
            $delivery_man_detail = User::where('id', $order->delivery_man_id)->withTrashed()->first();
        }
        $response = [
            'data' => $order_detail,
            'payment' => $payment ?? null,
            'order_history' => $order_history,
            'client_detail' => isset($client_detail) ? new UserResource($client_detail) : null ,
            'delivery_man_detail' => isset($delivery_man_detail) ? new UserResource($delivery_man_detail) : null
        ];

        return $this->json_custom_response($response);
    }

    public function getList(Request $request)
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            Log::warning('[order-list] unauthenticated');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::debug('[order-list] getList çağrıldı', [
            'query' => $request->query(),
            'auth'  => optional($user)->id,
        ]);

        // --- HAVUZ MODU KARARI (rol kontrolü YOK) ---
        $statusParam = $request->get('status');
        $poolMode = in_array($statusParam, ['create'], true) || $request->boolean('pool');

        // Havuz modunda myOrder KULLANMAYIN; normal modda mevcut davranış kalsın
        $orders = $poolMode ? DeliveryOrder::query()
                            : DeliveryOrder::myOrder();

        // Debug helper (opsiyonel)
        $step = 0;
        $dbg = function($label, $builder) use (&$step) {
            try {
                $c = (clone $builder)->count();
                Log::debug(sprintf('[order-list][%02d] %s', ++$step, $label), ['count' => $c]);
            } catch (\Throwable $e) {
                Log::debug(sprintf('[order-list][%02d] %s', ++$step, $label), ['count' => 'n/a', 'err' => $e->getMessage()]);
            }
        };
        $dbg($poolMode ? 'start POOL' : 'start myOrder()', $orders);

        // Admin ise şehir filtresi (varsa city_id)
        if ($user->user_type === 'admin' && $user->city_id) {
            $orders->where('city_id', $user->city_id);
            $dbg('after admin city filter', $orders);
        }

        // ---- STATUS FİLTRESİ ----
        if ($poolMode) {
            // Havuz: create/active default; param varsa uygula (trashed yok)
            if ($request->filled('status') && $statusParam !== 'trashed') {
                $orders->where('status', $statusParam);
                $dbg("POOL: status={$statusParam}", $orders);
            } else {
                $orders->whereIn('status', ['create','active']);
                $dbg('POOL: status IN (create,active)', $orders);
            }
        } else {
            if ($request->filled('status')) {
                if ($request->status === 'trashed') {
                    $orders->withTrashed();
                    $dbg('after withTrashed()', $orders);
                } else {
                    $orders->where('status', $request->status);
                    $dbg("after status={$request->status}", $orders);
                }
            }
        }

        // ---- DİĞER FİLTRELER (her iki modda da geçerli) ----
        if ($request->filled('client_id')) {
            $orders->where('client_id', (int) $request->client_id);
            $dbg("after client_id={$request->client_id}", $orders);
        }

        if ($request->filled('delivery_man_id')) {
            $orders->where('delivery_man_id', (int) $request->delivery_man_id);
            $dbg("after delivery_man_id={$request->delivery_man_id}", $orders);
        }

        if ($request->filled('country_id')) {
            $orders->where('country_id', (int) $request->country_id);
            $dbg("after country_id={$request->country_id}", $orders);
        }

        if ($request->filled('city_id')) {
            $orders->where('city_id', (int) $request->city_id);
            $dbg("after city_id={$request->city_id}", $orders);
        }

        if ($request->filled('exclude_status')) {
            $statuses = array_filter(array_map('trim', explode(',', $request->exclude_status)));
            if ($statuses) {
                $orders->whereNotIn('status', $statuses);
                $dbg('after exclude_status', $orders);
            }
        }

        if ($request->filled('statuses')) {
            $statuses = array_filter(array_map('trim', explode(',', $request->statuses)));
            if ($statuses) {
                $orders->whereIn('status', $statuses);
                $dbg('after statuses IN', $orders);
            }
        }

        if ($request->filled('today_date')) {
            $orders->whereDate('date', $request->today_date);
            $dbg("after today_date={$request->today_date}", $orders);
        }

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $orders->whereBetween('date', [$request->from_date, $request->to_date]);
            $dbg("after between {$request->from_date}..{$request->to_date}", $orders);
        }

        // ---- HAVUZ KOŞULLARI (rol kontrolü yok) ----
        if ($poolMode) {
            // Kimseye atanmamış işler
            $orders->whereNull('delivery_man_id');

            // Bu kullanıcı daha önce reddetmediyse (cancelled_delivery_man_ids JSON alanında user id yoksa)
            $orders->where(function ($q) use ($user) {
                $q->whereNull('cancelled_delivery_man_ids')
                  ->orWhereRaw(
                      'JSON_CONTAINS(COALESCE(cancelled_delivery_man_ids, JSON_ARRAY()), JSON_QUOTE(CAST(? AS CHAR))) = 0',
                      [$user->id]
                  );
            });

            $dbg('POOL: whereNull(delivery_man_id) & not-cancelled-by-me', $orders);
        }

        // ---- SAYFALAMA & ÇIKIŞ ----
        $perPage = (int) ($request->per_page ?? config('constant.PER_PAGE_LIMIT', 15));
        if ($request->filled('per_page') && (int)$request->per_page === -1) {
            try {
                $perPage = (clone $orders)->count();
            } catch (\Throwable $e) {
                $perPage = 100000; // worst-case fallback
            }
        }

        $paginator = $orders->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        Log::debug('[order-list] final', [
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'total'     => $paginator->total(),
        ]);

        $items = DeliveryManOrderResource::collection($paginator);

        $all_unread_count = $user?->unreadNotifications?->count() ?? 0;
        $wallet_data      = Wallet::where('user_id', $user->id)->first();

        return response()->json([
            'pagination'       => $this->json_pagination_response($paginator),
            'data'             => $items,
            'all_unread_count' => $all_unread_count,
            'wallet_data'      => $wallet_data ?? null,
        ])->header('X-User-Id', $user->id);
    }

    public function getLista(Request $request)
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            Log::warning('[order-list] unauthenticated');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        Log::debug('[order-list] getList çağrıldı', [
            'query' => $request->query(),
            'auth'  => optional(auth('sanctum')->user())->id,
        ]);

        $step = 0;
        $dbg = function($label, $builder) use (&$step) {
            $c = (clone $builder)->count();
            Log::debug(sprintf('[order-list][%02d] %s', ++$step, $label), ['count' => $c]);
        };

        $orders = DeliveryOrder::myOrder();
        $dbg('after myOrder()', $orders);

        if ($user->user_type === 'admin' && $user->city_id) {
            $orders->where('city_id', $user->city_id);
            $dbg('after admin city filter', $orders);
        }

        if ($request->filled('status')) {
            if ($request->status === 'trashed') {
                $orders->withTrashed();
                $dbg('after withTrashed()', $orders);
            } else {
                $orders->where('status', $request->status);
                $dbg("after status={$request->status}", $orders);
            }
        }

        if ($request->filled('client_id')) {
            $orders->where('client_id', (int) $request->client_id);
            $dbg("after client_id={$request->client_id}", $orders);
        }

        if ($request->filled('delivery_man_id')) {
            $orders->where('delivery_man_id', (int) $request->delivery_man_id);
            $dbg("after delivery_man_id={$request->delivery_man_id}", $orders);
        }

        if ($request->filled('country_id')) {
            $orders->where('country_id', (int) $request->country_id);
            $dbg("after country_id={$request->country_id}", $orders);
        }

        if ($request->filled('city_id')) {
            $orders->where('city_id', (int) $request->city_id);
            $dbg("after city_id={$request->city_id}", $orders);
        }

        if ($request->filled('exclude_status')) {
            $statuses = array_filter(array_map('trim', explode(',', $request->exclude_status)));
            if ($statuses) {
                $orders->whereNotIn('status', $statuses);
                $dbg('after exclude_status', $orders);
            }
        }

        if ($request->filled('statuses')) {
            $statuses = array_filter(array_map('trim', explode(',', $request->statuses)));
            if ($statuses) {
                $orders->whereIn('status', $statuses);
                $dbg('after statuses IN', $orders);
            }
        }

        if ($request->filled('today_date')) {
            $orders->whereDate('date', $request->today_date);
            $dbg("after today_date={$request->today_date}", $orders);
        }

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $orders->whereBetween('date', [$request->from_date, $request->to_date]);
            $dbg("after between {$request->from_date}..{$request->to_date}", $orders);
        }

        $perPage = (int) ($request->per_page ?? config('constant.PER_PAGE_LIMIT', 15));
        if ($request->filled('per_page') && (int)$request->per_page === -1) {
            $perPage = (clone $orders)->count();
        }

        $paginator = $orders->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        Log::debug('[order-list] final', [
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'total'     => $paginator->total(),
        ]);

        $items = DeliveryManOrderResource::collection($paginator);

        $all_unread_count = $user?->unreadNotifications?->count() ?? 0;
        $wallet_data      = Wallet::where('user_id', $user->id)->first();

        return response()->json([
            'pagination'       => $this->json_pagination_response($paginator),
            'data'             => $items,
            'all_unread_count' => $all_unread_count,
            'wallet_data'      => $wallet_data ?? null,
        ])->header('X-User-Id', $user->id);
    }

    /* -------- Ortak JSON helper'ların aynen kalması -------- */

    function json_message_response($message, $status_code = 200)
    {
        return response()->json(['message' => $message], $status_code);
    }

    function json_custom_response($response, $status_code = 200)
    {
        return response()->json($response, $status_code);
    }

    function json_list_response($data)
    {
        return response()->json(['data' => $data]);
    }

    function json_pagination_response($items)
    {
        return [
            'total_items' => $items->total(),
            'per_page' => $items->perPage(),
            'currentPage' => $items->currentPage(),
            'totalPages' => $items->lastPage()
        ];
    }

    /* -------- Cüzdan işlemleri (sende zaten varsa aynen kalır) -------- */
    private function walletTransactionCompleted(int $orderId): void
    {
        // Burada mevcut iş mantığını uyguluyorsun; bende içi boş.
        // Örn: Wallet::credit(...);
    }

    // private function walletTransactionCancelled(int $orderId): void { ... }
    
    
public function handoffMultiple(Request $request, DeliveryOrderImporter $importer)
{
    $data = $request->validate([
        'order_ids' => ['required', 'array', 'min:2'],
        'order_ids.*' => ['integer'],
        'auto_assign' => ['sometimes', 'boolean'],
        'vehicle_id'  => ['sometimes', 'integer'],
        'payment_collect_from' => ['sometimes', 'in:on_pickup,on_delivery'],
    ]);

    return DB::transaction(function () use ($data) {

        $orders = Order::with([
                'dealer',
                'buyer',
                'items.productVariant.product',
            ])
            ->whereIn('id', $data['order_ids'])
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Sipariş bulunamadı',
            ], 404);
        }

        if ($orders->count() < 2) {
            return response()->json([
                'success' => false,
                'message' => 'En az 2 geçerli sipariş bulunmalıdır.',
            ], 422);
        }

        $first = $orders->first();

        $sameDealer = $orders->every(fn($o) => $o->dealer_id == $first->dealer_id);
        $sameAddress = $orders->every(fn($o) =>
            $o->city == $first->city &&
            $o->district == $first->district &&
            $o->shipping_address == $first->shipping_address
        );

        if (!$sameDealer || !$sameAddress) {
            return response()->json([
                'success' => false,
                'message' => 'Toplu atama için tüm siparişlerin bayi ve adres bilgisi aynı olmalı.',
            ], 422);
        }

        // --- TOPLAM TUTAR ---
        $totalAmount = $orders->sum('total_amount');

        // --- FLAT ITEMS (tekli sipariş formatı) ---
        $items = [];

        foreach ($orders as $o) {
            foreach ($o->items as $it) {

                $items[] = [
                    'product_name'       => optional($it->productVariant->product)->name,
                    'variant_name'       => optional($it->productVariant)->name,
                    'product_variant_id' => (int) $it->product_variant_id,
                    'seller_id'          => (int) $it->seller_id,
                    'quantity'           => (int) $it->quantity,
                    'unit_price'         => (float) $it->unit_price,
                    'total_price'        => (float) $it->total_price,
                    'order_id'           => $o->id,
                    'order_no'           => $o->order_number,
                ];

            }
        }

        // --- EXTRA CHARGES (tekli ile aynı mantık) ---
        $extraCharges = [
            'source'  => 'orders',
            'orders'  => $orders->map(fn($o) => [
                'order_id'    => $o->id,
                'order_no'    => $o->order_number,
                'total'       => (float) $o->total_amount,
                'items_count' => $o->items->count(),
            ])->values()->all(),
            'currency' => 'TRY',
        ];

        // --- JSON: pickup & delivery ---
        $pickupPoint   = $this->buildPickupJson($first);
        $deliveryPoint = $this->buildDeliveryJson($first);

        // --- DELIVERY ORDER OLUŞTUR ---
        $delivery = new DeliveryOrder();
        $delivery->client_id           = $first->dealer_id;
        $delivery->pickup_point        = $pickupPoint;
        $delivery->delivery_point      = $deliveryPoint;
        $delivery->parcel_type         = 'DİĞER';
        $delivery->total_weight        = 1;
        $delivery->total_distance      = 0;
        $delivery->date                = now();
        $delivery->parent_order_id     = $first->id;
        $delivery->status              = 'active';
        $delivery->payment_collect_from= $data['payment_collect_from'] ?? 'on_delivery';
        $delivery->vehicle_id          = $data['vehicle_id'] ?? null;

        $delivery->extra_charges       = $extraCharges;
        $delivery->reason              = $items;   // FLAT ARRAY (tekli ile uyumlu)
        $delivery->total_amount        = $totalAmount;
        $delivery->auto_assign         = (bool) ($data['auto_assign'] ?? false);

        $delivery->save();

        // SIPARİŞLERİ GÜNCELLE
        Order::whereIn('id', $data['order_ids'])->update([
            'dealer_status'   => 'courier',
            'delivery_status' => 1,
        ]);

        return response()->json([
            'success'           => true,
            'delivery_order_id' => $delivery->id,
        ]);
    });
}

    /**
     * Bayi / mağaza tarafı pickup JSON
     */
    protected function buildPickupJson(Order $o): array
    {
        $dealer = $o->dealer; // ilişkili bayi (nullable olabilir)

        $dealerName = optional($dealer)->company_name
            ?? optional($dealer)->name
            ?? $o->dealer_name
            ?? 'Mağaza';

        $dealerAddress = optional($dealer)->address
            ?? $o->shipping_address
            ?? '';

        $dealerCity = optional($dealer)->city
            ?? $o->city
            ?? '';

        $dealerDistrict = optional($dealer)->district
            ?? $o->district
            ?? '';

        $dealerPhone = optional($dealer)->phone
            ?? $o->dealer_phone
            ?? $o->phone
            ?? null;

        $pickupLat = optional($dealer)->latitude;
        $pickupLng = optional($dealer)->longitude;

        return [
            'name'          => $dealerName,
            'address'       => $dealerAddress,
            'city'          => $dealerCity,
            'district'      => $dealerDistrict,
            'contact_name'  => $dealerName,
            'contact_phone' => $dealerPhone,
            'reference'     => $o->order_number,
            'latitude'      => $pickupLat,
            'longitude'     => $pickupLng,
        ];
    }

    /**
     * Müşteri tarafı: delivery_point JSON
     */
    protected function buildDeliveryJson(Order $o): array
    {
        $buyer = $o->buyer; // ilişkili müşteri (nullable)

        $buyerName = $o->ad_soyad
            
            ?? 'Müşteri';

        $buyerAddress = $o->shipping_address
            ?? optional($buyer)->address
            ?? '';

        $buyerCity = $o->buyer_city
            ?? optional($buyer)->city
            ?? $o->city
            ?? '';

        $buyerDistrict = $o->buyer_district
            ?? optional($buyer)->district
            ?? $o->district
            ?? '';

        $buyerPhone = $o->buyer_phone
            ?? optional($buyer)->phone
            ?? null;

        $buyerLat = optional($buyer)->latitude;
        $buyerLng = optional($buyer)->longitude;

        return [
            'name'          => $buyerName,
            'address'       => $buyerAddress,
            'city'          => $buyerCity,
            'district'      => $buyerDistrict,
            'contact_name'  => $buyerName,
            'contact_phone' => $buyerPhone,
            'reference'     => $o->order_number,
            'latitude'      => $buyerLat,
            'longitude'     => $buyerLng,
        ];
    }

    /**
     * Eğer ileride farklı senaryoda tek dropoff JSON lazım olursa
     */
    protected function buildDropoffJson(Order $order): array
    {
        return [
            'type'    => 'dropoff',
            'name'    => $order->customer_name ?? 'Müşteri',
            'phone'   => $order->customer_phone ?? null,
            'address' => $order->customer_address ?? '',
        ];
    }



 
    /**
     * Eğer ileride farklı senaryoda tek dropoff JSON lazım olursa
     */
 


    public function dealerDeliver(Request $request)
{
    $request->validate([
        'email' => ['required', 'email'],
        'page'  => ['nullable', 'integer'],
    ]);

    $dealer = User::where('email', $request->email)->first();

    if (!$dealer) {
        return response()->json([
            'success' => false,
            'message' => 'Bayi bulunamadı'
        ], 404);
    }

    $orders = DeliveryOrder::query()
        ->where('client_id', $dealer->id)   // 👈 bayi
        ->whereNotIn('status', ['delivered','closed','cancelled'])
        ->orderByDesc('date')
        ->orderByDesc('id')
        ->paginate(20);

    return response()->json([
        'success' => true,
        'orders'  => $orders,
    ]);
}
public function orderDetailDealerDeliver(Request $request)
{
    $request->validate(['id' => 'required|integer']);

    $order = DeliveryOrder::with(['delivery_man'])
        ->findOrFail($request->id);

    return response()->json([
        'success' => true,
        'data' => $order,
    ]);
}


public function dealerUpdateStatusDeliver(Request $request)
{
    $request->validate([
        'id' => 'required|integer',
        'status' => 'required|string',
        'delivery_man_id' => 'nullable|integer',
    ]);

    $order = DeliveryOrder::findOrFail($request->id);

    $order->status = $request->status;

    if ($request->filled('delivery_man_id')) {
        $order->delivery_man_id = $request->delivery_man_id;
    }

    $order->save();

    return response()->json([
        'success' => true,
        'message' => 'Sipariş güncellendi',
    ]);
}



public function dealerCollectionsReportOld(Request $request)
{
    $data = $request->validate([
        'email'      => 'required|email',
        'date_from'  => 'nullable|date',
        'date_to'    => 'nullable|date',
        'only_paid'  => 'nullable|boolean',
        'page'       => 'nullable|integer',
        'per_page'   => 'nullable|integer',
        'q'          => 'nullable|string',
    ]);

    $dealer = \App\Models\User::where('email', $data['email'])->first();
    if (!$dealer) {
        return response()->json(['success' => false, 'message' => 'Bayi bulunamadı'], 404);
    }

    $perPage  = max(1, min((int)($data['per_page'] ?? 20), 200));
    $from     = $data['date_from'] ?? null;
    $to       = $data['date_to'] ?? null;
    $onlyPaid = (bool)($data['only_paid'] ?? false);
    $search   = trim((string)($data['q'] ?? ''));

    // ----------------------------------------------------------
    // 1) BASE FILTER QUERY (NO SELECT/ORDER/JOINS)
    // ----------------------------------------------------------
    $base = \DB::table('delivery_orders as d')
        ->where('d.client_id', $dealer->id);

    // Tarih filtresi: d.date üzerinden
    if ($from) $base->where('d.date', '>=', $from . ' 00:00:00');
    if ($to)   $base->where('d.date', '<=', $to . ' 23:59:59');

    // Komisyon filtresi
    if ($onlyPaid) {
        $base->where('d.commission_status', 'charged');
    } else {
        $base->whereIn('d.commission_status', ['reserved','charged','void','none']);
    }

    // Arama (totals şişmesin diye sadece order_no/id)
    if ($search !== '') {
        $base->where(function ($qq) use ($search) {
            $qq->where('d.customer_fcm_token', 'like', "%{$search}%")
               ->orWhere('d.id', $search);
        });
    }

    // ----------------------------------------------------------
    // 2) TOTALS (Toplam Tahsilat YOK)
    //    - total_restaurant_commission = SUM(commission_amount)
    //    - total_courier_payment       = SUM(fixed_charges)
    // ----------------------------------------------------------
    $totals = (clone $base)
        ->selectRaw('
            COUNT(*) as orders_count,
            COALESCE(SUM(d.commission_amount),0) as total_restaurant_commission,
            COALESCE(SUM(d.fixed_charges),0) as total_courier_payment
        ')
        ->first();

    // ----------------------------------------------------------
    // 3) ROWS (display amaçlı join)
    // ----------------------------------------------------------
    $rowsQ = (clone $base)
        ->leftJoin('users as reseller', 'reseller.id', '=', 'd.reseller_id')
        ->leftJoin('users as courier',  'courier.id',  '=', 'd.delivery_man_id');

    // Arama: satırda reseller/courier adına da izin ver
    if ($search !== '') {
        $rowsQ->where(function ($qq) use ($search) {
            $qq->where('d.customer_fcm_token', 'like', "%{$search}%")
               ->orWhere('reseller.name', 'like', "%{$search}%")
               ->orWhere('courier.name', 'like', "%{$search}%");
        });
    }

    $rowsQ->select([
        'd.id',
        \DB::raw('d.customer_fcm_token as order_number'),
        'd.status',

        // ✅ Ekranda "Kurye Ücreti" göstereceğin alan
        'd.fixed_charges',

        // ✅ Ekranda "Restoran Komisyonu" göstereceğin alan
        'd.commission_amount',
        'd.commission_rate',
        'd.commission_status',
        'd.commission_reserved_at',
        // 'd.commission_charged_at', // varsa aç

        // Tarih alanı: sen "date" kullanacağım dedin
        'd.date',
        'd.created_at',

        \DB::raw('reseller.id as reseller_id'),
        \DB::raw('reseller.name as reseller_name'),

        \DB::raw('courier.id as courier_id'),
        \DB::raw('courier.name as courier_name'),
    ])->orderByDesc('d.id');

    $rows = $rowsQ->paginate($perPage);

    return response()->json([
        'success' => true,
        'dealer' => [
            'id' => $dealer->id,
            'email' => $dealer->email,
            'name' => $dealer->name,
        ],
        'filters' => [
            'date_from' => $from,
            'date_to' => $to,
            'only_paid' => $onlyPaid,
            'q' => $search !== '' ? $search : null,
        ],
        'totals' => [
            'orders_count' => (int)($totals->orders_count ?? 0),
            'total_restaurant_commission' => (float)($totals->total_restaurant_commission ?? 0),
            'total_courier_payment' => (float)($totals->total_courier_payment ?? 0),
        ],
        'orders' => $rows,
    ]);
}

public function dealerCollectionsReport(Request $request)
{
    $data = $request->validate([
        'email'      => 'required|email',
        'date_from'  => 'nullable|date',
        'date_to'    => 'nullable|date',
        'only_paid'  => 'nullable|boolean',
        'per_page'   => 'nullable|integer',
        'q'          => 'nullable|string',
    ]);

    $dealer = \App\Models\User::where('email', $data['email'])->first();
    if (!$dealer) {
        return response()->json(['success' => false, 'message' => 'Bayi bulunamadı'], 404);
    }

    $perPage  = max(1, min((int)($data['per_page'] ?? 20), 200));
    $from     = $data['date_from'] ?? null;
    $to       = $data['date_to'] ?? null;
    $onlyPaid = (bool)($data['only_paid'] ?? false);
    $search   = trim((string)($data['q'] ?? ''));

    // ✅ Tek query mantığı: joinler burada, q burada
    $base = \DB::table('delivery_orders as d')
        ->leftJoin('users as reseller', 'reseller.id', '=', 'd.reseller_id')     // restoran/işletme
        ->leftJoin('users as courier',  'courier.id',  '=', 'd.delivery_man_id') // kurye
        ->where('d.client_id', $dealer->id);

    // Tarih filtresi (d.date)
    if ($from) $base->where('d.date', '>=', $from . ' 00:00:00');
    if ($to)   $base->where('d.date', '<=', $to . ' 23:59:59');

    // onlyPaid filtresi
    if ($onlyPaid) {
        $base->where('d.commission_status', 'charged');
    } else {
        $base->whereIn('d.commission_status', ['reserved','charged','void','none']);
    }

    // ✅ q filtresi: order no / restoran / kurye / id
    if ($search !== '') {
        $base->where(function ($qq) use ($search) {
            $qq->where('d.customer_fcm_token', 'like', "%{$search}%")
               ->orWhere('reseller.name', 'like', "%{$search}%")
               ->orWhere('courier.name', 'like', "%{$search}%");

            if (ctype_digit($search)) {
                $qq->orWhere('d.id', (int)$search);
            }
        });
    }

    // ✅ Totals (aynı filtrelerle)
    $totals = (clone $base)
        ->selectRaw('
            COUNT(*) as orders_count,
            COALESCE(SUM(d.commission_amount),0) as total_restaurant_commission,
            COALESCE(SUM(d.fixed_charges),0) as total_courier_payment
        ')
        ->first();

    // ✅ Rows
    $rows = (clone $base)
        ->select([
            'd.id',
            \DB::raw('d.customer_fcm_token as order_number'),
            'd.status',

            // ✅ Kurye ödemesi: total_amount değil!
            'd.fixed_charges',
            'd.courier_payment_status',
            'd.courier_paid_at',
            'd.commission_charged_at',
            // ✅ İşletmeden alınacak komisyon
            'd.commission_amount',
            'd.commission_rate',
            'd.commission_status',
            'd.date',
            'd.created_at',

            \DB::raw('reseller.id as reseller_id'),
            \DB::raw('reseller.name as reseller_name'),

            \DB::raw('courier.id as courier_id'),
            \DB::raw('courier.name as courier_name'),
        ])
        ->orderByDesc('d.id')
        ->paginate($perPage);

    return response()->json([
        'success' => true,
        'dealer' => [
            'id' => $dealer->id,
            'email' => $dealer->email,
            'name' => $dealer->name,
        ],
        'filters' => [
            'date_from' => $from,
            'date_to' => $to,
            'only_paid' => $onlyPaid,
            'q' => $search !== '' ? $search : null,
        ],
        'totals' => [
            'orders_count' => (int)($totals->orders_count ?? 0),
            'total_restaurant_commission' => (float)($totals->total_restaurant_commission ?? 0),
            'total_courier_payment' => (float)($totals->total_courier_payment ?? 0),
        ],
        'orders' => $rows,
    ]);
}

 
    public function bulkCharge(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|min:1',
        ]);

        $dealer = User::where('email', $data['email'])->first();
        if (!$dealer) {
            throw ValidationException::withMessages(['email' => 'Bayi bulunamadı']);
        }

        $ids = array_values(array_unique($data['order_ids']));

        // ✅ yalnızca bu bayiye ait siparişler işlenir
        DB::transaction(function () use ($dealer, $ids) {
            $q = DB::table('delivery_orders')
                ->whereIn('id', $ids);
             //   ->where('reseller_id', $dealer->id);

            // İstersen iptal/void olanları dışla:
            // $q->whereNotIn('commission_status', ['void','canceled']);

            $q->update([
                'commission_status' => 'charged',
                'commission_charged_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // ✅ UI anında güncellesin diye updated rows dönelim
        $rows = DB::table('delivery_orders')
            ->select([
                'id',
                'customer_fcm_token',
        
                'fixed_charges',
                'commission_amount',
                'commission_status',
                'commission_charged_at',
                'courier_payment_status',
                'courier_paid_at',
                'created_at',
            ])
            ->whereIn('id', $ids)
          //  ->where('reseller_id', $dealer->id)
            ->get();

        return response()->json([
            'success' => true,
            'updated_count' => count($rows),
            'rows' => $rows,
        ]);
    }

  public function bulkPayCourier(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|min:1',
        ]);

        $dealer = User::where('email', $data['email'])->first();
        if (!$dealer) {
            throw ValidationException::withMessages(['email' => 'Bayi bulunamadı']);
        }

        $ids = array_values(array_unique($data['order_ids']));

        DB::transaction(function () use ($dealer, $ids) {
            DB::table('delivery_orders')
                ->whereIn('id', $ids)
            //    ->where('reseller_id', $dealer->id)
                ->update([
                    'courier_payment_status' => 'paid',
                    'courier_paid_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $rows = DB::table('delivery_orders')
            ->select([
                'id',
              //  'order_number',
          //      'reseller_name',
           //     'courier_name',
                'fixed_charges',
                'commission_amount',
                'commission_status',
                'commission_charged_at',
                'courier_payment_status',
                'courier_paid_at',
                'created_at',
            ])
            ->whereIn('id', $ids)
     //       ->where('reseller_id', $dealer->id)
            ->get();

        return response()->json([
            'success' => true,
            'updated_count' => count($rows),
            'rows' => $rows,
        ]);
    }
}
