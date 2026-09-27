# ZBY Taxi — Flutter Mobile Ilovasi uchun Backend API va Integratsiya Qo'llanmasi

Ushbu qo'llanma **Flutter (Haydovchi va Mijoz)** ilovasini ishlab chiquvchi dasturchilar guruhi uchun tayyorlangan.

---

## 1. Swagger va API Hujjatlari Havolalari

Backend to'liq interaktiv **Swagger UI (OpenAPI 3.0)** bilan ta'minlangan. Flutter jamoasi barcha endpointlarni brauzerda ochib, so'rovlarni jonli test qilib ko'rishi mumkin:

- 🌐 **Interaktiv Swagger UI (Brauzerda ochish):**
  - **`http://localhost:8000/swagger`**
  - **`http://localhost:8000/api/documentation`**
- 📄 **OpenAPI 3.0 JSON Spetsifikatsiyasi (Postman yoki Swagger Editor ga import qilish uchun):**
  - **`http://localhost:8000/docs/openapi.json`**
  - Fayl yo'li: `docs/openapi.json` yoki `backend/public/docs/openapi.json`

> **Eslatma (Android Emulator uchun):**  
> Android Emulator ichidan kompyuteringizdagi local serverga murojaat qilish uchun `localhost` o'rniga **`10.0.2.2:8000`** dan foydalaning (masalan: `http://10.0.2.2:8000/api`). Real telefonda test qilayotganda esa Wi-Fi IP manzilidan foydalaning (masalan: `http://192.168.1.X:8000/api`).

---

## 2. Autentifikatsiya (Login & Token)

Barcha haydovchi so'rovlari **Bearer Token** orqali himoyalangan (`Authorization: Bearer <token>`).

### 2.1 Haydovchi kirishi (Login)
- **URL:** `POST /api/auth/login`
- **Headers:** `Content-Type: application/json`, `Accept: application/json`
- **Request Body:**
```json
{
  "login": "+998976140201",
  "password": "password123"
}
```
- **Response (200 OK):**
```json
{
  "message": "Login successful",
  "token": "42|nSgW0qUfQe4cK...",
  "user": {
    "id": 4,
    "name": "Husniyor Azimboyev",
    "phone": "+998976140201",
    "role": "driver",
    "driver_profile": {
      "id": 1,
      "user_id": 4,
      "vehicle_type": "Nexia 3",
      "license_plate": "01A111AA",
      "status": "available",
      "current_lat": 41.311087,
      "current_lng": 69.240562
    }
  }
}
```
*Tokenni Flutter `SharedPreferences` yoki `flutter_secure_storage` da saqlab oling va keyingi barcha so'rovlarda `Authorization: Bearer <token>` sarlavhasini yuboring.*

---

## 3. Haydovchi Buyurtma Qabul Qilishining To'liq Sikli (Order Lifecycle)

Dispetcher yangi buyurtma yaratib (`Buyurtma 9`), uni haydovchiga biriktirganda Flutter ilovasi quyidagi 5 ta qadamni amalga oshiradi:

### 1-Qadam: Haydovchini Onlayn Qilish
Haydovchi ilovani ochib "Ishni boshlash" tugmasini bosganda:
- **URL:** `POST /api/driver/status`
- **Body:**
```json
{
  "status": "available"
}
```
*(Holatlar: `available` — bo'sh/onlayn, `busy` — band, `offline` — oflayn).*

---

### 2-Qadam: Yangi Buyurtmani Qabul Qilish (Polling / Real-time Sync)
Haydovchi ilovasi har 3–5 soniyada quyidagi sinxronlash endpointiga so'rov yuborib turadi:
- **URL:** `GET /api/driver/sync`
- **Headers:** `Authorization: Bearer <token>`
- **Response (Yangi buyurtma kelganda):**
```json
{
  "timestamp": "2026-09-27T07:48:11Z",
  "driver_status": "available",
  "pending_assignment": {
    "id": 40,
    "order_id": 41,
    "driver_id": 1,
    "status": "pending",
    "order": {
      "id": 41,
      "order_number": "Buyurtma 9",
      "customer_name": "Jasur Karimov",
      "customer_phone": "+998901234567",
      "pickup_address": "Amir Temur xiyoboni 12",
      "pickup_lat": 41.3111,
      "pickup_lng": 69.2405,
      "delivery_address": "Chilonzor metro 5-mavze",
      "delivery_lat": 41.3350,
      "delivery_lng": 69.2800,
      "total_amount": "15000.00",
      "status": "assigned"
    }
  },
  "active_order": null
}
```
*Agar `pending_assignment != null` bo'lsa, Flutter ekranda ovoz bilan yangi buyurtma dialogini (Bottom Sheet) chiqaradi!*

> **Muqobil endpoint:** Faqat kutilayotgan taklifni olish uchun `GET /api/driver/assignment/pending` endpointidan ham foydalanish mumkin.

---

### 3-Qadam: Buyurtmani Tasdiqlash yoki Rad Etish

#### A) Qabul qilish (Accept):
Haydovchi ekranda "Qabul qilish" tugmasini bosganda:
- **URL:** `POST /api/driver/assignments/{assignment_id}/accept`
*(Masalan: `POST /api/driver/assignments/40/accept`)*
- **Response (200 OK):**
```json
{
  "message": "Assignment accepted",
  "assignment": { "id": 40, "status": "accepted" },
  "order": {
    "id": 41,
    "order_number": "Buyurtma 9",
    "status": "driver_accepted"
  }
}
```
*(Buyurtma holati `driver_accepted` ga o'tadi, haydovchi holati avtomatik ravishda `busy` bo'ladi).*

#### B) Rad etish (Reject):
- **URL:** `POST /api/driver/assignments/{assignment_id}/reject`
- **Body:** `{ "reason": "Juda uzoqda" }`
*(Buyurtma boshqa haydovchiga taklif qilish uchun dispetcherga qaytariladi).*

---

### 4-Qadam: Safar Bosqichlarini Yangilash (Trip Progression)
Haydovchi safar davomida bosqichlarni quyidagi yagona endpoint orqali yangilab boradi:
- **URL:** `POST /api/driver/orders/{order_id}/status`
*(Masalan: `POST /api/driver/orders/41/status`)*

1. **Yo'lovchini olganda (Picked Up):**
```json
{
  "status": "picked_up",
  "remarks": "Mijoz mashinaga mindi"
}
```
2. **Safar davomida yo'lda (In Transit):**
```json
{
  "status": "in_transit",
  "remarks": "Manzil sari harakatlanmoqda"
}
```
3. **Manzilga yetib borganda va to'lov qilinganda (Delivered):**
```json
{
  "status": "delivered",
  "remarks": "Safar yakunlandi, to'lov naqd qabul qilindi"
}
```
*(Buyurtma tugagach, haydovchi avtomatik tarzda yana `available` (bo'sh) holatiga o'tadi va yangi buyurtmalarni qabul qilishga tayyor bo'ladi).*

---

### 5-Qadam: Jonli GPS Koordinatalarni Yuborish (Live Tracking & Taksimetr)
Flutter fonda (Background Service) yoki faol ilovada har 3-5 soniyada mashinaning GPS koordinatalarini yuborib turadi:
- **URL:** `POST /api/driver/location`
- **Headers:** `Authorization: Bearer <token>`
- **Request Body:**
```json
{
  "latitude": 41.311087,
  "longitude": 69.240562,
  "heading": 180.5,
  "speed": 42.0
}
```
- **Response (200 OK):**
```json
{
  "message": "Location updated successfully",
  "driver": {
    "current_lat": 41.311087,
    "current_lng": 69.240562,
    "total_distance_km": 15.2
  }
}
```
*Backend avtomatik ravishda mashinaning bosib o'tgan haqiqiy masofasini (km) hisoblab boradi va dispetcher xaritasida mashinaning burilish burchagi (heading) bilan birga jonli ko'rsatadi!*

---

## 4. Flutter Dart Kod Misoli (Order Sync & Polling)

```dart
import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;

class DriverApiService {
  static const String baseUrl = 'http://10.0.2.2:8000/api'; // Emulator uchun
  final String authToken;

  DriverApiService(this.authToken);

  // 1. Yangi buyurtma kelganini tekshirish (Sync)
  Future<Map<String, dynamic>?> checkNewOrders() async {
    final response = await http.get(
      Uri.parse('$baseUrl/driver/sync'),
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': 'Bearer $authToken',
      },
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['pending_assignment'] != null) {
        return data['pending_assignment'];
      }
    }
    return null;
  }

  // 2. Buyurtmani qabul qilish
  Future<bool> acceptAssignment(int assignmentId) async {
    final response = await http.post(
      Uri.parse('$baseUrl/driver/assignments/$assignmentId/accept'),
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': 'Bearer $authToken',
      },
    );
    return response.statusCode == 200;
  }

  // 3. Jonli GPS yuborish
  Future<void> sendGpsLocation(double lat, double lng, {double? speed, double? heading}) async {
    await http.post(
      Uri.parse('$baseUrl/driver/location'),
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': 'Bearer $authToken',
      },
      body: jsonEncode({
        'latitude': lat,
        'longitude': lng,
        'speed': speed,
        'heading': heading,
      }),
    );
  }
}
```

---

## 5. Test Uchun Mavjud Foydalanuvchilar

Flutter ilovasini test qilish uchun bazada tayyor haydovchilar mavjud:

| Role | Ismi | Login (Telefon) | Parol | Mashina |
| :--- | :--- | :--- | :--- | :--- |
| **Driver** | Husniyor Azimboyev | `+998976140201` | `password` | Nexia 3 (`01A111AA`) |
| **Driver** | Fariz Azimboyev | `+998976140202` | `password` | Lacetti (`01B222BB`) |
| **Driver** | David Vance | `+998901112233` | `password` | Malibu (`01M555MM`) |
| **Dispatcher / Admin** | Isfandiyor | `+998901234567` | `password` | — |

---

Barcha savollar bo'yicha Swagger hujjatiga (`http://localhost:8000/swagger`) murojaat qilishingiz yoki backend jamoasidan so'rashingiz mumkin.
