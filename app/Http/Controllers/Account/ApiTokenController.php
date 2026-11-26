<?php

namespace App\Http\Controllers\Account;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Jetons d'accès à l'API : capacités limitées au rôle, expiration à 90 jours, affichés une seule fois. */
class ApiTokenController extends Controller
{
    public const VALIDITY_DAYS = 90;

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/ApiTokens', [
            'tokens' => $user->tokens()->latest()->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities,
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
                'expiresAt' => $t->expires_at?->toIso8601String(),
                'createdAt' => $t->created_at->toIso8601String(),
            ]),
            'abilities' => self::allowedAbilities($user),
            'newToken' => session('newToken'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(array_column(self::allowedAbilities($user), 'value'))],
        ]);
        $token = $user->createToken($data['name'], array_values(array_unique($data['abilities'])), now()->addDays(self::VALIDITY_DAYS));
        $audit->record('api_token_created', null, $user, ['name' => $data['name'], 'abilities' => $data['abilities']]);

        // Le jeton en clair n'est affiché qu'une fois : seule son empreinte est conservée en base.
        return back()->with('newToken', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token, AuditLogger $audit): RedirectResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->first();
        abort_if($deleted === null, 404);
        $deleted->delete();
        $audit->record('api_token_revoked', null, $request->user(), ['name' => $deleted->name]);

        return back()->with('success', "Jeton « {$deleted->name} » révoqué.");
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function allowedAbilities(User $user): array
    {
        $abilities = [['value' => 'documents:read', 'label' => 'Lire et rechercher les documents']];
        if ($user->canContribute()) {
            $abilities[] = ['value' => 'documents:write', 'label' => 'Déposer des documents et des versions, les soumettre'];
        }
        if ($user->role === Role::Validator) {
            $abilities[] = ['value' => 'tasks:decide', 'label' => 'Consulter et viser ses étapes de validation'];
        }

        return $abilities;
    }
}
