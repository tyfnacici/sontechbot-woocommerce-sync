# sontechbot-woocommerce-sync
Exposes single &amp; bulk REST endpoints to get the product, update price &amp; stock by barcode (_original_id), with rate limiting and logging for not-found products.

# Sontechbot - WooCommerce Senkron Eklenti Dokümantasyonu

Bu doküman, "Sontechbot - WooCommerce Senkron" eklentisinin kurulumu, yapılandırılması ve kullanımı hakkında detaylı bilgi içerir. Eklenti, WooCommerce mağazanızdaki ürünleri harici sistemlerle (POS, ERP, envanter yönetimi yazılımları vb.) senkronize etmek için güvenli ve performanslı REST API uç noktaları sağlar.

## Genel Bakış

Eklentinin temel amacı, ürünleri standart WooCommerce ID'si yerine bir **barkod numarası** üzerinden sorgulamak ve güncellemek için bir köprü oluşturmaktır. Ürünleri barkod ile eşleştirmek için `_original_id` adında bir özel alan (meta field) kullanır.

### Ana Özellikler

*   **Barkod ile Ürün Sorgulama:** Barkod kullanarak tek bir ürünün detaylarını getirme.
*   **Toplu Güncelleme:** Tek bir API isteği ile 1000'e kadar ürünün fiyat ve stok bilgisini aynı anda güncelleme.
*   **İstek Sınırlandırma (Rate Limiting):** Sunucuyu aşırı yüklenmeye karşı korumak için IP başına dakikada 100 istek limiti.
*   **Hata Kaydı (Logging):** Sistemde bulunamayan ve güncellenmeye çalışılan barkodları kaydeden ve yönetici panelinde gösteren bir arayüz.
*   **Güvenlik:** Tüm uç noktalara yalnızca `manage_woocommerce` yetkisine sahip yönetici kullanıcılar erişebilir.
*   **Otomatik Güncelleme:** Eklentiyi doğrudan GitHub deposu üzerinden güncelleme imkanı.

## Kurulum ve Ön Gereksinimler

1.  Eklentiyi standart bir WordPress eklentisi gibi `.zip` dosyasından yükleyin ve etkinleştirin.
2.  **En Önemli Adım:** Eklentinin çalışabilmesi için güncellenecek her ürünün (veya ürün varyasyonunun) **`_original_id`** adında bir özel alanına (custom field/meta field) sahip olması ve bu alanın değerinin o ürüne ait **benzersiz barkod** olması gerekmektedir. Bu eşleştirme olmadan eklenti ürünleri bulamaz.

## Yetkilendirme

API uç noktaları korumalıdır ve yetkisiz erişime kapalıdır. İstek yapabilmek için, `manage_woocommerce` yetkisine sahip bir kullanıcının kimlik bilgileriyle kimlik doğrulaması yapılmalıdır.

Bunun için en güvenli ve tavsiye edilen yöntem WordPress'in **Uygulama Şifreleri (Application Passwords)** özelliğini kullanmaktır.

1.  WordPress Admin Panelinde **Kullanıcılar -> Profil** bölümüne gidin.
2.  Sayfanın altında "Uygulama Şifreleri" bölümünü bulun.
3.  Yeni bir uygulama adı girin (örn: "SontechPOS") ve "Yeni Uygulama Şifresi Ekle" butonuna tıklayın.
4.  Oluşturulan şifreyi (`sifre` formatında) kopyalayın. Bu şifre **sadece bir kez** gösterilir.
5.  API isteklerinizde **Basic Auth** kullanarak bu şifreyi gönderin. Kullanıcı adı olarak yönetici kullanıcı adınızı, şifre olarak ise bu uygulama şifresini kullanın.

## API Uç Noktaları (Endpoints)

Tüm uç noktalar sitenizin URL'sinin sonuna `/wp-json/sontechbot/` eklenerek erişilebilir.

---

### 1. Ürün Bilgisi Getirme

Barkod ile tek bir ürünün temel bilgilerini getirir.

*   **Metot:** `GET`
*   **URL:** `/get-product`
*   **Parametreler:**
    *   `barcode` (zorunlu): Bilgileri istenen ürünün barkodu.
*   **Örnek İstek (cURL):**
    ```bash
    curl -X GET "https://siteniz.com/wp-json/sontechbot/get-product?barcode=8691234567890" \
    -u "yonetici_kullanici_adi:sifre"
    ```
*   **Başarılı Yanıt (200 OK):**
    ```json
    {
        "id": 123,
        "barcode": "8691234567890",
        "name": "Örnek Ürün",
        "sku": "ORN-001",
        "regular_price": "99.90",
        "stock_quantity": 50,
        "manage_stock": true,
        "is_in_stock": true
    }
    ```
