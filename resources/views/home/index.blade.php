@extends('layouts.app')

@section('title', 'Ana Sayfa')

@section('content')
<!-- Hero Section -->
<div class="hero-section position-relative mb-5">
    <div class="hero-overlay position-absolute w-100 h-100"></div>
    <div class="container position-relative py-6">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <span class="badge bg-success mb-3">Taze & Organik</span>
                <h1 class="display-4 fw-bold text-white mb-4">Yeni Nesil Hal</h1>
                <p class="lead text-white mb-4 opacity-90">Halden direkt size, aynı gün teslimat</p>
                <div class="d-flex gap-3">
                    <a href="{{ route('products.index') }}" class="btn btn-light btn-lg px-4">
                        <i class="bi bi-basket me-2"></i>Alışverişe Başla
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Kampanya Banner -->
<div class="container mb-5">
    <div class="row g-4">
        <div class="col-md-6">
            <div class="promo-banner rounded-4 overflow-hidden position-relative">
                <img src="https://yalcinmarket.com.tr/wp-content/uploads/2025/01/Ocak-ayi-sebze.webp" class="img-fluid w-100" alt="Organik Sebzeler">
                <div class="promo-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center">
                    <div class="p-4">
                        <h3 class="text-white fw-bold mb-3">Organik Sebzeler</h3>
                        <p class="text-white mb-3">Sertifikalı çiftliklerden<br>sofralarınıza</p>
                        <a href="{{ route('products.index') }}" class="btn btn-light">Keşfet</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="promo-banner rounded-4 overflow-hidden position-relative">
                <img src="https://supstranger.com/media/blog/ithal-meyveler.jpg" class="img-fluid w-100" alt="Mevsim Meyveleri">
                <div class="promo-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center">
                    <div class="p-4">
                        <h3 class="text-white fw-bold mb-3">Mevsim Meyveleri</h3>
                        <p class="text-white mb-3">Taze ve lezzetli<br>mevsim meyveleri</p>
                        <a href="{{ route('products.index') }}" class="btn btn-light">Keşfet</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<!-- Özellikler -->
<div class="bg-light py-5 mb-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-3">
                <div class="feature-card text-center p-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-truck text-success display-4"></i>
                    </div>
                    <h5 class="fw-bold">Aynı Gün Teslimat</h5>
                    <p class="text-muted mb-0">Saat 14:00'e kadar verilen siparişler aynı gün teslim</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="feature-card text-center p-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-shield-check text-success display-4"></i>
                    </div>
                    <h5 class="fw-bold">Kalite Garantisi</h5>
                    <p class="text-muted mb-0">Memnun kalmazsanız anında iade garantisi</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="feature-card text-center p-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-hand-thumbs-up text-success display-4"></i>
                    </div>
                    <h5 class="fw-bold">Taze Ürünler</h5>
                    <p class="text-muted mb-0">Her gün halden direkt size</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="feature-card text-center p-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-wallet2 text-success display-4"></i>
                    </div>
                    <h5 class="fw-bold">Uygun Fiyat</h5>
                    <p class="text-muted mb-0">Hal fiyatlarıyla rekabetçi fiyatlar</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Öne Çıkan Ürünler -->
<div class="container mb-5">
    <div class="section-header text-center mb-5">
        <span class="badge bg-success mb-2">Öne Çıkanlar</span>
        <h2 class="display-5 fw-bold mb-3">Günün Fırsatları</h2>
        <p class="text-muted">En taze ürünler, en uygun fiyatlarla</p>
    </div>

    <div class="row g-4">
        @foreach(array_slice($products, 0, 8) as $product)
            <div class="col-md-3">
                <div class="product-card card h-100 border-0 shadow-sm">
                    <div class="product-image position-relative">
                        <img src="{{ $product['image'] ? 'https://haldeki.com/storage/'.$product['image'] : 'https://via.placeholder.com/300x200' }}" 
                             class="card-img-top" alt="{{ $product['name'] }}">
                        @if(!empty($product['discount']))
                            <div class="discount-badge position-absolute top-0 end-0 m-3">
                                <span class="badge bg-danger">%{{ $product['discount'] }} İndirim</span>
                            </div>
                        @endif
                    </div>
                    <div class="card-body">
                        <h5 class="product-title">{{ $product['name'] }}</h5>
                        @if(!empty($product['variants']))
                            <div class="product-price mb-3">
                                @php
                                    $prices = array_column($product['variants'], 'average_price');
                                    $minPrice = min($prices);
                                @endphp
                                <span class="text-success fw-bold h5 mb-0">
                                    ₺{{ number_format($minPrice, 2) }}
                                </span>
                                <small class="text-muted">/kg</small>
                            </div>
                        @endif
                        <div class="product-actions d-flex gap-2">
                            <a href="{{ route('products.show', $product['id']) }}" 
                               class="btn btn-outline-success flex-grow-1">
                                <i class="bi bi-eye me-2"></i>İncele
                            </a>
                            <button class="btn btn-success quick-add" 
                                    data-product-id="{{ $product['id'] }}">
                                <i class="bi bi-cart-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

    <div class="text-center mt-4">
        <a href="{{ route('products.index') }}" class="btn btn-success btn-lg">
            Tüm Ürünler
        </a>
    </div>
    </div>
