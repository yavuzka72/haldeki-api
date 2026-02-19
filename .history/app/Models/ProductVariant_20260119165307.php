<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;


class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // JSON çıktısına eklenecek sanal alanlar
    protected $appends = ['average_price'];

    // ----- İlişkiler -----

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function prices(): HasMany
    {
        // FK sende 'variant_id' ise ikinci parametreyi 'variant_id' yap
        return $this->hasMany(UserProductPrice::class, 'product_variant_id');
    }

    // ----- Accessor (average_price) -----
    // DİKKAT: Accessor metodu DOĞRUDAN çağrılmaz; $variant->average_price şeklinde okunur.
    public function getAveragePriceAttribute(): float
    {
        // 'active' kolonu yoksa where('active', 1) kısmını kaldırabilirsiniz.
        $avg = $this->prices()
            ->when(
                schemaHasColumn('user_product_prices', 'active'),   // ufak yardımcıyı aşağıda ekledim
                fn ($q) => $q->where('active', 1)
            )
            ->avg('price');

        return (float) ($avg ?? 0.0);
    }
    
              public function getUserPriceAttribute(): ?float
            {
                // request içinden email parametresini al
                $email = request('email');
                if (! $email) {
                    return null;
                }
            
                $user = User::where('email', $email)->first();
                if (! $user) {
                    return null;
                }
            
                return (float) $this->prices()
                    ->where('user_id', $user->id)
                    ->where('active', 1)
                    ->latest('id')
                    ->value('price');
            }
    
}

/**
 * Küçük yardımcı: tablo/kolon var mı kontrolü (opsiyonel)
 * Bunu uygun bir helper dosyasına da koyabilirsiniz.
 */
if (! function_exists('schemaHasColumn')) {
    function schemaHasColumn(string $table, string $column): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
