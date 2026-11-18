<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OrgSwitcher (A4): only to organisations the user is a member of (all for Super Admin).
 */
final class OrganisationSwitchController extends Controller
{
    public function update(Request $request, OrganisationMembership $membership): RedirectResponse
    {
        $data = $request->validate(['organisation_id' => ['required', 'integer']]);
        $user = $request->user();

        $allowed = $membership->organisationsFor($user)->pluck('id')->all();
        abort_unless(in_array((int) $data['organisation_id'], $allowed, true), 403);

        $user->forceFill(['current_organisation_id' => (int) $data['organisation_id']])->save();
        $request->session()->put('organisation_id', (int) $data['organisation_id']);

        return redirect('/dashboard');
    }
}
