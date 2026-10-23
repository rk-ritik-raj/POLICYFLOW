# TRIPPERSHIELD — POLICYFLOW
### Fault-Tolerant Insurance Issuance & Reconciliation Engine

PolicyFlow is an enterprise-grade, fault-tolerant travel insurance policy intake, pricing, and issuance engine built with **PHP 8.2+**, **Laravel 11+**, and **MySQL 8+**. 

The engine guarantees **strict idempotency**, **decimal-safe financial calculations**, **deterministic state machine transitions**, and **automated reconciliation** against unreliable upstream insurer networks (handling socket timeouts, delayed asynchronous callbacks, and duplicate webhook deliveries).

---

## Architecture Overview

```
                      ┌───────────────────────────────────────────────┐
                      │                 Client / Agent                │
                      └───────┬───────────────────────────────▲───────┘
                              │ POST /api/policy-requests     │
                              │ (Idempotent Intake)           │
                              ▼                               │
                      ┌───────────────────────────────┐       │
                      │   PolicyRequestController     │       │
                      └───────┬───────────────────────┘       │
                              │ Validates & Prices            │
                              ▼                               │
                      ┌───────────────────────────────┐       │
                      │       PremiumCalculator       │       │
                      │  (Decimal-Safe with BCMath)   │       │
                      └───────┬───────────────────────┘       │
                              │ Immutable Snapshot            │
                              ▼                               │
                      ┌───────────────────────────────┐       │
                      │      policy_requests (DB)     │       │
                      │ UNIQUE(agent_id, client_id)   │       │
                      └───────┬───────────────────────┘       │
                              │ POST /submit                  │
                              ▼                               │
                      ┌───────────────────────────────┐       │
                      │     PolicyIssuanceService     │       │
                      │    (Row-Locking & Audited)    │       │
                      └───────┬───────────────────────┘       │
                              │                               │
             ┌────────────────┴───────────────┐               │
             ▼                                ▼               │
      [Network Timeout]                [200 OK / Callback]    │
             │                                │               │
             ▼                                ▼               │
  NEEDS_RECONCILIATION               ISSUED (Unique Policy #) │
             │                                │               │
             ▼                                │               │
   ReconciliationService                      │               │
 (Query Provider Ledger) ─────────────────────┘               │
             │                                                │
             └────────────────────────────────────────────────┘
```

---

## 1. Technology Stack & Prerequisites

- **Language**: PHP 8.2 or newer (Tested with PHP 8.2.12 ZTS)
- **Extensions**: `bcmath`, `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `json`
- **Framework**: Laravel 11 / 12
- **Primary Database**: MySQL 8.0+ (Tested against MySQL 8.0.45)
- **Testing Database**: SQLite (fast in-memory) and MySQL 8 (`policyflow_test`)
- **Package Manager**: Composer 2.9+
- **Version Control**: Git

---

## 2. Installation and Local Setup

### Step 1: Clone or Open the Repository
```bash
git clone https://github.com/<your-username>/policyflow-<candidate-id>.git
cd policyflow-<candidate-id>
```

### Step 2: Install PHP Dependencies
```bash
composer install
```

### Step 3: Configure Environment
Copy `.env.example` to `.env`:
```bash
cp .env.example .env
```
Ensure your MySQL database credentials in `.env` match your local environment:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=policyflow
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

Generate the application encryption key:
```bash
php artisan key:generate
```

### Step 4: Run Database Migrations
Create the database in MySQL (if it doesn't already exist):
```sql
CREATE DATABASE IF NOT EXISTS policyflow;
CREATE DATABASE IF NOT EXISTS policyflow_test;
```
Then run the migrations:
```bash
php artisan migrate
```

### Step 5: Start the Local Development Server
```bash
php artisan serve
```
The API is now running at `http://127.0.0.1:8000`.

---

## 3. Automated Testing

