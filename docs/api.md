# ZBY Delivery Platform: REST API Reference

All requests must supply `Accept: application/json` and `Authorization: Bearer <token>` when authenticated.

---

## 1. Authentication (`/api/auth`)

### `POST /api/auth/login`
Authenticates a user and issues a Sanctum token.
* **Payload:**
```json
{
  "email": "dispatcher@zby.test",
  "password": "password"
}
```
* **Response (200 OK):**
```json
{
  "message": "Login successful",
  "token": "1|sanctum_plain_text_token...",
  "user": {
    "id": 2,
    "name": "Sarah Connor",
    "email": "dispatcher@zby.test",
    "role": "dispatcher",
    "driver_profile": null
  }
}
```

### `GET /api/auth/me`
Returns details of the currently authenticated user.

### `POST /api/auth/logout`
Revokes the bearer token currently in use.

---

## 2. Dispatcher Endpoints (`/api/dispatcher/*`)
*Guarded by Sanctum + `role:dispatcher,admin`*

### `GET /api/dispatcher/orders`
List orders with status counts.
* **Query Parameters:**
  - `status`: filter by status (`pending_dispatch`, `assigned`, etc.)
  - `search`: search query (customer, order #, address)
  - `per_page`: pagination size (default: 25)

### `POST /api/dispatcher/orders`
Create a new delivery order.
* **Payload:**
```json
{
  "customer_name": "Alisher Navoi",
  "customer_phone": "+998901234567",
  "pickup_address": "Amir Timur Street 14",
  "delivery_address": "Chilanzar District 5",
  "total_amount": 35.00,
  "notes": "Fragile items"
}
```

### `GET /api/dispatcher/orders/{id}`
Returns full order detail with assignment attempts and audit history.

### `POST /api/dispatcher/orders/{id}/assign`
Assigns an order to an available driver.
* **Payload:**
```json
{
  "driver_id": 1
}
```

### `POST /api/dispatcher/orders/{id}/reassign`
Reassigns an order to a different driver.
* **Payload:**
```json
{
  "driver_id": 2,
  "reason": "Driver 1 vehicle issue"
}
```

### `POST /api/dispatcher/orders/{id}/cancel-assignment`
Cancels pending assignment and returns order to `pending_dispatch`.

### `GET /api/dispatcher/drivers`
List all drivers with status badges (`available`, `busy`, `offline`).

### `GET /api/dispatcher/drivers/available`
Quick endpoint returning only currently available drivers.

### `GET /api/dispatcher/sync`
Real-time delta synchronization endpoint for dashboard board updates.

---

## 3. Driver Endpoints (`/api/driver/*`)
*Guarded by Sanctum + `role:driver`*

### `GET /api/driver/sync`
Returns active order, pending incoming offer, and driver status.

### `GET /api/driver/assignment/pending`
Returns incoming offer awaiting driver response.

### `POST /api/driver/assignments/{id}/accept`
Driver accepts the offered dispatch assignment.

### `POST /api/driver/assignments/{id}/reject`
Driver declines the assignment offer.
* **Payload:**
```json
{
  "reason": "Traffic / distance"
}
```

### `POST /api/driver/orders/{id}/status`
Advances order delivery lifecycle.
* **Payload:**
```json
{
  "status": "picked_up" // or "in_transit" or "delivered"
}
```

### `POST /api/driver/profile/status`
Toggles driver status (`available` vs `offline`).
* **Payload:**
```json
{
  "status": "available"
}
```
