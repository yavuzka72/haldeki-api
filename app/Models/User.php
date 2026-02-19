<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        // temel
        'name','email','username','password','user_type','dealer_id','vendor_id',
        'email_verified_at','login_type','uid','fcm_token','admin','admin_level',
        'status','is_active','player_id','app_version','app_source',
        'last_notification_seen','last_location_update_at',

        // adres/konum
        'country_id','city_id','city','district','address',
        'latitude','longitude',          // (string alanlar)
        'location_lat','location_lng',   // (decimal alanlar)

        // iletişim
        'phone','contact_number',

        // kurye alanları
        'vehicle_plate','iban','bank_account_owner',
        'commission_rate','commission_type', // percent | fixed
        'can_take_orders','has_hadi_account',
        'secret_note',

        // evraklar
        'residence_pdf_path','driver_license_front_path','good_conduct_pdf_path',
    ];

    protected $hidden = ['password','remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'admin'                   => 'boolean',
            'admin_level'             => 'integer',
            'is_active'               => 'boolean',
            'status'                  => 'integer',
            'can_take_orders'         => 'boolean',
            'has_hadi_account'        => 'boolean',
            'commission_rate'         => 'decimal:2',
            'location_lat'            => 'decimal:7',
            'location_lng'            => 'decimal:7',
            'last_notification_seen'  => 'datetime',
            'last_location_update_at' => 'datetime',
            'deleted_at'              => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->admin;
    }

    // ilişkiler (mevcutlar + küçük düzeltmeler)
    public function productPrices(): HasMany { return $this->hasMany(UserProductPrice::class); }
    public function cart() { return $this->hasOne(Cart::class); }
    public function orders() { return $this->hasMany(Order::class); }
    public function sellerOrders() { return $this->hasMany(OrderItem::class, 'dealer_id'); }

    public function userProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'user_products')
            ->withPivot('active')->withTimestamps();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'user_products', 'user_id', 'product_id')
            ->withPivot(['active'])->withTimestamps();
    }

    public function activeProducts(): BelongsToMany
    {
        return $this->products()->wherePivot('active', 1);
    }

    public function country(){ return $this->belongsTo(Country::class, 'country_id'); }
    public function city(){ return $this->belongsTo(City::class, 'city_id'); }

    public function deliveryManOrder(){ return $this->hasMany(Order::class,'delivery_man_id')->withTrashed(); }
    public function deliveryManDocument(){ return $this->hasMany(DeliveryManDocument::class,'delivery_man_id')->withTrashed(); }

    public function userBankAccount() { return $this->hasOne(UserBankAccount::class); } // opsiyonel
    public function userWallet() { return $this->hasOne(Wallet::class); }
    public function userWithdraw(){ return $this->hasMany(WithdrawRequest::class); }
    public function userAddress() { return $this->hasMany(UserAddress::class); }

    public function getPayment()
    {
        return $this->hasManyThrough(
            Payment::class, Order::class, 'delivery_man_id','order_id','id','id'
        )->where('payment_status','paid');
    }

    public function userWalletHistory(){ return $this->hasMany(WalletHistory::class); }
}