The repository contains an automated test suite verifying business logic, decimal precision, state machine transitions, unique database constraints, and all **4 reliability scenarios**.

### Fast In-Memory Test Suite (Zero External Dependencies)
```bash
php artisan test
```
*Executes all 26 tests and 135 assertions in ~2.6 seconds using in-memory SQLite.*

### Full Integration Test Suite Against MySQL 8
To execute the test suite directly against real MySQL 8 locking and constraints:
```bash
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
```
*Executes 26 tests and 135 assertions against MySQL 8 with 100% pass rate.*

---

## 4. Business Logic & Premium Pricing Rules

Pricing is calculated using **decimal-safe arithmetic** via PHP's `bcmath` extension, eliminating floating-point rounding errors.

### Pricing Rules Matrix:
1. **Base Daily Rate**:
   - `BASIC`: ₹12.00
   - `PLUS`: ₹20.00
   - `PREMIUM`: ₹30.00

2. **Age Multiplier** (Evaluated as of the `trip_start` date):
   - `0–17`: 1.10
   - `18–60`: 1.00
   - `61–70`: 1.50
   - `71+`: 2.00

3. **Destination Multiplier**:
   - `DOMESTIC`: 1.00
   - `INTERNATIONAL`: 1.80

4. **Coverage Multiplier**:
   - `₹100,000`: 1.00
   - `₹300,000`: 1.25
   - `₹500,000`: 1.50

5. **Travel Days**:
   - Inclusive: $\text{trip\_end} - \text{trip\_start} + 1$, minimum 1 day.

### Formula:
$$\text{Premium} = \text{base\_daily\_rate} \times \text{travel\_days} \times \text{age\_multiplier} \times \text{destination\_multiplier} \times \text{coverage\_multiplier}$$

### Benchmark Verification Example:
- Plan: `PLUS` (₹20.00)
- Dates: `2026-11-10` to `2026-11-14` (5 inclusive days)
- Customer DOB: `1998-04-10` (Age 28 on trip start $\rightarrow$ Multiplier 1.00)
- Destination: `INTERNATIONAL` (Multiplier 1.80)
- Coverage: `₹300,000` (Multiplier 1.25)
- Calculation: $20.00 \times 5 \times 1.00 \times 1.80 \times 1.25 = \mathbf{₹225.00}$ (Exact match).

**Immutable Snapshot Guarantee**: Once calculated, the pricing breakdown is persisted as an immutable snapshot in `policy_requests`. Retries, reconnections, or reconciliations **never recalculate or replace** an accepted premium.

---

## 5. State Machine & Audit History

Every policy request follows a strict, audited state transition machine:

```
                  ┌──────────────┐
                  │   RECEIVED   │
                  └──────┬───────┘
                         │
                         ▼
                  ┌──────────────┐
                  │  VALIDATED   │◄──────────────┐
                  └──────┬───────┘               │
                         │                       │
                         ▼                       │ Safe Retry
                  ┌──────────────┐               │
                  │  SUBMITTED   │               │
                  └──────┬───────┘               │
         ┌───────────────┼───────────────┐       │
         │               │               │       │
         ▼               ▼               ▼       │
   ┌───────────┐  ┌─────────────┐  ┌──────────┐  │
   │  ISSUED   │  │   PENDING   │  │  FAILED  ├──┘
   │(TERMINAL) │  │CONFIRMATION │  └──────────┘
   └───────────┘  └──────┬──────┘        ▲
         ▲               │               │
         │         ┌─────┴─────┐         │
         │         │           ▼         │
         │    [Callback]  [Timeout]      │
         │         │           │         │
         │         ▼           ▼         │
         │      ISSUED    NEEDS_RECON    │
         │                     │         │
         └─────────────────────┴─────────┘
                   Reconciled
```

