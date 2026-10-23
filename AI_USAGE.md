# AI Usage Disclosure — TripperShield PolicyFlow

In compliance with the assignment evaluation guidelines, this document discloses the AI tools utilized, specific contributions, encountered shortcomings, and the independent verification methods applied.

---

## 1. AI Tools Utilized
- **AI Model**: Google DeepMind Gemini (Advanced Coding Assistant) via Antigravity pair programming environment.
- **Role**: Architecture sparring partner, test-driven development assistant, and code scaffolding copilot.

---

## 2. Concrete Ways AI Assisted the Implementation
1. **Decimal-Safe Financial Calculation Scaffolding**:
   - Assisting in structuring `PremiumCalculator` using PHP's `bcmath` extension (`bcmul` with 4-scale intermediate precision and standard financial half-up rounding) to avoid IEEE 754 floating-point inaccuracies.
   - Generating boundary unit tests covering all age brackets (0–17, 18–60, 61–70, 71+), plans (BASIC, PLUS, PREMIUM), destinations (DOMESTIC, INTERNATIONAL), and coverage amounts.
2. **State Machine & Concurrency Strategy**:
   - Outlining the transition matrix in `StateTransitionService` and integrating pessimistic row locking (`lockForUpdate()`) inside transactions to protect against race conditions.
   - Formulating the `mock_insurer_policies` ledger schema to simulate an external provider's persistent state for realistic timeout reconciliation.
3. **Automated Reliability Test Cases**:
   - Designing end-to-end integration tests for all 4 reliability scenarios: duplicate intake, timeout after success, duplicate webhook callbacks, and temporary failure retries.

---

## 3. Incorrect or Incomplete Suggestions Encountered & Fixed
1. **Hardcoded Database Auto-Increment IDs**:
   - *Issue*: An initial draft of `tests/Feature/PolicyRequestIntakeTest.php` hardcoded a request to `/api/policy-requests/1`. This passed on SQLite (which resets sequence counters to 1 on clean tables), but failed when run against MySQL 8 because InnoDB retains auto-increment sequence progress across transaction rollbacks.
   - *Fix*: The test was corrected to dynamically extract `$recordId = $res1->json('data.id')` from the intake response and test against that specific ID.
2. **PHP 8.2+ Implicit Float Conversion in Carbon**:
   - *Issue*: Carbon's `$dobDate->diffInYears($tripStartDate)` returned a float under PHP 8.2, which caused an implicit float-to-int conversion deprecation warning when returning an integer age.
   - *Fix*: Explicitly cast the result to `(int) $dobDate->diffInYears($tripStartDate)` to ensure strict typing and clean, warning-free execution.
3. **PowerShell Statement Separators**:
   - *Issue*: Several command-line suggestions initially used UNIX-style `&&` chaining, which fails in default Windows PowerShell.
   - *Fix*: Replaced command separators with `;` or invoked single-purpose commands.

---

## 4. Verification and Independent Validation
Every suggestion was verified through hands-on testing:
1. **Automated Unit & Feature Testing**:
   - Ran `php artisan test` (26 tests, 135 assertions passed).
   - Ran `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml` directly against MySQL 8.0.45 (26 tests, 135 assertions passed).
2. **Database Lifecycle Testing**:
   - Verified database migrations roll back cleanly and reapply without schema conflicts using `php artisan migrate:rollback` and `php artisan migrate`.
3. **Manual Verification**:
   - Verified the exact benchmark calculation ($20.00 \times 5 \times 1.00 \times 1.80 \times 1.25 = ₹225.00$) against the assignment specification.