</div>

@push('styles')
<style>
:root {
    --primary-color: #198754;
    --primary-dark: #146c43;
}

.hero-section {
    background: url('/images/hero-bg.jpg') no-repeat center center;
    background-size: cover;
    min-height: 600px;
    display: flex;
    align-items: center;
}

.hero-overlay {
    background: linear-gradient(to right, rgba(0, 128, 0, 0.7), rgba(128, 128, 128, 0.7)), 
                url(https://images.unsplash.com/photo-1542838132-92c53300491e?q=80);
    background-size: cover;
    background-position: center;
}

.py-6 {
    padding-top: 5rem;
    padding-bottom: 5rem;
}

/* Category Cards */
.category-card {
    aspect-ratio: 4/3;
    cursor: pointer;
    transition: all 0.3s ease;
}

.category-card img {
    height: 100%;
    object-fit: cover;
}

.category-overlay {
    background: rgba(25, 135, 84, 0.8);
    opacity: 0;
    transition: all 0.3s ease;
}

.category-card:hover .category-overlay {
    opacity: 1;
}

/* Feature Cards */
.feature-card {
    background: white;
    border-radius: 1rem;
    transition: all 0.3s ease;
}

.feature-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

/* Product Cards */
.product-card {
    border-radius: 1rem;
    overflow: hidden;
    transition: all 0.3s ease;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}

.product-image img {
    height: 200px;
    object-fit: cover;
}

/* Promo Banners */
.promo-banner {
    height: 300px;
    overflow: hidden;
}

.promo-banner img {
    height: 100%;
    object-fit: cover;
}

.promo-overlay {
    background: linear-gradient(to right, rgba(25, 135, 84, 0.9), rgba(25, 135, 84, 0.6));
}

/* Buttons */
.btn {
    border-radius: 0.5rem;
    padding: 0.75rem 1.5rem;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-success {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}

.btn-success:hover {
    background-color: var(--primary-dark);
    border-color: var(--primary-dark);
    transform: translateY(-2px);
}

.btn-outline-success {
    color: var(--primary-color);
    border-color: var(--primary-color);
}

.btn-outline-success:hover {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
    color: white;
    transform: translateY(-2px);
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick add to cart functionality
    const quickAddButtons = document.querySelectorAll('.quick-add');
    quickAddButtons.forEach(button => {
        button.addEventListener('click', async function() {
            const productId = this.dataset.productId;
            const button = this;
            
            try {
                // Önce ürünün ilk varyantını al
                const variantResponse = await fetch(`/api/v1/products/${productId}/variants`);
                const variantData = await variantResponse.json();
                
                if (!variantData.data || variantData.data.length === 0) {
                    throw new Error('Ürün varyantı bulunamadı');
                }

                const variantId = variantData.data[0].id;

                // Sepete ekle
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
                button.innerHTML = '<i class="bi bi-check2"></i>';
                button.classList.add('btn-success');

                // Sepet sayacını güncelle
                const cartCount = document.querySelector('.cart-count');
                if (cartCount) {
                    cartCount.textContent = Object.keys(data.cart.items).length;
                }

                setTimeout(() => {
                    button.innerHTML = '<i class="bi bi-cart-plus"></i>';
                    button.classList.remove('btn-success');
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