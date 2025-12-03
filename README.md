# Flash Sale API

A production-ready Laravel 12 API for managing flash sales with concurrency-safe inventory holds, idempotent payment webhooks, and automatic stock restoration.

## Table of Contents

-   [Assumptions & Business Rules](#assumptions--business-rules)
-   [How to Run the Project](#how-to-run-the-project)
-   [How to View Logs & Metrics](#how-to-view-logs--metrics)
-   [Automated Tests Coverage](#automated-tests-coverage)
-   [API Endpoints](#api-endpoints)

---

## Assumptions & Business Rules

### Stock Management

-   **Finite Stock**: Products have a limited `stock_remaining` that cannot be oversold
-   **Instant Reservation**: Creating a hold immediately decrements available stock using pessimistic locking (`lockForUpdate`)
-   **Concurrency Safety**: All stock operations use database transactions with row-level locks to prevent race conditions

### Hold Lifecycle

-   **Expiration Time**: Holds expire automatically after 2 minutes from creation
-   **Single Use**: Each hold can only be converted to one order (enforced via `status` transitions: `active` → `converted`)
-   **Automatic Release**: Expired holds restore stock via scheduled background job (`holds:release-expired`)
-   **Status States**: `active`, `converted`, `expired`

### Order Lifecycle

-   **State Machine**: Orders transition through: `pending_payment` → `paid` or `cancelled`
-   **Stock Restoration**: Failed payments trigger automatic stock restoration
-   **Hold Association**: Each order is linked to exactly one hold via `hold_id`

### Payment Webhook

-   **Idempotency**: Duplicate webhooks are deduplicated using `webhook_id` (stored in `payment_logs` table)
-   **Race Condition Handling**: Webhook may arrive before order creation response; system handles eventual consistency
-   **Multiple Deliveries**: Same webhook processed only once; subsequent calls return existing `PaymentLog` record
-   **Atomic Updates**: Payment status updates and stock restoration happen in a single transaction

### Concurrency Guarantees

-   **Pessimistic Locking**: All critical operations use `lockForUpdate()` on products, holds, and orders
-   **Database Transactions**: All state changes wrapped in `DB::transaction()` for atomicity
-   **No Overselling**: Stock checks and decrements happen atomically within locked transactions
-   **Retry Safety**: Operations are designed to be safely retried without side effects

---

## How to Run the Project

### System Requirements

-   **PHP**: 8.2 or higher
-   **MySQL**: 8.0+ (or MariaDB 10.5+)
-   **Composer**: 2.x
-   **Node.js**: 18+ (for asset compilation, optional)

### Installation Steps

```bash
# 1. Clone the repository
git clone https://github.com/YOUR_USERNAME/flash-sale-api.git
cd flash-sale-api

# 2. Install dependencies
composer install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Update .env with your database credentials
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=flash_sale
# DB_USERNAME=root
# DB_PASSWORD=

# 5. Run migrations and seed sample data
php artisan migrate
php artisan db:seed --class=ProductSeeder

# 6. Install API authentication (if not already done)
php artisan install:api
```

### Running the Application

```bash
# Terminal 1: Start the web server
php artisan serve --port=8000

# Terminal 2: Start queue workers for background jobs
php artisan queue:work

# Terminal 3: Start scheduler for hold expiration (runs every minute)
php artisan schedule:work
```

**API Base URL**: `http://localhost:8000/api`

### Quick Test

```bash
# Get product details
curl http://localhost:8000/api/products/1

# Create a hold
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'
```

---

## How to View Logs & Metrics

### Application Logs

**Location**: `storage/logs/laravel.log`

```bash
# Tail logs in real-time
tail -f storage/logs/laravel.log

# Use Laravel Pail for enhanced log viewing (Laravel 12)
php artisan pail
```

### Enable Debug Logging

Update `.env`:

```env
APP_DEBUG=true
LOG_LEVEL=debug
```

### Monitoring Key Events

The application logs critical events with structured context:

```php
// Hold creation with contention
Log::info('Hold created', ['hold_id' => $hold->id, 'product_id' => $productId, 'stock_remaining' => $product->stock_remaining]);

// Webhook deduplication
Log::info('Duplicate webhook ignored', ['webhook_id' => $webhookId, 'existing_log_id' => $existingLog->id]);

// Stock restoration
Log::info('Stock restored from expired hold', ['hold_id' => $hold->id, 'quantity' => $hold->quantity]);
```

### Queue Monitoring

```bash
# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Monitor queue status
php artisan queue:monitor
```

### Database Query Logging

Add to `AppServiceProvider::boot()` for development:

```php
DB::listen(function ($query) {
    Log::debug('Query', ['sql' => $query->sql, 'time' => $query->time]);
});
```

---

## Automated Tests Coverage

### Running Tests

```bash
# Run all tests
php artisan test

# Run with coverage (requires Xdebug)
php artisan test --coverage

# Run specific test suite
php artisan test --testsuite=Feature
```

### Test Suite Overview

| Test Goal                                      | Expected Result                                                 | Test Name                                                    |
| ---------------------------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------ |
| **No overselling under high concurrency**      | Stock never drops below zero, even with 100+ parallel requests  | `ConcurrencyTest::test_concurrent_holds_do_not_oversell`     |
| **Holds release stock correctly when expired** | Availability returned automatically without manual intervention | `HoldExpirationTest::test_expired_holds_restore_stock`       |
| **Webhook is idempotent**                      | Duplicate webhooks do not change order state more than once     | `PaymentWebhookTest::test_duplicate_webhooks_are_idempotent` |
| **Webhook arrives before order creation**      | Final state remains consistent regardless of timing             | `PaymentWebhookTest::test_webhook_before_order_creation`     |

### Test Descriptions

#### 1. `test_concurrent_holds_do_not_oversell`

Spawns 100 parallel requests attempting to create holds for a product with limited stock. Verifies:

-   Total holds created ≤ available stock
-   `stock_remaining` never goes negative
-   Database integrity maintained under contention

#### 2. `test_expired_holds_restore_stock`

Creates holds, advances time past expiration, runs scheduler command. Verifies:

-   Hold status changes to `expired`
-   Stock is returned to product
-   No manual intervention required

#### 3. `test_duplicate_webhooks_are_idempotent`

Sends identical webhook payloads multiple times. Verifies:

-   Only one `PaymentLog` record created
-   Order status updated exactly once
-   Subsequent calls return existing log without side effects

#### 4. `test_webhook_before_order_creation`

Simulates webhook arriving before `POST /orders` response completes. Verifies:

-   System handles race condition gracefully
-   Final order state is correct (`paid` or `cancelled`)
-   No data corruption or duplicate stock adjustments

### Verification Script

A Python concurrency test script is included:

```bash
python verify_concurrency.py
```

This script simulates real-world load and validates:

-   No overselling occurs
-   Response times under load
-   Error rate thresholds

---

## API Endpoints

### Products

**GET** `/api/products/{id}`

-   Returns product details including `stock_remaining`

### Holds

**POST** `/api/holds`

```json
{
    "product_id": 1,
    "qty": 2
}
```

Response:

```json
{
    "hold_id": 123,
    "token": "uuid-token",
    "expires_at": "2025-12-04T00:46:00Z"
}
```

### Orders

**POST** `/api/orders`

```json
{
    "hold_id": 123
}
```

Response:

```json
{
    "order_id": 456,
    "status": "pending_payment",
    "product_id": 1,
    "quantity": 2
}
```

### Payment Webhook

**POST** `/api/payments/webhook`

```json
{
    "webhook_id": "unique-webhook-id",
    "order_id": 456,
    "status": "success"
}
```

---

## Architecture Highlights

-   **Service Layer**: Business logic encapsulated in `FlashSaleService`
-   **Database Transactions**: All critical operations use `DB::transaction()` with pessimistic locking
-   **Background Jobs**: Scheduled command releases expired holds every minute
-   **Idempotency**: Webhook deduplication via unique `webhook_id` constraint
-   **State Machine**: Explicit status transitions prevent invalid state changes

## License

MIT
