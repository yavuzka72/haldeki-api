<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',        // buyer
        'dealer_id',      // <-- EKLE
        'reseller_id',    // vendor
        'total_amount',
        'status',
        'dealer_status',
        'supplier_status', // <-- SONDaki boşluğu kaldır
        'payment_status',
        'note',
        'shipping_address',
        'phone',
          'partner_client_id', 
            'partner_order_id', 
              'user_type',
          'ad_soyad',
        // (opsiyonel) 'order_number',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Order $order) {
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-' . strtoupper(\Illuminate\Support\Str::random(10));
            }

            // dealer_id boşsa buyer’dan türet
            if (empty($order->dealer_id) && !empty($order->user_id)) {
                $buyer = $order->buyer; // user() ile de olur
                if ($buyer) {
                    $dealerId = (int)($buyer->dealer_id ?? $buyer->vendor_id ?? 0);
                    if ($dealerId > 0) {
                        $order->dealer_id = $dealerId;
                    }
                }
            }
        });
    }

    // ---- İlişkiler ----

    // Buyer (user_id)
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Eski adla geriye uyum
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
 


    // Dealer/Bayi (dealer_id)  <-- YENİ
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dealer_id');
    }

    // Vendor/Reseller (reseller_id)
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
        public function partnerClient(): BelongsTo
    {
        return $this->belongsTo(PartnerClient::class, 'partner_client_id');
    }
}