### Transition Invariants:
- `ISSUED` is a **terminal state**. The system enforces that an issued policy can **never regress** to `SUBMITTED`, `FAILED`, or `NEEDS_RECONCILIATION`.
- All transitions occur under a **pessimistic row lock** (`SELECT ... FOR UPDATE`) inside an isolated database transaction.
- Every state change is recorded in `policy_state_history` with `from_status`, `to_status`, timestamp, reason, and structured context.

---

## 6. Reliability & Idempotency Implementations

PolicyFlow implements and tests all **four** reliability challenges:

### Reliability Case 1: Duplicate Intake Handling
- **Problem**: The distributor receives identical requests from an agent concurrently or near-simultaneously.
- **Solution**:
  1. Application pre-check for existing `(agent_id, client_request_id)`.
  2. Database-level composite unique constraint: `UNIQUE KEY (agent_id, client_request_id)`.
  3. Concurrent race conditions that bypass the pre-check are caught by MySQL's unique constraint violation (`QueryException`) and safely returned as HTTP 200 with the existing record.
  4. Returns `HTTP 201 Created` for newly created requests; `HTTP 200 OK` for safely repeated intakes.

### Reliability Case 2: Timeout After Success & Reconciliation
- **Problem**: The upstream insurer successfully generates a policy, but the network connection drops or times out before the client receives the response. Blindly retrying would cause duplicate policy issuance.
- **Solution**:
  1. When a network connection timeout occurs during submission (`TIMEOUT_AFTER_ISSUE`), the request transitions to `NEEDS_RECONCILIATION`.
  2. `ReconciliationService` queries the provider's external ledger (`/api/mock-insurer/lookup` or internal status check) using the stable identifiers before permitting any new issuance attempt.
  3. Upon finding the policy was issued upstream, PolicyFlow recovers the `policy_number` and marks the request `ISSUED` without duplicate issuance.

### Reliability Case 3: Duplicate Provider Callbacks
- **Problem**: External webhook deliveries often retry 2–3 times due to network glitches or delayed HTTP 200 ACKs.
- **Solution**:
  1. The `provider_events` table enforces `UNIQUE KEY (event_id)`.
  2. The first callback delivery updates the policy request to `ISSUED` under row lock.
  3. Subsequent callbacks with the same `event_id` are caught by the unique constraint and return `DUPLICATE_IGNORED` with `HTTP 200 OK` without re-executing state changes.

### Reliability Case 4: Retry After Temporary Failure
- **Problem**: Insurer returns a temporary 503 retriable failure. Retries must not produce duplicate policies or recalculate premiums.
- **Solution**:
  1. Temporary failure transitions the request to `FAILED` and increments `attempt_count`.
  2. Subsequent retry submission checks the existing persisted premium snapshot and successfully transitions to `ISSUED`.
  3. Exactly one policy number is generated, and the accepted premium remains unaltered.

---

## 7. Database Design & Schema

### Tables & Relationships:
1. **`policy_requests`**:
   - `id`: BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
   - `client_request_id`: VARCHAR(100)
   - `agent_id`: VARCHAR(50)
   - `customer_name`: VARCHAR(255)
   - `date_of_birth`: DATE
   - `destination`: VARCHAR(30)
   - `trip_start`: DATE
   - `trip_end`: DATE
   - `travel_days`: INT UNSIGNED
   - `customer_age`: INT UNSIGNED
   - `plan_code`: VARCHAR(30)
   - `sum_insured`: DECIMAL(12, 2)
   - `base_daily_rate`: DECIMAL(8, 2)
   - `age_multiplier`: DECIMAL(4, 2)
   - `destination_multiplier`: DECIMAL(4, 2)
   - `coverage_multiplier`: DECIMAL(4, 2)
   - `premium_amount`: DECIMAL(10, 2) (Immutable snapshot)
   - `status`: VARCHAR(30)
   - `provider_reference`: VARCHAR(100), NULLABLE
   - `policy_number`: VARCHAR(100), UNIQUE, NULLABLE
   - `attempt_count`: INT UNSIGNED DEFAULT 0
   - `last_error`: TEXT, NULLABLE
   - `created_at`, `updated_at`: TIMESTAMP
   - **Constraints**: `UNIQUE (agent_id, client_request_id)`, `UNIQUE (policy_number)`, `INDEX (status, created_at)`

