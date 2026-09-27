# ⚙️ ZBY Taxi Backend API (Laravel 12)

REST API xizmati, buyurtmalar navbati (dispatching), haydovchilar hisob-kitobi va autentifikatsiya tizimi.

## 🚀 Texnologiyalar
- **Laravel 12** (PHP 8.2+)
- **PostgreSQL 16 (Docker)** / SQLite
- **Laravel Sanctum** (Token-based authentication)
- **RBAC (Role Based Access Control)**: Admin, Dispatcher, Driver

---

## 🐳 Docker orqali Ma'lumotlar Bazasini Sozlash

Loyihada PostgreSQL ma'lumotlar bazasi Docker orqali avtomatlashtirilgan.

### 1. Baza konteynerini ishga tushirish:
```bash
docker compose up -d
```
> Bu buyruq `zby-postgres` konteynerini yaratadi va orqa fonda (background) ishga tushiradi (`port: 5432`). Ma'lumotlar `zby_pgdata` Docker volumeda doimiy saqlanadi.

### 2. Konteyner holatini tekshirish:
```bash
docker compose ps
```

### 3. Konteynerni to'xtatish:
```bash
docker compose down
```

---

## 🛠️ Loyihani O'rnatish va Ishga Tushirish

```bash
# 1. Bog'liqliklarni o'rnatish
composer install

# 2. .env faylini yaratish
cp .env.example .env
php artisan key:generate

# 3. Docker orqali PostgreSQL bazasini yoqish
docker compose up -d

# 4. Migratsiyalar va standart ma'lumotlarni yuklash
php artisan migrate --seed

# 5. Serverni ishga tushirish
php artisan serve --port=8000
```

---

## 🧪 Testlarni ishga tushirish
```bash
php artisan test
```

## 📑 Asosiy API Endpointlari
- `POST /api/auth/login` - Tizimga kirish (admin, dispatcher, driver)
- `GET /api/dispatcher/sync` - Realtime polling sinxronizatsiyasi
- `GET/POST /api/dispatcher/orders` - Buyurtmalar yaratish va boshqarish
- `GET/POST /api/dispatcher/drivers` - Haydovchilarni boshqarish
- `DELETE /api/dispatcher/drivers/{id}` - Haydovchini o'chirish (Admin)
- `POST /api/dispatcher/drivers/{id}/topup` - Balans to'ldirish
- `GET /api/dispatcher/permissions` - Ruxsatlar matritsasi (Admin)
