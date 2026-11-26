<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\StatisticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __invoke(Request $request, StatisticsService $statistics): Response
    {
        abort_unless($request->user()->isAdmin() || $request->user()->role === Role::Validator, 403);
        $request->validate(['period' => ['nullable', 'in:30,90,180,365']]);
        $days = (int) $request->input('period', 180);

        return Inertia::render('Statistics', $statistics->compute($days));
    }
}
