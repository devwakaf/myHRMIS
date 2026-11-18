<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\CreateOrganisation;
use App\Domain\Core\Actions\UpdateOrganisation;
use App\Domain\Core\Enums\DailyRateBasis;
use App\Domain\Core\Enums\RegistrationType;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Shared\Support\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisasi (B1.1, B4): Super Admin manages; HR Manager may view their own.
 */
final class OrganisationController extends Controller
{
    public function index(Request $request, OrganisationMembership $membership): Response
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $user->can('system.organisations.view'), 403);

        $organisations = $user->is_super_admin
            ? Organisation::query()->orderBy('name')->get()
            : $membership->organisationsFor($user);

        return Inertia::render('Core/Organisations/Index', [
            'organisations' => $organisations->map(fn (Organisation $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'short_code' => $o->short_code,
                'state' => $o->state->label(),
                'kwsp_employer_no' => $o->kwsp_employer_no,
                'perkeso_employer_code' => $o->perkeso_employer_code,
                'lhdn_employer_no' => $o->lhdn_employer_no,
                'is_active' => $o->is_active,
            ])->values(),
            'canManage' => $user->is_super_admin,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->is_super_admin, 403);

        return Inertia::render('Core/Organisations/Form', ['organisation' => null, 'options' => $this->options(), 'canManage' => true]);
    }

    public function store(Request $request, CreateOrganisation $create): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        $organisation = $create->handle($this->validated($request, null));

        return redirect('/organisations/'.$organisation->id.'/edit')->with('success', __('core.organisation.created'));
    }

    public function edit(Request $request, Organisation $organisation, OrganisationMembership $membership): Response
    {
        $user = $request->user();
        abort_unless($user->is_super_admin
            || ($user->can('system.organisations.view') && $membership->isMember($user, $organisation->id)), 403);

        return Inertia::render('Core/Organisations/Form', [
            'organisation' => array_merge($organisation->only($organisation->getFillable()), [
                'id' => $organisation->id,
                'registration_type' => $organisation->registration_type->value,
                'state' => $organisation->state->value,
                'daily_rate_basis' => $organisation->daily_rate_basis->value,
                'has_employees' => $organisation->hasEmployees(),
            ]),
            'options' => $this->options(),
            'canManage' => $user->is_super_admin,
        ]);
    }

    public function update(Request $request, Organisation $organisation, UpdateOrganisation $update): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        $update->handle($organisation, $this->validated($request, $organisation));

        return $this->done('common.saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Organisation $organisation): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('organisations', 'name')->ignore($organisation?->id)],
            'short_code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('organisations', 'short_code')->ignore($organisation?->id)],
            'registration_type' => ['required', Rule::enum(RegistrationType::class)],
            'ssm_no' => ['nullable', 'string', 'max:20'],
            'ssm_old_no' => ['nullable', 'string', 'max:20'],
            'kwsp_employer_no' => ['nullable', 'regex:/^\d{1,20}$/'],
            'perkeso_employer_code' => ['nullable', 'string', 'max:12'],
            'lhdn_employer_no' => ['nullable', 'string', 'max:10'],
            'lhdn_hq_employer_no' => ['nullable', 'string', 'max:10'],
            'tax_identification_no' => ['nullable', 'string', 'max:20'],
            'hrd_corp_registered' => ['boolean'],
            'hrd_corp_levy_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'address_line3' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'regex:/^\d{5}$/'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', Rule::enum(MalaysianState::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'daily_rate_basis' => ['required', Rule::enum(DailyRateBasis::class)],
            'daily_rate_divisor' => ['required', 'numeric', 'min:1', 'max:31'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'registration_types' => RegistrationType::options(),
            'states' => MalaysianState::options(),
            'daily_rate_bases' => DailyRateBasis::options(),
        ];
    }
}
