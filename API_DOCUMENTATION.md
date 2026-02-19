# haldeki.local API Dokümantasyonu

## Genel Bilgiler

### Base URL
```http
https://haldeki.com/api/v1
```

### Kimlik Doğrulama
- API Bearer token kullanmaktadır
- Token'ı login veya register endpoint'lerinden alabilirsiniz
- Token'ı tüm isteklerde header'da göndermelisiniz:
```http
Authorization: Bearer {your_token}
```

### Response Format

Başarılı yanıt:
```json
{
    "status": "success",
    "message": "İşlem başarılı mesajı (opsiyonel)",
    "data": {
        // Response verisi
    }
}
```

Hata yanıtı:
```json
{
    "status": "error",
    "message": "Hata mesajı"
}
```

## Endpoints

### 1. Kimlik Doğrulama (Authentication)

#### 1.1. Kayıt Olma
```http
POST /register
```

Request body:
```json
{
    "name": "Test Kullanıcı",
    "email": "test@example.com",
    "password": "12345678",
    "password_confirmation": "12345678"
}
```

Response (201):
```json
{
    "access_token": "1|abcdef123...",
    "token_type": "Bearer",
    "user": {
        "id": 1,
        "name": "Test Kullanıcı",
        "email": "test@example.com"
    }
}
```

#### 1.2. Giriş Yapma
```http
POST /login
```

Request body:
```json
{
    "email": "test@example.com",
    "password": "12345678"
}
```

Response (200):
```json
{
    "access_token": "2|xyz789...",
    "token_type": "Bearer",
    "user": {
        "id": 1,
        "name": "Test Kullanıcı",
        "email": "test@example.com"
    }
}
```

#### 1.3. Çıkış Yapma
```http
POST /logout
```

Headers:
```http
Authorization: Bearer {token}
```

Response (200):
```json
{
    "message": "Başarıyla çıkış yapıldı"
}
```

### 2. Ürün İşlemleri (Products)

#### 2.1. Ürün Listesi
```http
GET /products
```

Query Parameters:
- `search`: Ürün adına göre arama (opsiyonel)
- `page`: Sayfa numarası (opsiyonel)

Response (200):
```json
{
    "status": "success",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "Ürün Adı",
                "description": "Ürün açıklaması",
                "active": true,
                "variants": [
                    {
                        "id": 1,
                        "name": "Varyant Adı",
                        "active": true
                    }
                ]
            }
        ],
        "total": 100
    }
}
```

#### 2.2. Ürün Detayı
```http
GET /products/{product_id}
```

Response (200):
```json
{
    "status": "success",
    "data": {
        "id": 1,
        "name": "Ürün Adı",
        "description": "Ürün açıklaması",
        "active": true,
        "variants": [
            {
                "id": 1,
                "name": "Varyant Adı",
                "active": true
            }
        ]
    }
}
```

#### 2.3. Ürün Varyantları
```http
GET /products/{product_id}/variants
```

Response (200):
```json
{
    "status": "success",
    "data": [
        {
            "id": 1,
            "name": "Varyant Adı",
            "active": true,
            "prices": [
                {
                    "id": 1,
                    "price": 15.50,
                    "active": true
                }
            ]
        }
    ]
}
```

#### 2.4. Ürün Fiyatları
```http
GET /products/{product_id}/prices
```

Response (200):
```json
{
    "status": "success",
    "data": [
        {
            "variant_id": 1,
            "variant_name": "Varyant Adı",
            "prices": [
                {
                    "price": 15.50,
                    "seller": "Satıcı Adı"
                }
            ]
        }
    ]
}
```

### 3. Sepet İşlemleri (Cart)

#### 3.1. Sepeti Görüntüleme
```http
GET /cart
```

Headers:
```http
Authorization: Bearer {token}
```

Response (200):
```json
{
    "status": "success",
    "data": {
        "id": 1,
        "total_amount": 31.00,
        "items": [
            {
                "id": 1,
                "quantity": 2,
                "unit_price": 15.50,
                "total_price": 31.00,
                "product_variant": {
                    "id": 1,
                    "name": "Varyant Adı",
                    "product": {
                        "id": 1,
                        "name": "Ürün Adı"
                    }
                },
                "seller": {
                    "id": 1,
                    "name": "Satıcı Adı"
                }
            }
        ]
    }
}
```

#### 3.2. Sepete Ürün Ekleme
```http
POST /cart/items
```

Headers:
```http
Authorization: Bearer {token}
```

Request body:
```json
{
    "product_variant_id": 1,
    "seller_id": 1,
    "quantity": 2,
    "unit_price": 15.50
}
```

Response (200): Sepet verisi döner (3.1'deki gibi)

#### 3.3. Sepetteki Ürünü Güncelleme
```http
PUT /cart/items/{item_id}
```

Headers:
```http
Authorization: Bearer {token}
```

Request body:
```json
{
    "quantity": 3
}
```

Response (200): Sepet verisi döner (3.1'deki gibi)

#### 3.4. Sepetten Ürün Silme
```http
DELETE /cart/items/{item_id}
```

Headers:
```http
Authorization: Bearer {token}
```

Response (200): Sepet verisi döner (3.1'deki gibi)

### 4. Sipariş İşlemleri (Orders)

#### 4.1. Sipariş Listesi
```http
GET /orders
```

Headers:
```http
Authorization: Bearer {token}
```

Response (200):
```json
{
    "status": "success",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "total_amount": 31.00,
                "status": "pending",
                "payment_status": "pending",
                "note": "Sipariş notu",
                "items": [
                    {
                        "id": 1,
                        "quantity": 2,
                        "unit_price": 15.50,
                        "total_price": 31.00,
                        "status": "pending",
                        "product_variant": {
                            "id": 1,
                            "name": "Varyant Adı"
                        },
                        "seller": {
                            "id": 1,
                            "name": "Satıcı Adı"
                        }
                    }
                ]
            }
        ],
        "total": 10
    }
}
```

#### 4.2. Sipariş Oluşturma
```http
POST /orders
```

Headers:
```http
Authorization: Bearer {token}
```

Request body:
```json
{
    "note": "Lütfen öğleden sonra teslimat yapın"  // opsiyonel
}
```

Response (201): Oluşturulan sipariş verisi döner

#### 4.3. Sipariş Detayı
```http
GET /orders/{order_id}
```

Headers:
```http
Authorization: Bearer {token}
```

Response (200): Sipariş verisi döner (4.1'deki tek sipariş formatında)

## Hata Kodları

- `400`: Geçersiz istek
- `401`: Yetkisiz erişim
- `403`: Yasaklı erişim
- `404`: Kaynak bulunamadı
- `422`: Validasyon hatası
- `500`: Sunucu hatası

## Test Senaryosu

1. `/register` ile yeni bir kullanıcı oluşturun
2. Aldığınız token'ı kaydedin
3. `/products` ile ürünleri listeleyin
4. Seçtiğiniz bir ürünün varyantlarını ve fiyatlarını görüntüleyin
5. Sepete ürün ekleyin
6. Sepeti görüntüleyin
7. Siparişi tamamlayın
8. Sipariş listesini görüntüleyin
```
