@extends('layouts.app')

@section('title', 'Kategoriler')

@section('content')
<x-page-title 
    title="Kategoriler"
    :breadcrumbs="[['title' => 'Kategoriler']]"
/>

<div class="container py-5">
    <div class="row g-4">
        @foreach($categories as $category)
            <div class="col-md-4">
                <a href="{{ route('categories.show', $category->slug) }}" class="text-decoration-none">
                    <div class="category-card rounded-4 overflow-hidden position-relative">
                        <img src="{{ $category->image ? Storage::url($category->image) : 'https://via.placeholder.com/400x300' }}" 
                             class="img-fluid w-100" 
                             alt="{{ $category->name }}">
                        <div class="category-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center">
                            <div class="text-center text-white">
                                <h3 class="fw-bold mb-3">{{ $category->name }}</h3>
                                <span class="btn btn-light">Ürünleri Gör</span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
</div>

@push('styles')
<style>
.category-card {
    aspect-ratio: 4/3;
    cursor: pointer;
    transition: all 0.3s ease;
}

.category-overlay {
    background: rgba(25, 135, 84, 0.8);
    opacity: 0;
    transition: all 0.3s ease;
}

.category-card:hover .category-overlay {
    opacity: 1;
}
</style>
@endpush
@endsection 