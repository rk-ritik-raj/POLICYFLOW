# PolicyFlow API Examples & Runnable cURL Commands

All commands assume the local server is running at `http://127.0.0.1:8000`.

Start local server:
```bash
php artisan serve
```

---

## 1. Creating a Valid Policy Request (POST /api/policy-requests)

### Request:
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```

### Response (`HTTP 201 Created`):
```json
{
  "success": true,
  "status": "CREATED",
  "message": "Policy request created and validated successfully.",
  "data": {
    "id": 1,
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "travel_days": 5,
    "customer_age": 28,
    "plan_code": "PLUS",
    "sum_insured": "300000.00",
    "pricing_breakdown": {
      "base_daily_rate": "20.00",
      "age_multiplier": "1.00",
      "destination_multiplier": "1.80",
      "coverage_multiplier": "1.25",
      "premium_amount": "225.00"
    },
    "status": "VALIDATED",
    "provider_reference": null,
    "policy_number": null,
    "attempt_count": 0,
    "last_error": null,
    "created_at": "2026-10-24T00:00:00+00:00",
    "updated_at": "2026-10-24T00:00:00+00:00"
  }
}
```

---

## 2. Submitting the Same Intake Request Twice (Idempotent Intake)

### Request:
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```

### Response (`HTTP 200 OK`):
```json
{
  "success": true,
  "status": "EXISTING_REQUEST",
  "message": "Policy request already exists. Idempotent response returned.",
  "data": {
    "id": 1,
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "status": "VALIDATED",
    "pricing_breakdown": {
      "premium_amount": "225.00"
    }
  }
}
```

---

## 3. Rejecting Invalid Input (Validation Errors)

### Request (Invalid Date & Unsupported Plan):
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-INVALID-001",
    "agent_id": "AGENT-17",
    "customer_name": "Invalid Customer",
    "date_of_birth": "1998-04-10",
    "destination": "MARS",
    "trip_start": "2026-11-14",
    "trip_end": "2026-11-10",
    "plan_code": "ULTRA",
    "sum_insured": 250000
  }'
```

### Response (`HTTP 422 Unprocessable Content`):
```json
{
  "success": false,
  "message": "Validation failed for policy request intake.",
  "errors": {
    "destination": [
      "Unsupported destination. Permitted destinations: DOMESTIC, INTERNATIONAL."
    ],
    "trip_end": [
      "The trip end date must be on or after the trip start date."
    ],
    "plan_code": [
      "Unsupported plan code. Permitted plans: BASIC, PLUS, PREMIUM."
    ],
    "sum_insured": [
      "Unsupported sum insured. Permitted coverage amounts: 100000, 300000, 500000."
    ]
  }
}
```

---

## 4. Calculating Expected Premium Breakdown

The premium calculation formula is:
$$\text{Premium} = \text{base daily rate} \times \text{inclusive days} \times \text{age multiplier} \times \text{destination multiplier} \times \text{coverage multiplier}$$

For the assignment sample:
- Plan `PLUS`: ₹20.00
- Inclusive travel days (2026-11-10 to 2026-11-14): 5 days
- Age 28 (Bracket 18–60): 1.00
- Destination `INTERNATIONAL`: 1.80
- Coverage `300000`: 1.25
- Arithmetic: $20.00 \times 5 \times 1.00 \times 1.80 \times 1.25 = \mathbf{₹225.00}$

---

## 5. Starting Issuance (POST /api/policy-requests/{id}/submit)

### Request:
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -d '{
    "scenario": "SUCCESS"
  }'
```

### Response (`HTTP 200 OK`):
```json
{
  "success": true,
  "result": {
    "status": "ISSUED",
    "policy_number": "POL-20261024-X8K9L1",
    "provider_reference": "REF-ABC1234XYZ",
    "message": "Policy issued successfully."
  },
  "data": {
    "id": 1,
    "status": "ISSUED",
    "policy_number": "POL-20261024-X8K9L1",
    "provider_reference": "REF-ABC1234XYZ",
    "attempt_count": 1
  }
}
```

