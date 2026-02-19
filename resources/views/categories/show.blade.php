@extends('layouts.app')

@section('title', $category->name)

@section('content')
<x-page-title 
    :title="$category->name"
    :breadcrumbs="[
        ['title' => 'Kategoriler', 'url' => route('categories.index')],
        ['title' => $category->name]
    ]"
/>

<div class="container py-5">
    @if($category->description)
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="card-text">{{ $category->description }}</p>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-4">
        @forelse($products as $product)
            <div class="col-6 col-md-4 col-lg-3">
                <x-product-card :product="$product" />
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-info">
                    Bu kategoride henüz ürün bulunmuyor.
                </div>
            </div>
        @endforelse
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $products->links() }}
    </div>
</div>
@endsection