#!/usr/bin/env bash
# PolicyFlow - Example 9: Simulate Timeout and Safe Reconciliation

echo "--- 1. Creating Policy Request ---"
CREATE_RES=$(curl -s -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-TIMEOUT-DEMO",
    "agent_id": "AGENT-DEMO",
    "customer_name": "Timeout Demo",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }')
echo "$CREATE_RES"

ID=$(echo "$CREATE_RES" | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
echo "Policy Request ID: $ID"

echo "--- 2. Submitting with TIMEOUT_AFTER_ISSUE ---"
curl -s -X POST "http://127.0.0.1:8000/api/policy-requests/$ID/submit" \
  -H "Content-Type: application/json" \
  -d '{ "scenario": "TIMEOUT_AFTER_ISSUE" }'

echo "--- 3. Reconciling with Mock Insurer Provider Ledger ---"
curl -s -X POST "http://127.0.0.1:8000/api/policy-requests/$ID/reconcile"
