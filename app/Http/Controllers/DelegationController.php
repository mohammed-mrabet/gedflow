<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Delegation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Absences des valideurs : délégation temporaire des visas à un remplaçant. */
class DelegationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->role === Role::Validator || $user->isAdmin(), 403);

        $query = Delegation::with(['delegator.department', 'delegate.department'])->latest('starts_on')->latest('id');
        if (! $user->isAdmin()) {
            $query->where(fn ($q) => $q->where('delegator_id', $user->id)->orWhere('delegate_id', $user->id));
        }

        return Inertia::render('Delegations/Index', [
            'delegations' => $query->limit(50)->get()->map(fn (Delegation $d) => [
                'id' => $d->id,
                'delegator' => $d->delegator->name.' ('.$d->delegator->department?->name.')',
                'delegate' => $d->delegate->name.' ('.$d->delegate->department?->name.')',
                'startsOn' => $d->starts_on->toDateString(),
                'endsOn' => $d->ends_on->toDateString(),
                'reason' => $d->reason,
                'state' => $d->cancelled_at ? 'cancelled' : ($d->isActive() ? 'active' : ($d->ends_on->isPast() ? 'past' : 'upcoming')),
                'mine' => $d->delegator_id === $user->id,
                'canCancel' => $d->cancelled_at === null && ! $d->ends_on->isPast() && ($d->delegator_id === $user->id || $user->isAdmin()),
            ]),
            'canDelegate' => $user->role === Role::Validator,
            'candidates' => $user->role === Role::Validator
                ? User::whereKeyNot($user->id)->whereIn('role', [Role::Validator, Role::Contributor])->with('department')->orderBy('name')->get()
                    ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'department' => $u->department?->name])
                : [],
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role === Role::Validator, 403);
        $max = config('gedflow.delegation_max_days');
        $data = $request->validate([
            'delegate_id' => ['required', Rule::exists('users', 'id')->whereIn('role', [Role::Validator->value, Role::Contributor->value]), Rule::notIn([$user->id])],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [
            'delegate_id.not_in' => 'Choisissez une autre personne que vous-même.',
        ]);

        if (Carbon::parse($data['starts_on'])->diffInDays(Carbon::parse($data['ends_on'])) > $max) {
            throw ValidationException::withMessages(['ends_on' => "Une délégation dure au plus {$max} jours."]);
        }

        $overlaps = Delegation::where('delegator_id', $user->id)->whereNull('cancelled_at')
            ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['starts_on' => 'Vous avez déjà une délégation sur cette période.']);
        }

        $delegation = Delegation::create([...$data, 'delegator_id' => $user->id]);
        $audit->record('delegation_created', null, $user, [
            'delegate' => $delegation->delegate->name, 'from' => $data['starts_on'], 'to' => $data['ends_on'],
        ]);

        return back()->with('success', "Délégation enregistrée : {$delegation->delegate->name} visera les étapes de votre service pendant votre absence.");
    }

    public function cancel(Request $request, Delegation $delegation, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($delegation->delegator_id === $user->id || $user->isAdmin(), 403);
        $delegation->forceFill(['cancelled_at' => now()])->save();
        $audit->record('delegation_cancelled', null, $user, ['delegate' => $delegation->delegate->name]);

        return back()->with('success', 'Délégation annulée.');
    }
}
