<!-- Footer -->
<footer class="bg-dark text-light py-5 mt-5">
    <div class="container">
        <div class="row g-4">
            <!-- Hakkımızda -->
            <div class="col-lg-4">
                <h5 class="mb-4">Haldeki</h5>
                <p class="text-muted">
                    Haldeki, taze meyve ve sebzeleri direkt halden sizin sofranıza getiren yeni nesil bir online market platformudur.
                </p>
                <div class="social-links mt-4">
                    <a href="#" class="text-light me-3"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="text-light me-3"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="text-light me-3"><i class="bi bi-twitter"></i></a>
                </div>
            </div>

            <!-- Hızlı Linkler -->
            <div class="col-lg-2">
                <h5 class="mb-4">Hızlı Linkler</h5>
                <ul class="list-unstyled">
                    <li class="mb-2">
                        <a href="{{ route('home') }}" class="text-muted text-decoration-none">Anasayfa</a>
                    </li>
                    <li class="mb-2">
                        <a href="{{ route('products.index') }}" class="text-muted text-decoration-none">Ürünler</a>
                    </li>
                    <li class="mb-2">
                        <a href="{{ route('cart.index') }}" class="text-muted text-decoration-none">Sepetim</a>
                    </li>
                </ul>
            </div>

            <!-- Kategoriler -->
            <div class="col-lg-2">
                <h5 class="mb-4">Kategoriler</h5>
                <ul class="list-unstyled">
                    @foreach(\App\Models\Category::orderBy('name')->take(5)->get() as $category)
                        <li class="mb-2">
                            <a href="{{ route('categories.show', $category->slug) }}" class="text-muted text-decoration-none">
                                {{ $category->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <!-- İletişim -->
            <div class="col-lg-4">
                <h5 class="mb-4">İletişim</h5>
                <ul class="list-unstyled text-muted">
                    <li class="mb-3">
                        <i class="bi bi-geo-alt me-2"></i> Ankara Toptancı Hali, Ankara
                    </li>
                    <li class="mb-3">
                        <i class="bi bi-telephone me-2"></i> +90 (312) 123 45 67
                    </li>
                    <li class="mb-3">
                        <i class="bi bi-envelope me-2"></i> info@haldeki.com
                    </li>
                </ul>
            </div>
        </div>

        <hr class="my-4 border-secondary">

        <!-- Alt Footer -->
        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-start">
                <p class="mb-0 text-muted">
                    &copy; {{ date('Y') }} Haldeki. Tüm hakları saklıdır.
                </p>
            </div>
            <div class="col-md-6 text-center text-md-end mt-3 mt-md-0">
                <img src="{{ asset('images/payment-methods.png') }}" alt="Ödeme Yöntemleri" height="30">
            </div>
        </div>
    </div>
</footer>

@push('styles')
<style>
footer {
    background-color: #1a1a1a;
}

footer h5 {
    color: #fff;
    font-weight: 600;
}

footer .social-links a {
    font-size: 1.2rem;
    transition: color 0.3s ease;
}

footer .social-links a:hover {
    color: #198754 !important;
}

footer ul li a {
    transition: color 0.3s ease;
}

footer ul li a:hover {
    color: #198754 !important;
}
</style>
@endpush 