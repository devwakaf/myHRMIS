<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\SelfUpdateProfile;
use App\Domain\Core\Enums\EmergencyRelationship;
use App\Domain\Core\Http\Resources\EmployeeResource;
use App\Domain\Core\Models\EmployeeDocument;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Shared\Support\DomainError;
use App\Domain\Shared\Support\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profil Saya (B4): own record (sensitive fields of one's own record visible),
 * edit of the fields HR allows, own documents, own holiday list.
 */
final class ProfileController extends Controller
{
    public function show(Request $request, HolidayResolver $resolver): Response
    {
        $employee = $request->user()->employee();
        abort_if($employee === null, 404, (string) __('core.me.no_record'));
        $employee->load(['branch', 'department', 'designation', 'grade', 'employmentType', 'reportsTo', 'bank', 'taxProfile', 'emergencyContacts']);

        $holidays = [];
        $listName = null;
        try {
            $list = $resolver->listFor($employee, today());
            $listName = $list->displayName();
            $holidays = $resolver->holidaysBetween($employee, today(), $list->to_date)
                ->filter(fn (array $h) => $h['kind'] !== 'weekly_off')
                ->map(fn (array $h, string $date) => ['date' => $date, 'name' => $h['name'], 'kind' => $h['kind'], 'half_day' => $h['isHalfDay']])
                ->values()->all();
        } catch (DomainError) {
            // No list assigned yet.
        }

        return Inertia::render('Core/Me/Show', [
            'employee' => (new EmployeeResource($employee))->resolve($request),
            'emergencyContacts' => $employee->emergencyContacts->map(fn ($c) => $c->only(['name', 'phone', 'alt_phone', 'address', 'is_primary'])
                + ['relationship' => $c->relationship->value, 'relationship_label' => $c->relationship->label()])->values(),
            'documents' => EmployeeDocument::query()->where('employee_id', $employee->id)->with('documentType')->latest()->get()
                ->map(fn (EmployeeDocument $d) => [
                    'id' => $d->id, 'title' => $d->title, 'type' => $d->documentType?->displayName(),
                    'expires_on' => $d->expires_on?->toDateString(),
                ])->values(),
            'holidayList' => $listName,
            'holidays' => $holidays,
            'editable' => HrSettings::current()->selfServiceFields(),
            'options' => [
                'states' => MalaysianState::options(),
                'relationships' => EmergencyRelationship::options(),
            ],
            'canEdit' => $request->user()->can('selfEdit', $employee),
        ]);
    }

    public function update(Request $request, SelfUpdateProfile $update): RedirectResponse
    {
        $employee = $request->user()->employee();
        abort_if($employee === null, 404);
        $this->authorize('selfEdit', $employee);

        $update->handle($employee, $request->except(['_token', '_method']));

        return $this->done('common.saved');
    }
}
