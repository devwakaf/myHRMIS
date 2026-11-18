<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base for business-rule failures that the user should see as a translated message.
 * Rendered as a flash error (Inertia) or a 422 JSON body.
 */
abstract class DomainError extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $params = [],
    ) {
        parent::__construct($translationKey);
    }

    public function userMessage(): string
    {
        return (string) __($this->translationKey, array_map(fn ($v) => (string) $v, $this->params));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return new JsonResponse(['message' => $this->userMessage(), 'code' => $this->translationKey], 422);
        }

        return back()->with('error', $this->userMessage());
    }
}
