<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DocumentType;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Paramétrage des types de documents et de leurs circuits de validation (administrateur). */
class WorkflowController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Admin/Workflows', [
            'types' => DocumentType::with('steps.department')->withCount('documents')->orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'description' => $t->description,
                'retentionYears' => $t->retention_years,
                'documentsCount' => $t->documents_count,
                'steps' => $t->steps->map(fn ($s) => [
                    'name' => $s->name, 'departmentId' => $s->department_id, 'dueHours' => $s->due_hours,
                ]),
            ]),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Remplace le circuit d'un type. Les documents déjà en validation gardent les étapes déjà créées :
     * le nouveau circuit s'applique aux étapes suivantes et aux prochaines soumissions.
     */
    public function update(Request $request, DocumentType $type, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'retention_years' => ['required', 'integer', 'min:1', 'max:99'],
            'steps' => ['array', 'max:6'],
            'steps.*.name' => ['required', 'string', 'max:80'],
            'steps.*.department_id' => ['nullable', 'exists:departments,id'],
            'steps.*.due_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        DB::transaction(function () use ($type, $data) {
            $type->update(['retention_years' => $data['retention_years']]);
            $type->steps()->delete();
            foreach (array_values($data['steps'] ?? []) as $i => $step) {
                $type->steps()->create([...$step, 'position' => $i + 1]);
            }
        });
        $audit->record('workflow_updated', null, $request->user(), ['type' => $type->code, 'steps' => count($data['steps'] ?? [])]);

        return back()->with('success', "Circuit « {$type->name} » enregistré.");
    }
}
