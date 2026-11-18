<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use App\Domain\Shared\Audit\Activity;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Log Audit (A5.7, system.audit.view): activity of the active organisation.
 * Sensitive attributes are already stored as "changed" without values.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requirePermission('system.audit.view');

        $log = trim((string) $request->query('log', ''));
        $subjectType = trim((string) $request->query('subject_type', ''));
        $subjectId = $request->integer('subject_id');

        $entries = Activity::query()
            ->where('organisation_id', OrganisationContext::id())
            ->when($log !== '', fn ($q) => $q->where('log_name', $log))
            ->when($subjectType !== '', fn ($q) => $q->where('subject_type', $subjectType))
            ->when($subjectId > 0, fn ($q) => $q->where('subject_id', $subjectId))
            ->with('causer')
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Activity $a) => [
                'id' => $a->id,
                'log_name' => $a->log_name,
                'description' => $a->description,
                'event' => $a->event,
                'subject' => class_basename((string) $a->subject_type).' #'.$a->subject_id,
                'causer' => $a->causer?->getAttribute('name'),
                'properties' => $a->properties,
                'created_at' => $a->created_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('System/Audit/Index', [
            'entries' => $entries,
            'filters' => ['log' => $log],
        ]);
    }
}
