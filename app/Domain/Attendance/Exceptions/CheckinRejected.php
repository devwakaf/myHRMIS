<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Exceptions;

use App\Domain\Shared\Support\DomainError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Check-in refused (spec-03 §4.7): 409 when the location notice must be accepted,
 * 422 otherwise. The JSON body carries a machine code plus nearest-location info.
 */
final class CheckinRejected extends DomainError
{
    /**
     * @param  array<string, scalar|null>  $params
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $translationKey,
        array $params = [],
        public readonly string $reason = 'rejected',
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($translationKey, $params);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia')) {
            return back()->with('error', $this->userMessage());
        }

        return new JsonResponse([
            'message' => $this->userMessage(),
            'reason' => $this->reason,
            'details' => $this->details,
        ], $this->status);
    }
}
