<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Delegation;
use App\Models\ReviewTask;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** Données partagées par toutes les pages : utilisateur connecté, compteurs, messages flash. */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'roleLabel' => $user->role->label(),
                    'department' => $user->department?->name,
                    'jobTitle' => $user->job_title,
                ],
            ] : null,
            'nav' => $user ? fn () => [
                'delegations' => $user->role === Role::Validator || $user->isAdmin(),
                'statistics' => $user->role === Role::Validator || $user->isAdmin(),
                'activeDelegation' => Delegation::active()->where('delegate_id', $user->id)->with('delegator')->first()?->delegator?->name,
            ] : null,
            'counters' => $user ? fn () => [
                'tasks' => ReviewTask::actionableBy($user)->count(),
                'notifications' => $user->unreadNotifications()->count(),
            ] : null,
            'notifications' => $user ? fn () => $user->notifications()->latest()->limit(8)->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'message' => $n->data['message'] ?? '',
                    'kind' => $n->data['kind'] ?? null,
                    'documentId' => $n->data['document_id'] ?? null,
                    'read' => $n->read_at !== null,
                    'createdAt' => $n->created_at->toIso8601String(),
                ]) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