---

## 6. Retrieving Current Status and Audit History (GET /api/policy-requests/{id})

### Request:
```bash
curl -X GET http://127.0.0.1:8000/api/policy-requests/1
```

### Response (`HTTP 200 OK`):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "status": "ISSUED",
    "policy_number": "POL-20261024-X8K9L1",
    "provider_reference": "REF-ABC1234XYZ",
    "pricing_breakdown": {
      "premium_amount": "225.00"
    },
    "attempts": [
      {
        "attempt_number": 1,
        "scenario": "SUCCESS",
        "status": "SUCCESS",
        "provider_reference": "REF-ABC1234XYZ"
      }
    ],
    "state_history": [
      {
        "from_status": "RECEIVED",
        "to_status": "VALIDATED",
        "reason": "Request intake successfully verified and priced."
      },
      {
        "from_status": "VALIDATED",
        "to_status": "SUBMITTED",
        "reason": "Submission started to insurer provider."
      },
      {
        "from_status": "SUBMITTED",
        "to_status": "ISSUED",
        "reason": "Insurer successfully issued policy."
      }
    ]
  }
}
```

---

## 7. Listing and Filtering Policy Requests (GET /api/policy-requests)

### Request:
```bash
curl -X GET "http://127.0.0.1:8000/api/policy-requests?status=ISSUED&per_page=10"
```

### Response (`HTTP 200 OK`):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "client_request_id": "AGT-2026-0001",
      "status": "ISSUED",
      "policy_number": "POL-20261024-X8K9L1"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total": 1,
    "last_page": 1
  }
}
```

---

## 8. Processing Mock Provider Callback (POST /api/mock-insurer/callback)

### Request:
```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "EVT-MOCK-999",
    "event_type": "POLICY_ISSUED",
    "provider_reference": "REF-ABC1234XYZ",
    "policy_number": "POL-ASYNC-5555",
    "agent_id": "AGENT-17",
    "client_request_id": "AGT-2026-0001"
  }'
```

### Response (`HTTP 200 OK`):
```json
{
  "success": true,
  "result": {
    "success": true,
    "status": "PROCESSED",
    "event_id": "EVT-MOCK-999"
  }
}
```

---

## 9. Handling Timeout and Reconciliation (POST .../reconcile)

### Step A: Submit with TIMEOUT_AFTER_ISSUE
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/2/submit \
  -H "Content-Type: application/json" \
  -d '{ "scenario": "TIMEOUT_AFTER_ISSUE" }'
```
Response:
```json
{
  "result": {
    "status": "NEEDS_RECONCILIATION",
    "error": "Submission timed out. Request placed in NEEDS_RECONCILIATION to protect against duplicate issuance."
  }
}
```

### Step B: Reconcile with Provider
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/2/reconcile
```
Response:
```json
{
  "success": true,
  "result": {
    "reconciled": true,
    "outcome": "CONFIRMED_ISSUED"
  },
  "data": {
    "status": "ISSUED",
    "policy_number": "POL-20261024-REC01"
  }
}
```

---

## 10. Safely Retrying a Failed Request

### Step A: Temporary Failure
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/3/submit \
  -H "Content-Type: application/json" \
  -d '{ "scenario": "TEMPORARY_FAILURE" }'
```
Response (`HTTP 422`):
```json
{
  "success": false,
  "result": {
    "status": "FAILED",
    "retriable": true,
    "error": "Mock provider temporary 503 Service Unavailable."
  }
}
```

### Step B: Safe Retry
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/3/submit \
  -H "Content-Type: application/json" \
  -d '{ "scenario": "SUCCESS" }'
```
Response (`HTTP 200`):
```json
{
  "success": true,
  "result": {
    "status": "ISSUED",
    "policy_number": "POL-20261024-RETRY01"
  },
  "data": {
    "status": "ISSUED",
    "pricing_breakdown": {
      "premium_amount": "225.00"
    }
  }
}
```
