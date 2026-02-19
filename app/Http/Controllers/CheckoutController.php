<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    private function validatePayTRConfig()
    {
        $required = ['merchant_id', 'merchant_key', 'merchant_salt'];
        foreach ($required as $key) {
            if (empty(config('paytr.' . $key))) {
                Log::error('PayTR: Eksik konfigurasyon', ['missing_key' => $key]);
                return false;
            }
        }
        return true;
    }

    public function index()
    {
        $cart = session()->get('cart', ['items' => [], 'total' => 0]);
        
        if (empty($cart['items'])) {
            return redirect()->route('cart.index')
                ->with('error', 'Sepetiniz boş');
        }

        return view('checkout.index', compact('cart'));
    }

    public function initPayment(Request $request)
    {
        try {

            
            $merchant_id = 550857;
            $merchant_key = "Pxk4yp5jGx5iU73N";
            $merchant_salt = "eEhdxuR7drQPEGwp";

            // Form validasyonu - PayTR karakter limitlerine göre
            try {
                $validated = $request->validate([
                    'email' => 'required|email|max:100',
                    'name' => 'required|string|max:60',
                    'phone' => 'required|string|max:20',
                    'address' => 'required|string|max:400'
                ], [
                    'email.required' => 'E-posta adresi zorunludur',
                    'email.email' => 'Geçerli bir e-posta adresi giriniz',
                    'email.max' => 'E-posta adresi en fazla 100 karakter olabilir',
                    'name.required' => 'Ad Soyad zorunludur',
                    'name.max' => 'Ad Soyad en fazla 60 karakter olabilir',
                    'phone.required' => 'Telefon numarası zorunludur',
                    'phone.max' => 'Telefon numarası en fazla 20 karakter olabilir',
                    'address.required' => 'Adres zorunludur',
                    'address.max' => 'Adres en fazla 400 karakter olabilir'
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::warning('PayTR: Form validasyon hatası', [
                    'errors' => $e->errors(),
                    'input' => $request->except(['name', 'phone', 'address']) // Hassas bilgileri loglama
                ]);
                
                return response()->json([
                    'error' => 'Lütfen tüm zorunlu alanları doldurun',
                    'validation_errors' => $e->errors()
                ], 422);
            }

            // Debug log - gelen verileri kontrol et
            Log::debug('PayTR: Form verileri alındı', [
                'has_email' => !empty($request->email),
                'has_name' => !empty($request->name),
                'has_phone' => !empty($request->phone),
                'has_address' => !empty($request->address)
            ]);

            if (!$this->validatePayTRConfig()) {
                return response()->json(['error' => 'Ödeme sistemi yapılandırması eksik'], 500);
            }

            $cart = session()->get('cart');
            if (empty($cart['items'])) {
                Log::warning('PayTR: Boş sepet ile ödeme denemesi yapıldı');
                return response()->json(['error' => 'Sepetiniz boş'], 400);
            }

            // Gerçek IP adresini al
            $userIp = $request->header('X-Forwarded-For') ?? $request->ip();
            if ($userIp === '127.0.0.1') {
                // Lokal test durumunda gerçek IP kullan
                $userIp = file_get_contents('https://api.ipify.org');
            }

            // Sipariş numarası oluştur (max 64 karakter)
            $orderNumber = 'ORD-' . strtoupper(Str::random(8));

            // Sepet içeriğini hazırla
            $basketItems = [];
            foreach ($cart['items'] as $item) {
                $basketItems[] = [
                    $item['name'] . ' - ' . $item['variant_name'],
                    number_format($item['price'], 2, '.', ''),
                    $item['quantity']
                ];
            }

            // PayTR için gerekli parametreleri hazırla
            $params = [
                'merchant_id' => $merchant_id,
                'user_ip' => substr($userIp, 0, 39), // IPv4 limit
                'merchant_oid' => $merchant_id,
                'email' => $validated['email'],
                'payment_amount' => (int)($cart['total'] * 100), // Kuruş cinsinden
                'paytr_token' => '',
                'user_basket' => base64_encode(json_encode($basketItems)),
                'no_installment' => 1,
                'max_installment' => 0,
                'user_name' => $validated['name'],
                'user_phone' => $validated['phone'],
                'user_address' => $validated['address'],
                'merchant_ok_url' => route('checkout.success'),
                'merchant_fail_url' => route('checkout.cancel'),
                'timeout_limit' => 30,
                'currency' => 'TL',
                'test_mode' => 1,
                'debug_on' => 1,
                'lang' => 'tr'
            ];

            // Token oluştur - PayTR dokümantasyonuna göre
            $hashStr = implode('', [
                $params['merchant_id'],
                $params['user_ip'],
                $params['merchant_oid'],
                $params['email'],
                $params['payment_amount'],
                $params['user_basket'],
                $params['no_installment'],
                $params['max_installment'],
                $params['currency'],
                $params['test_mode']
            ]) . config('paytr.merchant_salt');

            $token = base64_encode(hash_hmac('sha256', $hashStr, config('paytr.merchant_key'), true));
            $params['paytr_token'] = $token;

            // API isteği öncesi log
            Log::info('PayTR: API isteği başlatılıyor', [
                'order_number' => $orderNumber,
                'amount' => $params['payment_amount'],
                'merchant_id' => $params['merchant_id'],
                'user_ip' => $params['user_ip']
            ]);

            // SSL doğrulamasını devre dışı bırakarak isteği gönder
            $response = Http::withOptions([
                'timeout' => 30,
            ])->withoutVerifying()
              ->asForm() // application/x-www-form-urlencoded
              ->post('https://www.paytr.com/odeme/api/get-token', $params);

            // HTTP yanıt durumunu kontrol et
            Log::info('PayTR: API yanıtı alındı', [
                'status_code' => $response->status(),
                'body' => $response->body()
            ]);

            if (!$response->successful()) {
                Log::error('PayTR API Hatası', [
                    'status_code' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json([
                    'error' => 'Ödeme sistemi şu anda kullanılamıyor',
                    'details' => 'HTTP ' . $response->status()
                ], 500);
            }

            $result = $response->json();
            
            if (!is_array($result) || !isset($result['status'])) {
                Log::error('PayTR: Geçersiz API yanıtı', ['response' => $response->body()]);
                return response()->json(['error' => 'Ödeme sistemi yanıtı geçersiz'], 500);
            }

            if ($result['status'] === 'success' && isset($result['token'])) {
                // Sipariş bilgilerini session'da sakla
                session()->put('pending_order', [
                    'order_number' => $orderNumber,
                    'shipping_address' => $validated['address'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'],
                    'note' => $request->note,
                    'cart' => $cart
                ]);

                Log::info('PayTR: Ödeme başlatıldı', ['order_number' => $orderNumber]);

                return response()->json([
                    'token' => $result['token'],
                    'iframe_url' => "https://www.paytr.com/odeme/guvenli/" . $result['token']
                ]);
            }

            Log::warning('PayTR: İşlem başarısız', ['reason' => $result['reason'] ?? 'Bilinmiyor']);
            return response()->json(['error' => $result['reason'] ?? 'Ödeme işlemi başlatılamadı'], 400);

        } catch (\Exception $e) {
            Log::error('PayTR Error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response()->json(['error' => 'Ödeme sistemi şu anda kullanılamıyor. Lütfen daha sonra tekrar deneyin.'], 500);
        }
    }

    public function success(Request $request)
    {
        $pendingOrder = session()->get('pending_order');
        if (!$pendingOrder) {
            return redirect()->route('cart.index');
        }

        // Siparişi API'ye gönder
        $response = Http::withoutVerifying()
            ->post('https://haldeki.com/api/v1/orders', [
                'order_number' => $pendingOrder['order_number'],
                'shipping_address' => $pendingOrder['shipping_address'],
                'phone' => $pendingOrder['phone'],
                'email' => $pendingOrder['email'],
                'note' => $pendingOrder['note'],
                'items' => collect($pendingOrder['cart']['items'])->map(function($item) {
                    return [
                        'product_variant_id' => $item['variant_id'],
                        'quantity' => $item['quantity'],
                    ];
                })->values()->toArray()
            ]);

        if ($response->successful()) {
            // Sepeti ve bekleyen siparişi temizle
            session()->forget(['cart', 'pending_order']);
            
            return redirect()->route('orders.show', $response->json()['data']['id'])
                ->with('success', 'Siparişiniz başarıyla oluşturuldu!');
        }

        return redirect()->route('cart.index')
            ->with('error', 'Sipariş oluşturulurken bir hata oluştu.');
    }

    public function cancel()
    {
        session()->forget('pending_order');
        return redirect()->route('cart.index')
            ->with('error', 'Ödeme işlemi iptal edildi.');
    }

    public function notification(Request $request)
    {
        try {
            Log::info('PayTR: Bildirim alındı', $request->all());

            // Gelen parametreleri al
            $merchant_oid = $request->merchant_oid;
            $status = $request->status;
            $total_amount = $request->total_amount;
            $hash = $request->hash;

            // Hash'i doğrula
            $hash_str = $merchant_oid . config('paytr.merchant_salt') . $status . $total_amount;
            $hash_check = base64_encode(hash_hmac('sha256', $hash_str, config('paytr.merchant_key'), true));

            if ($hash != $hash_check) {
                Log::error('PayTR: Hash doğrulama hatası', [
                    'received_hash' => $hash,
                    'calculated_hash' => $hash_check
                ]);
                return response('PAYTR notification failed: hash check', 400);
            }

            // Siparişi bul
            $pendingOrder = session()->get('pending_order');
            if (!$pendingOrder || $pendingOrder['order_number'] !== $merchant_oid) {
                Log::error('PayTR: Sipariş bulunamadı', [
                    'merchant_oid' => $merchant_oid,
                    'status' => $status
                ]);
                return response('PAYTR notification failed: order not found', 400);
            }

            if ($status == 'success') {
                try {
                    // Siparişi API'ye gönder
                    $response = Http::withoutVerifying()
                        ->post('https://haldeki.com/api/v1/orders', [
                            'order_number' => $pendingOrder['order_number'],
                            'shipping_address' => $pendingOrder['shipping_address'],
                            'phone' => $pendingOrder['phone'],
                            'email' => $pendingOrder['email'],
                            'note' => $pendingOrder['note'],
                            'payment_status' => 'completed',
                            'items' => collect($pendingOrder['cart']['items'])->map(function($item) {
                                return [
                                    'product_variant_id' => $item['variant_id'],
                                    'quantity' => $item['quantity'],
                                ];
                            })->values()->toArray()
                        ]);

                    if ($response->successful()) {
                        // Sepeti ve bekleyen siparişi temizle
                        session()->forget(['cart', 'pending_order']);
                        Log::info('PayTR: Sipariş başarıyla tamamlandı', [
                            'order_number' => $merchant_oid
                        ]);
                    } else {
                        throw new \Exception('API yanıtı başarısız: ' . $response->body());
                    }
                } catch (\Exception $e) {
                    Log::error('PayTR: Sipariş oluşturma hatası', [
                        'error' => $e->getMessage(),
                        'order_number' => $merchant_oid
                    ]);
                    return response('PAYTR notification failed: order creation error', 400);
                }
            } else {
                Log::warning('PayTR: Başarısız ödeme', [
                    'merchant_oid' => $merchant_oid,
                    'status' => $status
                ]);
                session()->forget('pending_order');
            }

            return response('OK');

        } catch (\Exception $e) {
            Log::error('PayTR Notification Error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response('PAYTR notification failed: system error', 500);
        }
    }
} 