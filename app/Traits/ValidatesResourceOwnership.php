<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Access\AuthorizationException;

trait ValidatesResourceOwnership
{
    protected function validateTaskOwnership($task, ?string $userIdField = 'user_id'): void
    {
        if (!$task) {
            return;
        }

        $taskUserId = is_object($task) ? $task->{$userIdField} : ($task[$userIdField] ?? null);
        $currentUserId = Auth::id();

        if ($taskUserId !== $currentUserId) {
            throw new AuthorizationException('Unauthorized access to this resource');
        }
    }

    protected function validateProjectOwnership(?int $projectId): void
    {
        if (!$projectId) {
            return;
        }

        $project = \App\Models\Project::where('id', $projectId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$project) {
            throw new AuthorizationException('Project not found or you do not have access to it');
        }
    }

    protected function validateSeoTaskOwnership(\App\Models\SeoTask $task): void
    {
        if ($task->project && $task->project->user_id !== Auth::id()) {
            throw new AuthorizationException('Unauthorized access to this task');
        }
    }
}

