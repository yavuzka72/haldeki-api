# haldeki.local - Mevcut Durum ve API Planı

## Tamamlanan Özellikler

### Kullanıcı Sistemi
1. ✅ Üç seviyeli yetkilendirme:
   - Süper Admin (admin_level=1)
   - Satıcı (admin_level=2)
   - Müşteri (admin_level=0)
2. ✅ Kullanıcı yönetimi (admin panel)
3. ✅ Rol bazlı erişim kontrolü

### Ürün Yönetimi
1. ✅ Ürün ve varyant yönetimi
2. ✅ Satıcı-ürün ilişkisi
3. ✅ Satıcı bazlı fiyatlandırma
4. ✅ Aktiflik durumu kontrolü
5. ✅ Görsel yükleme desteği

### Sipariş Sistemi
1. ✅ Sepet yapısı
2. ✅ Sipariş oluşturma
3. ✅ Otomatik sipariş numarası
4. ✅ Sipariş durumu takibi
5. ✅ Ödeme durumu takibi

### Admin Panel (Filament)
1. ✅ Süper Admin Yetkileri:
   - Tüm modüllere tam erişim
   - Kullanıcı yönetimi
   - Ürün-satıcı ilişkisi yönetimi
2. ✅ Satıcı Yetkileri:
   - Kendine atanan ürünleri görüntüleme
   - Fiyat girişi ve yönetimi
   - Kendi siparişlerini görüntüleme

## API Planı

### Gerekli Endpointler

1. Kimlik Doğrulama
   - [ ] Kayıt olma
   - [ ] Giriş yapma
   - [ ] Şifre sıfırlama

2. Ürün İşlemleri
   - [ ] Ürün listesi
   - [ ] Ürün detayı
   - [ ] Varyant listesi
   - [ ] Fiyat listesi
   - [ ] Satıcı bazlı ürünler

3. Sepet İşlemleri
   - [ ] Sepet oluşturma
   - [ ] Ürün ekleme
   - [ ] Ürün çıkarma
   - [ ] Miktar güncelleme
   - [ ] Sepet özeti

4. Sipariş İşlemleri
   - [ ] Sipariş oluşturma
   - [ ] Sipariş listesi
   - [ ] Sipariş detayı
   - [ ] Sipariş durumu güncelleme

### API Güvenliği
1. [ ] Sanctum token authentication
2. [ ] Rate limiting
3. [ ] Input validation
4. [ ] Error handling
5. [ ] API documentation

### Özel Gereksinimler
1. [ ] Satıcı bazlı fiyat filtreleme
2. [ ] Ürün arama ve filtreleme
3. [ ] Sipariş geçmişi
4. [ ] Fiyat geçmişi
5. [ ] Bildirim sistemi

## Sonraki Adımlar
1. API Routes oluşturma
2. Controller yapısını kurma
3. Resource sınıflarını hazırlama
4. Validation kurallarını belirleme
5. Postman collection hazırlama 