2. **`issuance_attempts`**:
   - `id`: BIGINT UNSIGNED PRIMARY KEY
   - `policy_request_id`: FK $\rightarrow$ `policy_requests.id` (ON DELETE CASCADE)
   - `attempt_number`: INT UNSIGNED
   - `scenario`: VARCHAR(50)
   - `request_payload`: JSON
   - `response_payload`: JSON
   - `status`: VARCHAR(50)
   - `error_message`: TEXT, NULLABLE

3. **`provider_events`**:
   - `id`: BIGINT UNSIGNED PRIMARY KEY
   - `event_id`: VARCHAR(100) UNIQUE (Guarantees webhook idempotency)
   - `policy_request_id`: FK $\rightarrow$ `policy_requests.id`, NULLABLE
   - `event_type`: VARCHAR(50)
   - `payload`: JSON
   - `status`: VARCHAR(30)

4. **`policy_state_history`**:
   - `id`: BIGINT UNSIGNED PRIMARY KEY
   - `policy_request_id`: FK $\rightarrow$ `policy_requests.id` (ON DELETE CASCADE)
   - `from_status`: VARCHAR(30), NULLABLE
   - `to_status`: VARCHAR(30)
   - `reason`: VARCHAR(255)
   - `context`: JSON, NULLABLE
   - `created_at`: TIMESTAMP

5. **`mock_insurer_policies`**:
   - Simulates the external insurer's internal policy registry to enable deterministic status lookups during reconciliation.

---

## 8. API Contract Summary

| Method | Endpoint | Description | Status Code |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/policy-requests` | Intake endpoint. Validates, prices, and stores request idempotently. | `201 Created` / `200 OK` |
| `GET` | `/api/policy-requests/{id}` | Retrieves policy details, immutable pricing breakdown, and audit trail. | `200 OK` |
| `GET` | `/api/policy-requests` | Lists requests with pagination and filters (`?status=ISSUED&agent_id=...`). | `200 OK` |
| `POST` | `/api/policy-requests/{id}/submit` | Submits request to insurer. Accepts scenario flag (`SUCCESS`, `TIMEOUT_AFTER_ISSUE`, etc.). | `200 OK` / `422 Unprocessable` |
| `POST` | `/api/policy-requests/{id}/reconcile` | Reconciles pending or timed-out requests against provider status. | `200 OK` |
| `POST` | `/api/mock-insurer/callback` | Webhook endpoint receiving simulated asynchronous provider events idempotently. | `200 OK` |
| `GET` | `/api/mock-insurer/lookup` | Query simulated upstream insurer registry for reconciliation. | `200 OK` |

*Complete cURL examples for all 10 scenarios are provided in [`examples/README.md`](examples/README.md).*

---

## 9. Assumptions and Trade-offs

1. **SQLite vs. MySQL Concurrency**:
   - SQLite supports `BEGIN IMMEDIATE` but does not support row-level pessimistic locking (`SELECT ... FOR UPDATE`), instead locking the entire database file during transactions.
   - For this reason, PolicyFlow provides both SQLite in-memory configuration (`phpunit.xml`) for fast developer feedback and a dedicated MySQL 8 test runner (`phpunit.mysql.xml`) to validate real row locking and InnoDB multi-version concurrency control (MVCC).
2. **Synchronous Mock Execution**:
   - For ease of local review without background worker dependencies, the submission and reconciliation workflows run synchronously by default while adhering to production-ready database constraints.
3. **Data Privacy**:
   - Audit trail context logs avoid storing personal identifying numbers or raw sensitive credentials.
