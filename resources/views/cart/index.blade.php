@extends('layouts.app')

@section('title', 'Sepetim')

@section('content')
<x-page-title 
    title="Sepetim"
    :breadcrumbs="[['title' => 'Sepetim']]"
/>

<div class="container py-5">
    @php
    $cart = session()->get('cart', ['items' => [], 'total' => 0]);
    @endphp

    @if(empty($cart['items']))
        <div class="text-center py-5">
            <i class="bi bi-cart-x display-1 text-muted mb-4"></i>
            <h3 class="mb-4">Sepetiniz Boş</h3>
            <a href="{{ route('products.index') }}" class="btn btn-success">
                <i class="bi bi-arrow-left me-2"></i>Alışverişe Başla
            </a>
        </div>
    @else
        <div class="row">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        @foreach($cart['items'] as $item)
                            <div class="cart-item d-flex align-items-center py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                                <img src="{{ $item['image'] ? 'https://haldeki.com/storage/'.$item['image'] : 'https://via.placeholder.com/100' }}" 
                                     class="rounded-3" width="100" height="100" 
                                     style="object-fit: cover;"
                                     alt="{{ $item['name'] }}">
                                
                                <div class="ms-3 flex-grow-1">
                                    <h5 class="mb-1">{{ $item['name'] }}</h5>
                                    <p class="text-muted mb-1">{{ $item['variant_name'] }}</p>
                                    <div class="d-flex align-items-center">
                                        <div class="quantity-control d-flex align-items-center">
                                            <button class="btn btn-sm btn-outline-secondary update-quantity" 
                                                    data-variant-id="{{ $item['variant_id'] }}" 
                                                    data-action="decrease">-</button>
                                            <span class="mx-3">{{ $item['quantity'] }}</span>
                                            <button class="btn btn-sm btn-outline-secondary update-quantity" 
                                                    data-variant-id="{{ $item['variant_id'] }}" 
                                                    data-action="increase">+</button>
                                        </div>
                                        <div class="ms-auto">
                                            <span class="h5 mb-0 text-success">₺{{ number_format($item['total_price'], 2) }}</span>
                                            <button class="btn btn-sm btn-link text-danger remove-item" 
                                                    data-variant-id="{{ $item['variant_id'] }}">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title mb-4">Sipariş Özeti</h5>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Ara Toplam</span>
                            <span>₺{{ number_format($cart['total'], 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>KDV (%8)</span>
                            <span>₺{{ number_format($cart['total'] * 0.08, 2) }}</span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-4">
                            <strong>Toplam</strong>
                            <strong class="text-success">₺{{ number_format($cart['total'] * 1.08, 2) }}</strong>
                        </div>

                        <a href="{{ route('checkout.index') }}" class="btn btn-success w-100">
                            <i class="bi bi-credit-card me-2"></i>Siparişi Tamamla
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Ürün silme işlemi
    const removeButtons = document.querySelectorAll('.remove-item');
    removeButtons.forEach(button => {
        button.addEventListener('click', async function() {
            if (!confirm('Bu ürünü sepetten kaldırmak istediğinize emin misiniz?')) {
                return;
            }

            try {
                const response = await fetch('/cart/remove', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        product_id: this.dataset.productId,
                        variant_id: this.dataset.variantId
                    })
                });

                if (!response.ok) throw new Error('İşlem başarısız');

                window.location.reload();
            } catch (error) {
                console.error('Error:', error);
                alert('Ürün silinirken bir hata oluştu');
            }
        });
    });

    // Miktar güncelleme işlemi
    const updateButtons = document.querySelectorAll('.update-quantity');
    updateButtons.forEach(button => {
        button.addEventListener('click', async function() {
            const input = this.parentElement.querySelector('input');
            let quantity = parseInt(input.value);
            const productId = this.dataset.productId;
            const variantId = this.dataset.variantId;

            if (this.dataset.action === 'increase') {
                quantity++;
            } else {
                if (quantity <= 1) return;
                quantity--;
            }

            try {
                const response = await fetch('/cart/update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        product_id: productId,
                        variant_id: variantId,
                        quantity: quantity
                    })
                });

                if (!response.ok) throw new Error('İşlem başarısız');

                const data = await response.json();
                
                // Miktar inputunu güncelle
                input.value = quantity;
                
                // Ürün fiyatını güncelle
                const priceElement = button.closest('.row').querySelector('.fw-bold');
                const unitPrice = parseFloat(priceElement.dataset.unitPrice);
                priceElement.textContent = '₺' + (unitPrice * quantity).toFixed(2);
                
                // Toplam tutarı güncelle
                const totalElement = document.querySelector('.text-success.h5');
                totalElement.textContent = '₺' + data.cart.total.toFixed(2);
                
                // Ara toplam tutarını güncelle
                const subtotalElement = document.querySelector('.card-body .fw-bold');
                subtotalElement.textContent = '₺' + data.cart.total.toFixed(2);

            } catch (error) {
                console.error('Error:', error);
                alert('Miktar güncellenirken bir hata oluştu');
                input.value = quantity - (this.dataset.action === 'increase' ? 1 : -1);
            }
        });
    });
});
</script>
@endpush
@endsection 