<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            // Comptes fictifs créés par les données de démonstration (mot de passe : password).
            'demoAccounts' => config('app.env') === 'production' ? [] : [
                ['email' => 'admin@gedflow.test', 'label' => 'Administrateur GED'],
                ['email' => 'sara.contributrice@gedflow.test', 'label' => 'Contributrice · Achats'],
                ['email' => 'karim.valideur@gedflow.test', 'label' => 'Valideur · Achats'],
                ['email' => 'nadia.finance@gedflow.test', 'label' => 'Valideuse · Finance'],
                ['email' => 'youssef.juridique@gedflow.test', 'label' => 'Valideur · Juridique'],
            ],
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 5 tentatives par minute et par couple e-mail/adresse IP, contre les attaques par force brute.
        $key = Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($key).' secondes.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $audit->record('login', null, $request->user());

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
