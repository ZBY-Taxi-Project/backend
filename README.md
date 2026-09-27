# ⚙️ ZBY Taxi Backend API (Laravel 12)

REST API xizmati, buyurtmalar navbati (dispatching), haydovchilar hisob-kitobi va autentifikatsiya tizimi.

## 🚀 Texnologiyalar
- **Laravel 12** (PHP 8.2+)
- **PostgreSQL / SQLite**
- **Laravel Sanctum** (Token-based authentication)
- **RBAC (Role Based Access Control)**: Admin, Dispatcher, Driver

## 🛠️ O'rnatish
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8000
```

## 🧪 Testlarni ishga tushirish
```bash
php artisan test
```

## 📑 API Endpointlari
- `POST /api/auth/login` - Tizimga kirish (admin, dispatcher, driver)
- `GET /api/dispatcher/sync` - Realtime polling sinxronizatsiyasi
- `GET/POST /api/dispatcher/orders` - Buyurtmalar yaratish va boshqarish
- `GET/POST /api/dispatcher/drivers` - Haydovchilarni boshqarish
- `DELETE /api/dispatcher/drivers/{id}` - Haydovchini o'chirish (Admin)
- `POST /api/dispatcher/drivers/{id}/topup` - Balans to'ldirish
- `GET /api/dispatcher/permissions` - Ruxsatlar matritsasi (Admin)
