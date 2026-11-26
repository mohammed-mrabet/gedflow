<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Delegation;
use App\Models\ReviewTask;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Qui peut viser quoi, aujourd'hui : un valideur vise les étapes de son service,
 * et un remplaçant celles du service d'un valideur absent qui lui a délégué ses visas.
 * Les délégations ne se transmettent pas en cascade (un remplaçant ne peut pas re-déléguer).
 */
class ValidationRights
{
    /** @return Collection<int, int> identifiants des services dont l'utilisateur peut viser les étapes */
    public static function departmentsFor(User $user): Collection
    {
        $own = $user->role === Role::Validator && $user->department_id ? collect([$user->department_id]) : collect();

        return $own->merge(self::activeDelegationsTo($user)->map(fn (Delegation $d) => $d->delegator->department_id))
            ->filter()->unique()->values();
    }

    /** Le valideur absent pour le compte duquel l'utilisateur vise cette étape (null s'il la vise en son nom propre). */
    public static function onBehalfOf(User $user, ReviewTask $task): ?User
    {
        if ($user->role === Role::Validator && $user->department_id === $task->department_id) {
            return null;
        }

        return self::activeDelegationsTo($user)
            ->first(fn (Delegation $d) => $d->delegator->department_id === $task->department_id)?->delegator;
    }

    /** Remplaçants actifs des valideurs d'un service (destinataires des notifications de l'étape). */
    public static function delegatesForDepartment(int $departmentId): Collection
    {
        return Delegation::active()->with('delegate')
            ->whereHas('delegator', fn ($q) => $q->where('role', Role::Validator)->where('department_id', $departmentId))
            ->get()->pluck('delegate');
    }

    /** @return Collection<int, Delegation> */
    private static function activeDelegationsTo(User $user): Collection
    {
        return Delegation::active()->with('delegator')
            ->where('delegate_id', $user->id)
            ->whereHas('delegator', fn ($q) => $q->where('role', Role::Validator))
            ->get();
    }
}
