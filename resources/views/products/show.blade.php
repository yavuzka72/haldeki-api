@extends('layouts.app')

@section('title', $product->name)

@section('content')
<div class="container py-5">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('home') }}">Ana Sayfa</a></li>
            <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Ürünler</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
        </ol>
    </nav>

    <div class="row g-5">
        <!-- Ürün Görseli -->
        <div class="col-lg-6">
            <div class="product-gallery">
                <div class="main-image mb-4">
                    <img src="{{ $product->image ? 'https://haldeki.com/storage/'.$product->image : 'https://via.placeholder.com/600x400' }}" 
                         class="img-fluid rounded-4 shadow-sm" 
                         alt="{{ $product->name }}">
                </div>
                @if(!empty($product->gallery))
                <div class="thumbnails row g-2">
                    @foreach($product->gallery as $image)
                    <div class="col-3">
                        <img src="{{ $image }}" 
                             class="img-fluid rounded-3 cursor-pointer thumbnail-image" 
                             alt="{{ $product->name }}">
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        <!-- Ürün Bilgileri -->
        <div class="col-lg-6">
            <div class="product-info">
                <h1 class="display-5 fw-bold mb-4">{{ $product->name }}</h1>
                
                @if(!empty($product->discount))
                <div class="discount-badge mb-3">
                    <span class="badge bg-danger">%{{ $product->discount }} İndirim</span>
                </div>
                @endif

                <div class="product-description mb-4">
                    <p class="lead text-muted">{{ $product->description }}</p>
                </div>

                @if(!empty($product->variants))
                <div class="variants-section mb-4">
                    <h5 class="mb-3">Paket Seçenekleri</h5>
                    <div class="variants-list">
                        @foreach($product->variants as $variant)
                        <div class="variant-item mb-3 p-3 border rounded-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">{{ $variant->name }}</h6>
                                </div>
                                <div class="text-end">
                                    <div class="price mb-2">
                                            <span class="h4 text-success fw-bold">₺{{ number_format((float)$variant->average_price, 2) }}</span>
                                                    @if(!empty($variant->old_price))
                                        <small class="text-muted text-decoration-line-through ms-2">
                                            ₺{{ number_format($variant->old_price, 2) }}
                                        </small>
                                        @endif
                                    </div>
                                    <button class="btn btn-success add-to-cart" 
                                            data-variant-id="{{ $variant->id }}"
                                            data-product-id="{{ $product->id }}">
                                        <i class="bi bi-cart-plus me-2"></i>Sepete Ekle
                                    </button>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- Ürün Özellikleri -->
                @if(!empty($product->features))
                <div class="features-section mb-4">
                    <h5 class="mb-3">Ürün Özellikleri</h5>
                    <div class="row g-3">
                        @foreach($product->features as $feature)
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-check2-circle text-success me-2"></i>
                                <span>{{ $feature }}</span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- Teslimat Bilgisi -->
                <div class="delivery-info mt-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-truck text-success me-2"></i>
                                <span>Aynı Gün Teslimat</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-shield-check text-success me-2"></i>
                                <span>Kalite Garantisi</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Benzer Ürünler -->
    @if(!empty($relatedProducts))
    <div class="related-products mt-6">
        <h3 class="mb-4">Benzer Ürünler</h3>
        <div class="row g-4">
            @foreach($relatedProducts as $relatedProduct)
            <div class="col-md-3">
                <div class="product-card card h-100 border-0 shadow-sm">
                    <img src="{{ $relatedProduct->image ? 'https://haldeki.com/storage/'.$relatedProduct->image : 'https://via.placeholder.com/300x200' }}" 
                         class="card-img-top" 
                         alt="{{ $relatedProduct->name }}">
                    <div class="card-body">
                        <h5 class="card-title">{{ $relatedProduct->name }}</h5>
                        <p class="card-text text-muted">
                            {{ Str::limit($relatedProduct->description, 50) }}
                        </p>
                        <a href="{{ route('products.show', $relatedProduct->id) }}" 
                           class="btn btn-outline-success w-100">
                            İncele
                        </a>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

@push('styles')
<style>
.mt-6 {
    margin-top: 5rem;
}

.cursor-pointer {
    cursor: pointer;
}

.thumbnail-image {
    transition: all 0.3s ease;
    opacity: 0.7;
}

.thumbnail-image:hover {
    opacity: 1;
}

.variant-item {
    transition: all 0.3s ease;
}

.variant-item:hover {
    background-color: #f8f9fa;
    transform: translateX(5px);
}

.product-card {
    transition: all 0.3s ease;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sepete ekle butonları için animasyon ve işlevsellik
    const addToCartButtons = document.querySelectorAll('.add-to-cart');
    addToCartButtons.forEach(button => {
        button.addEventListener('click', async function() {
            const variantId = this.dataset.variantId;
            const productId = this.dataset.productId;
            const button = this;
            
            try {
                const response = await fetch('/cart/add', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        product_id: productId,
                        variant_id: variantId,
                        quantity: 1
                    })
                });

                if (!response.ok) {
                    throw new Error('Sepete eklenirken bir hata oluştu');
                }

                const data = await response.json();

                // Başarılı ekleme animasyonu
                button.innerHTML = '<i class="bi bi-check2 me-2"></i>Sepete Eklendi';
                button.disabled = true;

                // Sepet sayacını güncelle
                const cartCount = document.querySelector('.cart-count');
                if (cartCount) {
                    cartCount.textContent = Object.keys(data.cart.items).length;
                }
                
                setTimeout(() => {
                    button.innerHTML = '<i class="bi bi-cart-plus me-2"></i>Sepete Ekle';
                    button.disabled = false;
                }, 2000);

            } catch (error) {
                console.error('Error:', error);
                alert('Ürün sepete eklenirken bir hata oluştu');
                button.disabled = false;
            }
        });
    });
});
</script>
@endpush
@endsection 