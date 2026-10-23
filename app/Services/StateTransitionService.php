<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StateTransitionService
{
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    public const STATUS_ISSUED = 'ISSUED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /**
     * Allowed state transitions map.
     */
    protected const ALLOWED_TRANSITIONS = [
        self::STATUS_RECEIVED => [
            self::STATUS_VALIDATED,
            self::STATUS_FAILED,
        ],
        self::STATUS_VALIDATED => [
            self::STATUS_SUBMITTED,
            self::STATUS_FAILED,
        ],
        self::STATUS_SUBMITTED => [
            self::STATUS_ISSUED,
            self::STATUS_PENDING_CONFIRMATION,
            self::STATUS_FAILED,
            self::STATUS_NEEDS_RECONCILIATION,
        ],
        self::STATUS_PENDING_CONFIRMATION => [
            self::STATUS_ISSUED,
            self::STATUS_FAILED,
            self::STATUS_NEEDS_RECONCILIATION,
        ],
        self::STATUS_FAILED => [
            self::STATUS_SUBMITTED, // Safe retry
            self::STATUS_NEEDS_RECONCILIATION,
        ],
        self::STATUS_NEEDS_RECONCILIATION => [
            self::STATUS_ISSUED,
            self::STATUS_FAILED,
            self::STATUS_SUBMITTED,
        ],
        // ISSUED is terminal - no transitions allowed out of ISSUED
        self::STATUS_ISSUED => [],
    ];

    /**
     * Check if a transition is valid.
     */
    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === $toStatus) {
            return false;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];

        return in_array($toStatus, $allowed, true);
    }

    /**
     * Perform an explicit, audited state transition under row lock.
     *
     * @param PolicyRequest $policyRequest
     * @param string $toStatus
     * @param string|null $reason
     * @param array|null $context
     * @param array $extraAttributes
     * @return PolicyRequest
     */
    public function transition(
        PolicyRequest $policyRequest,
        string $toStatus,
        ?string $reason = null,
        ?array $context = null,
        array $extraAttributes = []
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $toStatus, $reason, $context, $extraAttributes) {
            /** @var PolicyRequest $lockedRequest */
            $lockedRequest = PolicyRequest::where('id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromStatus = $lockedRequest->status;

            if ($fromStatus === $toStatus) {
                return $lockedRequest;
            }

            if (!$this->canTransition($fromStatus, $toStatus)) {
                throw new InvalidArgumentException(
                    "Illegal state transition from '{$fromStatus}' to '{$toStatus}' for Policy Request #{$lockedRequest->id}."
                );
            }

            // Update attributes
            $attributesToUpdate = array_merge($extraAttributes, [
                'status' => $toStatus,
            ]);

            $lockedRequest->update($attributesToUpdate);

            // Record state audit trail
            PolicyStateHistory::create([
                'policy_request_id' => $lockedRequest->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
                'context' => $context,
                'created_at' => now(),
            ]);

            return $lockedRequest->fresh();
        });
    }
}
