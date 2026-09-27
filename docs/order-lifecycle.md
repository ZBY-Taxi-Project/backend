# Order Lifecycle & Finite State Machine Specification

## 1. Lifecycle Graph

```text
[NEW]
  │
  ▼
[PENDING_DISPATCH] ────(Dispatcher Cancels)────► [CANCELLED]
  │
  │ (Dispatcher assigns driver)
  ▼
[ASSIGNED] ───(Driver Rejects / Timeout)───► [PENDING_DISPATCH]
  │
  │ (Driver clicks Accept)
  ▼
[DRIVER_ACCEPTED]
  │
  │ (Driver picks up package)
  ▼
[PICKED_UP]
  │
  │ (Driver departs pickup point)
  ▼
[IN_TRANSIT]
  │
  │ (Driver delivers to customer)
  ▼
[DELIVERED]  (Terminal)
```

## 2. State Machine Rules

| Current Status | Allowed Target Statuses | Initiated By | Side Effects |
| :--- | :--- | :--- | :--- |
| `new` | `pending_dispatch`, `cancelled` | System / Dispatcher | Order queued on dispatch board |
| `pending_dispatch` | `assigned`, `cancelled` | Dispatcher | Driver must be `available`; creates assignment record |
| `assigned` | `driver_accepted`, `pending_dispatch`, `cancelled` | Driver / Timeout | If accepted: driver marked `busy`. If rejected: order returns to queue |
| `driver_accepted` | `picked_up`, `cancelled` | Driver | Driver en route to pickup |
| `picked_up` | `in_transit` | Driver | Package collected |
| `in_transit` | `delivered` | Driver | Delivery journey |
| `delivered` | *None* | Driver | Terminal; driver status reset to `available` |
| `cancelled` | *None* | Dispatcher | Terminal; releases driver back to `available` |

## 3. Rejection & Concurrency Protection
* An invalid transition attempt (e.g. `delivered` -> `assigned`) throws `InvalidOrderStateTransitionException` returning HTTP 422:
```json
{
  "error": "INVALID_STATE_TRANSITION",
  "message": "Illegal order state transition from 'delivered' to 'assigned'."
}
```
* Simultaneous assignments lock both order and driver rows using `SELECT ... FOR UPDATE` within a database transaction.
