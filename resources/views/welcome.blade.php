<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>haldeki.local - Yeni Nesil Hal</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            .hero-section {
                background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)),
                            url('https://images.unsplash.com/photo-1542838132-92c53300491e?q=80');
                background-size: cover;
                background-position: center;
                height: 600px;
                color: white;
            }

            .feature-icon {
                font-size: 3rem;
                color: #28a745;
                margin-bottom: 1rem;
            }

            .navbar {
                background-color: rgba(255, 255, 255, 0.95);
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .feature-card {
                transition: transform 0.3s;
                border: none;
                border-radius: 15px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }

            .feature-card:hover {
                transform: translateY(-10px);
            }

            .product-card {
                transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
                border: none;
                border-radius: 15px;
                overflow: hidden;
            }

            .product-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            }

            .product-card .card-img-top {
                transition: transform 0.3s ease-in-out;
            }

            .product-card:hover .card-img-top {
                transform: scale(1.05);
            }

            .product-card .card-body {
                padding: 1.5rem;
            }

            .product-card .btn {
                border-radius: 25px;
                padding: 0.5rem 1.5rem;
            }
        </style>
    </head>
    <body>
        <!-- Navbar -->
        <nav class="navbar navbar-expand-lg navbar-light fixed-top">
            <div class="container">
                <a class="navbar-brand" href="#">
                    <img src="/logo.png" alt="haldeki.local Logo" height="50">
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="#">Ana Sayfa</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">Hakkımızda</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">İletişim</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-success text-white px-4" href="/login">Giriş Yap</a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Hero Section -->
        <section class="hero-section d-flex align-items-center">
            <div class="container text-center">
                <h1 class="display-3 fw-bold mb-4">Yeni Nesil Hal</h1>
                <p class="lead mb-4">Taze meyve ve sebzelerin dijital pazaryeri</p>
                <a href="/register" class="btn btn-success btn-lg px-5 py-3">Hemen Başla</a>
            </div>
        </section>

        <!-- Features Section -->
        <section class="py-5">
            <div class="container">
                <h2 class="text-center mb-5">Neden haldeki.local?</h2>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="card feature-card h-100 p-4">
                            <div class="text-center">
                                <i class="bi bi-truck feature-icon"></i>
                            </div>
                            <div class="card-body text-center">
                                <h5 class="card-title">Hızlı Teslimat</h5>
                                <p class="card-text">Siparişleriniz aynı gün içinde hazırlanır ve sevk edilir.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card feature-card h-100 p-4">
                            <div class="text-center">
                                <i class="bi bi-shield-check feature-icon"></i>
                            </div>
                            <div class="card-body text-center">
                                <h5 class="card-title">Kalite Garantisi</h5>
                                <p class="card-text">Tüm ürünler kalite kontrolünden geçirilir.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card feature-card h-100 p-4">
                            <div class="text-center">
                                <i class="bi bi-graph-up feature-icon"></i>
                            </div>
                            <div class="card-body text-center">
                                <h5 class="card-title">Rekabetçi Fiyatlar</h5>
                                <p class="card-text">Piyasadaki en uygun fiyatları sunuyoruz.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Cart Modal -->
        <div class="modal fade" id="cartModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Sepetim</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="cartItems"></div>
                        <div class="text-end mt-3">
                            <h5>Toplam: ₺<span id="cartTotal">0.00</span></h5>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                        <button type="button" class="btn btn-success" onclick="showOrderForm()">Sipariş Ver</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Form Modal -->
        <div class="modal fade" id="orderModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Sipariş Bilgileri</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="orderForm">
                            <div class="mb-3">
                                <label class="form-label">Restoran Adı</label>
                                <input type="text" class="form-control" name="restaurant[name]" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">E-posta</label>
                                <input type="email" class="form-control" name="restaurant[email]" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Telefon</label>
                                <input type="tel" class="form-control" name="restaurant[phone]" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Adres</label>
                                <textarea class="form-control" name="restaurant[address]" required></textarea>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                        <button type="button" class="btn btn-success" onclick="submitOrder()">Siparişi Tamamla</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products Section -->
        @php
            $response = Http::withoutVerifying()->get('https://haldeki.com/api/v1/products');
            $products = $response->json()['data']['data'] ?? [];
        @endphp

        <section class="py-5 bg-light">
            <div class="container">
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <h2>Günün Ürünleri</h2>
                    <button class="btn btn-success" onclick="showCart()">
                        Sepetim (<span id="cartCount">0</span>)
                    </button>
                </div>
                <div class="row g-4">
                    @foreach($products as $product)
                        <div class="col-md-4 mb-4">
                            <div class="card h-100 product-card">
                                <div class="card-body">
                                    <h5 class="card-title">{{ $product['name'] }}</h5>
                                    <p class="card-text text-muted">{{ substr($product['description'] ?? '', 0, 100) . '...' }}</p>
                                    
                                    @if(!empty($product['variants']))
                                        <div class="mb-3">
                                            <select class="form-select mb-2" id="variant-{{ $product['id'] }}">
                                                @foreach($product['variants'] as $variant)
                                                    <option value="{{ $variant['id'] }}" 
                                                            data-price="{{ $variant['average_price'] }}"
                                                            data-name="{{ $variant['name'] }}">
                                                        {{ $variant['name'] }} - ₺{{ number_format((float)$variant['average_price'], 2) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="d-flex align-items-center">
                                                <input type="number" class="form-control me-2" 
                                                       id="quantity-{{ $product['id'] }}" 
                                                       value="1" min="1">
                                                <button class="btn btn-success" 
                                                        onclick="addToCart({{ json_encode($product) }})">
                                                    Sepete Ekle
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-dark text-light py-4">
            <div class="container">
                <div class="row">
                    <div class="col-md-6">
                        <h5>haldeki.local</h5>
                        <p>Yeni nesil hal deneyimi için doğru adres.</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <p>© 2024 haldeki.local Tüm hakları saklıdır.</p>
                    </div>
                </div>
            </div>
        </footer>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            window.cart = [];

            window.addToCart = function(product) {
                const variantSelect = document.getElementById(`variant-${product.id}`);
                const quantityInput = document.getElementById(`quantity-${product.id}`);
                
                const variantId = variantSelect.value;
                const quantity = parseInt(quantityInput.value);
                const selectedOption = variantSelect.options[variantSelect.selectedIndex];
                const price = parseFloat(selectedOption.dataset.price);
                const variantName = selectedOption.dataset.name;

                const cartItem = {
                    product_id: product.id,
                    product_name: product.name,
                    product_variant_id: variantId,
                    variant_name: variantName,
                    quantity: quantity,
                    unit_price: price,
                    total_price: price * quantity
                };

                cart.push(cartItem);
                updateCartUI();
            };

            window.updateCartUI = function() {
                const cartCount = document.getElementById('cartCount');
                const cartItems = document.getElementById('cartItems');
                const cartTotal = document.getElementById('cartTotal');
                
                cartCount.textContent = cart.length;
                
                let total = 0;
                cartItems.innerHTML = cart.map(item => {
                    total += item.total_price;
                    return `
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h6 class="mb-0">${item.product_name}</h6>
                                <small class="text-muted">${item.variant_name} x ${item.quantity}</small>
                            </div>
                            <div class="text-end">
                                <div>₺${item.total_price.toFixed(2)}</div>
                                <button class="btn btn-sm btn-danger" onclick="removeFromCart(${cart.indexOf(item)})">
                                    Kaldır
                                </button>
                            </div>
                        </div>
                    `;
                }).join('');
                
                cartTotal.textContent = total.toFixed(2);
            };

            window.removeFromCart = function(index) {
                cart.splice(index, 1);
                updateCartUI();
            };

            window.showCart = function() {
                new bootstrap.Modal(document.getElementById('cartModal')).show();
            };

            window.showOrderForm = function() {
                if (cart.length === 0) {
                    alert('Lütfen sepete ürün ekleyin.');
                    return;
                }
                
                new bootstrap.Modal(document.getElementById('orderModal')).show();
            };

            window.submitOrder = function() {
                const formData = new FormData(document.getElementById('orderForm'));
                const orderData = {
                    restaurant: Object.fromEntries(formData.entries()),
                    items: cart.map(item => ({
                        product_variant_id: item.product_variant_id,
                        quantity: item.quantity,
                        unit_price: item.unit_price,
                        total_price: item.total_price
                    })),
                    total_amount: cart.reduce((sum, item) => sum + item.total_price, 0)
                };

                fetch('/api/v1/orders/restaurant', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(orderData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('Siparişiniz başarıyla oluşturuldu!');
                        cart = [];
                        updateCartUI();
                        bootstrap.Modal.getInstance(document.getElementById('orderModal')).hide();
                    } else {
                        alert('Bir hata oluştu: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Bir hata oluştu: ' + error.message);
                });
            };
        });
        </script>
    </body>
</html>
