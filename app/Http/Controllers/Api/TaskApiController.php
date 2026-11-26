<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\ReviewTask;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TaskApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TaskResource::collection(
            ReviewTask::actionableBy($request->user())->with(['document', 'department'])->orderBy('due_at')->get()
        );
    }

    public function decide(Request $request, ReviewTask $task, WorkflowService $workflow): TaskResource
    {
        Gate::authorize('decide', $task);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $workflow->decide($task, $request->user(), $data['decision'] === 'approve', $data['comment'] ?? null);

        return new TaskResource($task->fresh(['document', 'department', 'decider']));
    }
}
