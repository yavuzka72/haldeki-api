@extends('layouts.app')

@section('title', 'Sipariş Tamamla')

@section('content')
<x-page-title 
    title="Sipariş Tamamla"
    :breadcrumbs="[
        ['title' => 'Sepetim', 'url' => route('cart.index')],
        ['title' => 'Sipariş Tamamla']
    ]"
/>

<div class="container py-5">
    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-4">Teslimat Bilgileri</h5>
                    <form id="checkoutForm" class="needs-validation" novalidate>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Ad Soyad</label>
                                <input type="text" class="form-control" name="name" id="name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefon</label>
                                <input type="tel" class="form-control" name="phone" id="phone"  required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="email" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Adres</label>
                                <textarea class="form-control" name="address" rows="3" id="address" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Sipariş Notu</label>
                                <textarea class="form-control" name="note" rows="2" id="note"></textarea>
                            </div>
                        </div>
                    </form>
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

                    <button type="submit" form="checkoutForm" class="btn btn-success w-100">
                        <i class="bi bi-credit-card me-2"></i>Ödemeye Geç
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PayTR iframe modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Güvenli Ödeme</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="paymentFrame" frameborder="0" scrolling="no" style="width: 100%; height: 900px;"></iframe>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('checkoutForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    console.log('test');
    const form = e.target;
    const submitButton = form.querySelector('button[type="submit"]');
    
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }

            // Form verilerini manuel olarak topla
            const formData = {
            name: document.getElementById('name').value,
            email: document.getElementById('email').value,
            phone: document.getElementById('phone').value,
            address: document.getElementById('address').value,
            note: document.getElementById('note').value
        };

        console.log(formData);

    try {
   

        const response = await fetch('/checkout/init-payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(formData)  
        });

        console.log(formData);
        const data = await response.json();

        if (response.ok) {
            // PayTR iframe'ini göster
            document.getElementById('paymentFrame').src = data.iframe_url;
            new bootstrap.Modal(document.getElementById('paymentModal')).show();
        } else {
            throw new Error(data.error || 'Bir hata oluştu');
        }

    } catch (error) {
        alert(error.message);
    } finally {
        console.log('test2');
    }
});
</script>
@endpush
@endsection 