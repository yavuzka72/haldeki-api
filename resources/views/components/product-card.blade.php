@props(['product'])

<div class="card h-100 border-0 shadow-sm product-card">
    <a href="{{ route('products.show', $product->id) }}" class="text-decoration-none">
        <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
           <img src="{{ $product->image ? 'https://haldeki.com/storage/'.$product->image : 'https://via.placeholder.com/300x200' }}" 
                             class="card-img-top" alt="{{ $product->name }}">
        </div>
        
        <div class="card-body">
            <h5 class="card-title text-dark mb-2">{{ $product->name }}</h5>
            
            @if($product->variants->count() > 0)
                <p class="card-text text-muted mb-2">
                    {{ $product->variants->count() }} varyant mevcut
                </p>
                <p class="card-text">
                    <span class="text-primary fw-bold">
                        {{ number_format($product->variants->min('price'), 2) }} ₺
                    </span>
                    'den başlayan fiyatlarla
                </p>
            @else
                <p class="card-text text-muted">
                    İncele
                </p>
            @endif
        </div>
    </a>
</div>

<style>
.product-card {
    transition: transform 0.2s ease-in-out;
}

.product-card:hover {
    transform: translateY(-5px);
}

.product-card .card-img-top {
    height: 200px;
    object-fit: cover;
}
</style> 