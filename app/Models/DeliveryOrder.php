<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeliveryOrder extends Model
{
    use SoftDeletes;

    protected $table = 'delivery_orders';

    protected $fillable = [
        'client_id',
        'reseller_id',
        'pickup_point',
        'delivery_point',
        'country_id',
        'city_id',
        'parcel_type',
        'total_weight',
        'total_distance',
        'date',
        'pickup_datetime',
        'delivery_datetime',
        'parent_order_id',
        'payment_id',
        'reason',
        'status',
        'payment_collect_from',
        'delivery_man_id',
        'deliveryman_fcm_token',
        'fixed_charges',
        'weight_charge',
        'distance_charge',
        'extra_charges',
        'total_amount',
        'pickup_confirm_by_client',
        'pickup_confirm_by_delivery_man',
        'total_parcel',
        'vehicle_id',
        'vehicle_data',
        'auto_assign',
        'cancelled_delivery_man_ids',
        'delivery_photo',
        'order_photo',
        'pick_photo',
        'customer_fcm_token',
    ];

    protected $casts = [
        // JSON
        'pickup_point'   => 'array',
        'delivery_point' => 'array',
        'extra_charges'  => 'array',
        'vehicle_data'   => 'array',

        // sayısal
        'total_weight'     => 'float',
        'total_distance'   => 'float',
        'fixed_charges'    => 'float',
        'weight_charge'    => 'float',
        'distance_charge'  => 'float',
        'total_amount'     => 'float',
        'total_parcel'     => 'int',

        // boolean
        'pickup_confirm_by_client'        => 'boolean',
        'pickup_confirm_by_delivery_man'  => 'boolean',
        'auto_assign'                     => 'boolean',

        // tarih/saat
        'date'              => 'datetime',
        'pickup_datetime'   => 'datetime',
        'delivery_datetime' => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
        'deleted_at'        => 'datetime',
  
    'reason'         => 'array',
    ];

    /* ---------- Accessor ---------- */
    protected $appends = ['reason_items','delivery_man_name','delivery_photo_url','order_photo_url','pick_photo_url'];

    public function getReasonItemsAttributew(): array
    {
        if (!$this->reason) return [];
        $arr = json_decode($this->reason, true);
        return is_array($arr) ? $arr : [];
    }
public function getReasonItemsAttribute(): array
{
    // reason alanı casts içinde 'array' olduğu için
    // çoğu durumda buraya array olarak gelecek.
    if (is_array($this->reason)) {
        return $this->reason;
    }

    // null, boş string vs.
    if (empty($this->reason)) {
        return [];
    }

    // Eski kayıtlar JSON string ise, geriye dönük uyumluluk için decode et
    $arr = json_decode($this->reason, true);

    return is_array($arr) ? $arr : [];
}

    public function setReasonItemsAttribute($val): void
    {
        $this->attributes['reason'] = is_array($val)
            ? json_encode($val, JSON_UNESCAPED_UNICODE)
            : $val;
    }

    public function getDeliveryManNameAttribute()
    {
        return optional($this->delivery_man)->name;
    }

    public function getDeliveryPhotoUrlAttribute(): ?string
    {
        if (!$this->delivery_photo) return null;
        return str_starts_with($this->delivery_photo, 'http')
            ? $this->delivery_photo
            : Storage::url($this->delivery_photo);
    }

    public function getOrderPhotoUrlAttribute(): ?string
    {
        if (!$this->order_photo) return null;
        return str_starts_with($this->order_photo, 'http')
            ? $this->order_photo
            : Storage::url($this->order_photo);
    }

    public function getPickPhotoUrlAttribute(): ?string
    {
        if (!$this->pick_photo) return null;
        return str_starts_with($this->pick_photo, 'http')
            ? $this->pick_photo
            : Storage::url($this->pick_photo);
    }

    /* ---------- İlişkiler ---------- */
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id')->withTrashed();
    }

    public function delivery_man()
    {
        return $this->belongsTo(User::class, 'delivery_man_id')->withTrashed();
    }

    public function deliveryMan()
    {
        return $this->belongsTo(User::class, 'delivery_man_id')->withTrashed();
    }

    /* ---------- Query Scopes ---------- */
    public function scopeClient(Builder $query, ?int $clientId): Builder
    {
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }
        return $query;
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (!empty($status)) {
            $query->where('status', $status);
        }
        return $query;
    }

    public function scopeDateBetween(Builder $query, $from = null, $to = null): Builder
    {
        if ($from) $query->where('date', '>=', $from);
        if ($to)   $query->where('date', '<=', $to);
        return $query;
    }

    /**
     * Oturumdaki kullanıcının rolüne göre görünür siparişler.
     */
    public function scopeMyOrder(Builder $query): Builder
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return $query->whereRaw('1=0');
        }

        if (in_array($user->user_type, ['admin', 'demo_admin'], true)) {
            return $query;
        }

        if ($user->user_type === 'client') {
            return $query->where('client_id', $user->id);
        }

        if ($user->user_type === 'delivery_man') {
            return $query->where('delivery_man_id', $user->id);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $term = null): Builder
    {
        if (!$term) return $query;

        $term = trim($term);
        return $query->where(function ($qq) use ($term) {
            $qq->whereRaw("JSON_EXTRACT(delivery_point, '$.name') LIKE ?", ["%{$term}%"])
               ->orWhereRaw("JSON_EXTRACT(delivery_point, '$.address') LIKE ?", ["%{$term}%"])
               ->orWhere('status', 'like', "%{$term}%")
               ->orWhere('payment_collect_from', 'like', "%{$term}%")
               ->orWhere('order_photo', 'like', "%{$term}%")
               ->orWhere('pick_photo', 'like', "%{$term}%")
               ->orWhere('delivery_photo', 'like', "%{$term}%")
               ->orWhere('deliveryman_fcm_token', 'like', "%{$term}%");
        });
    }

    protected static function booted()
    {
        static::updated(function (DeliveryOrder $do) {
            if ($do->wasChanged('status')) {
                try {
                    $dealerStatus = self::mapDeliveryToDealer($do->status);

                    $q = \App\Models\Order::query();
                    if ($do->parent_order_id) {
                        $q->where('id', (int) $do->parent_order_id);
                    } elseif (!empty($do->customer_fcm_token)) {
                        $q->where('order_number', $do->customer_fcm_token);
                    } else {
                        Log::warning('[DeliveryOrder->Order sync] eşleşecek anahtar yok', [
                            'delivery_order_id' => $do->id,
                            'status'            => $do->status,
                        ]);
                        return;
                    }

                    $affected = $q->update(['dealer_status' => $dealerStatus]);

                    Log::info('[DeliveryOrder->Order sync] dealer_status güncellendi', [
                        'delivery_order_id' => $do->id,
                        'from'              => $do->getOriginal('status'),
                        'to'                => $do->status,
                        'mapped'            => $dealerStatus,
                        'affected'          => $affected,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[DeliveryOrder->Order sync] hata: ' . $e->getMessage(), [
                        'delivery_order_id' => $do->id,
                    ]);
                }
            }
        });
    }

    // DeliveryOrder.status -> Order.dealer_status map
    private static function mapDeliveryToDealer(?string $s): string
    {
        $s = strtolower((string)$s);

        // DeliveryOrder.status: create, active, completed, cancelled, courier_picked_up, ...
        // Order.dealer_status enum: pending | courier | away | delivered | closed | cancelled
        return match ($s) {
            'create', 'draft', 'pending'                 => 'pending',
            'active', 'courier_assigned'                 => 'courier',
            'courier_picked_up', 'courier_departed',
            'courier_arrived', 'on_the_way', 'in_transit'=> 'away',
            'completed', 'delivered'                     => 'delivered',
            'closed'                                     => 'closed',
            'cancelled', 'canceled'                      => 'cancelled',
            default                                      => 'pending',
        };
    }
}
