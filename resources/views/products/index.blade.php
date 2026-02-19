@extends('layouts.app')

@section('title', 'Ürünler')

@section('content')

<x-page-title 
    title="Ürünler"
    :breadcrumbs="[['title' => 'Ürünler']]"
/>

<div class="container py-5">
    <div class="row g-4">
        @foreach($products as $product)
            <div class="col-md-3">
                <div class="product-card card h-100 border-0 shadow-sm">
                    <div class="product-image position-relative">
                        <img src="{{ $product->image ? Storage::url($product->image) : asset('images/product-placeholder.jpg') }}" 
                             class="card-img-top" alt="{{ $product->name }}">
                        @if(!empty($product->discount))
                            <div class="discount-badge position-absolute top-0 end-0 m-3">
                                <span class="badge bg-danger">%{{ $product->discount }} İndirim</span>
                            </div>
                        @endif
                    </div>
                    <div class="card-body">
                        <h5 class="product-title">{{ $product->name }}</h5>
                        @if($product->variants->isNotEmpty())
                            <div class="product-price mb-3">
                                @php
                                    $prices = $product->variants->pluck('average_price');
                                    $minPrice = $prices->min();
                                @endphp
                                <span class="text-success fw-bold h5 mb-0">
                                    ₺{{ number_format($minPrice, 2) }}
                                </span>
                                <small class="text-muted">/kg</small>
                            </div>
                        @endif
                        <a href="{{ route('products.show', $product->id) }}" class="btn btn-outline-success w-100">
                            <i class="bi bi-eye me-2"></i>İncele
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex justify-content-center mt-5">
        {{ $products->links() }}
    </div>
</div>

@push('styles')
<style>
.product-card {
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

.product-title {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    height: 48px;
}
</style>
@endpush
@endsection 