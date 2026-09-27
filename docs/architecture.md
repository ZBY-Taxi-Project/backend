# System Architecture: ZBY Dispatcher & Driver Delivery Platform

## High-Level Topology

```text
┌─────────────────────────────────┐      ┌───────────────────────────────┐
│ Dispatcher Operations Dashboard │      │    Driver Mobile Client       │
│           (frontend/)           │      │        (zby_driver/)          │
│         Vite + Web Audio        │      │    Vite Mobile / PWA Client   │
└────────────────┬────────────────┘      └───────────────┬───────────────┘
                 │                                       │
                 │ HTTP REST / JSON                      │ HTTP REST / JSON
                 │ Bearer Token (Sanctum)                │ Bearer Token (Sanctum)
                 ▼                                       ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        Laravel 13 Core Backend                         │
│                              (backend/)                                │
│                                                                        │
│   ├── AuthController & Sanctum Token Guard                             │
│   ├── Dispatcher Order & Driver Management APIs                        │
│   ├── Driver Workflow & Status Update APIs                             │
│   ├── OrderStateMachine (Validation & Terminal Rules)                  │
│   ├── AssignmentService (Concurrency Protection & Row Locking)         │
│   └── RealtimeController (Event Broadcasts & SSE Stream)               │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
                      ┌───────────────────────────┐
                      │    Database (SQLite /     │
                      │       PostgreSQL)         │
                      └───────────────────────────┘
```

## Directory Overview

* **`backend/`**: Laravel 13 framework running on PHP 8.5 with Sanctum token authentication, SQLite database, Eloquent domain models, database migrations, seeders, and automated feature tests.
* **`frontend/`**: Dispatcher Operations Hub with real-time live synchronization, audible alert chime, order filtering, and driver assignment panel.
* **`zby_driver/`**: Mobile-first driver application with availability status controls, incoming dispatch alert with 30s countdown bar, and 3-stage delivery lifecycle buttons.
* **`docs/`**: Complete architecture blueprints, API specifications, and order state transition rules.

## Security & Concurrency Defense
1. **Server-side Authorization**: All routes guarded by Sanctum and strict `RoleMiddleware`. Dispatchers cannot call driver routes; drivers cannot call dispatcher routes.
2. **Pessimistic Locking**: `AssignmentService` executes all assignment and state changes inside `DB::transaction()` using `lockForUpdate()`. Two simultaneous assignment attempts on the same driver or order are safely serialized.
3. **Decoupled Assignment History**: `order_assignments` table tracks every dispatch attempt (driver, timestamp, accept/reject, reason) independently from the order record.