*   **Hata Yanıtı (404 Not Found):**
    ```json
    {
        "code": "not_found",
        "message": "No product found with barcode 8691234567890",
        "data": { "status": 404 }
    }
    ```

---

### 2. Toplu Fiyat ve Stok Güncelleme

Birden fazla ürünün fiyat ve stok bilgisini tek bir istekte günceller. Bu, senkronizasyon için en verimli yöntemdir.

*   **Metot:** `POST`
*   **URL:** `/sync`
*   **Body (JSON):**
    *   `items`: Ürün bilgilerini içeren bir array. Her bir obje şunları içermelidir:
        *   `barcode` (zorunlu): Ürünün barkodu.
        *   `quantity` (zorunlu): Yeni stok miktarı.
        *   `sellingPrice` (zorunlu): Yeni satış fiyatı (`regular_price`).
*   **Örnek İstek (cURL):**
    ```bash
    curl -X POST "https://siteniz.com/wp-json/sontechbot/sync" \
    -u "yonetici_kullanici_adi:sifre" \
    -H "Content-Type: application/json" \
    -d '{
      "items": [
        {
          "barcode": "8691234567890",
          "quantity": 45,
          "sellingPrice": 95.50
        },
        {
          "barcode": "8690987654321",
          "quantity": 120,
          "sellingPrice": 149.99
        },
        {
          "barcode": "BU-BARKOD-YOK-00",
          "quantity": 10,
          "sellingPrice": 10.0
        }
      ]
    }'
    ```
*   **Başarılı Yanıt (200 OK):**
    Yanıt, hangi ürünlerin başarılı bir şekilde güncellendiğini ve hangilerinin başarısız olduğunu gösteren bir rapor içerir.
    ```json
    {
        "success": [
            {
                "index": 0,
                "barcode": "8691234567890",
                "id": 123
            },
            {
                "index": 1,
                "barcode": "8690987654321",
                "id": 456
            }
        ],
        "failed": [
            {
                "index": 2,
                "barcode": "BU-BARKOD-YOK-00",
                "error": "Product not found."
            }
        ]
    }
    ```

---

### 3. Tekil Ürün Güncelleme

Tek bir ürünün fiyat ve stok bilgisini günceller. `/sync` endpoint'inin tek bir item ile kullanılmasına benzer.

*   **Metot:** `POST`
*   **URL:** `/update`
*   **Body (JSON):**
    *   `barcode` (zorunlu): Ürünün barkodu.
    *   `regular_price` (zorunlu): Yeni satış fiyatı.
    *   `stock_quantity` (zorunlu): Yeni stok miktarı.
*   **Örnek İstek (cURL):**
    ```bash
    curl -X POST "https://siteniz.com/wp-json/sontechbot/update" \
    -u "yonetici_kullanici_adi:sifre" \
    -H "Content-Type: application/json" \
    -d '{
      "barcode": "8691234567890",
      "regular_price": 92.00,
      "stock_quantity": 40
    }'
    ```
*   **Başarılı Yanıt (200 OK):**
    ```json
    {
        "id": 123,
        "regular_price": "92.00",
        "stock_quantity": 40
    }
    ```

## İstek Sınırlandırma (Rate Limiting)

Sunucuya aşırı yük bindirilmesini önlemek için tüm API uç noktalarında bir sınırlandırma mevcuttur.

*   **Limit:** `100` istek / `60` saniye (IP adresi başına).
*   Limit aşıldığında, API `429 Too Many Requests` durum kodu ile bir hata döndürür.
*   Her yanıtta aşağıdaki HTTP başlıkları (headers) gönderilir, böylece kalan istek hakkınızı takip edebilirsiniz:
    *   `X-RateLimit-Limit`: Toplam istek limiti (100).
    *   `X-RateLimit-Remaining`: Mevcut periyotta kalan istek hakkı.
    *   `X-RateLimit-Reset`: Limitin sıfırlanmasına kalan saniye.

## Yönetim Paneli: Bulunamayan Barkodlar

Eklenti, `/sync` uç noktası üzerinden güncellenmeye çalışılan ancak veritabanında bulunamayan barkodları kaydeder. Bu kayıtları görmek ve yönetmek için:

1.  WordPress Admin Panelinde sol menüden **Bulunamayan Barkodlar**'a tıklayın.
2.  Bu sayfada, sistemde mevcut olmayan tüm barkodların bir listesini görebilirsiniz. Bu liste, harici sisteminiz ile WooCommerce arasındaki veri tutarsızlıklarını tespit etmenize yardımcı olur.
3.  **"Logları Temizle"** butonuna basarak bu listeyi silebilirsiniz.

## Eklentiyi Kaldırma

Eklentiyi WordPress yönetim panelinden sildiğinizde, veritabanına eklediği `wcbss_not_found_barcodes_log` seçeneğini otomatik olarak temizler. Veritabanında gereksiz veri bırakmaz.
