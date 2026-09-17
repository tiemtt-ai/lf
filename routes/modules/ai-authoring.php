<?php

use App\Http\Controllers\AiAuthoringController;
use Illuminate\Support\Facades\Route;

Route::prefix('course-templates/{templateId}/activities/{activityId}/ai-authoring')
    ->name('course-templates.activities.ai-authoring.')
    ->where(['templateId' => '[1-9][0-9]*', 'activityId' => '[1-9][0-9]*'])
    ->group(function () use ($registerCourseTemplateLifecycleRoutes): void {
        $routes = [
            ['GET', 'proposals', 'proposals.index'],
            ['POST', 'generation-requests', 'generation-requests.store'],
            ['GET', 'generation-requests/{generationRequestUuid}', 'generation-requests.show'],
            ['GET', 'proposals/{proposalUuid}', 'proposals.show'],
            ['PATCH', 'proposals/{proposalUuid}', 'proposals.update'],
            ['POST', 'proposals/{proposalUuid}/decisions', 'proposals.decide'],
            ['POST', 'proposal-decisions', 'proposals.bulk-decide'],
            ['GET', 'proposals/{proposalUuid}/target-preview', 'proposals.target-preview'],
            ['POST', 'proposals/{proposalUuid}/target-confirmations', 'proposals.confirm-target'],
            ['POST', 'proposals/{proposalUuid}/target-rejections', 'proposals.reject-target'],
            ['GET', 'proposals/{proposalUuid}/context-preview', 'proposals.context-preview'],
            ['POST', 'proposals/{proposalUuid}/context-confirmations', 'proposals.reconfirm-context'],
            ['POST', 'proposals/{proposalUuid}/intent-applications', 'proposals.apply-intent'],
            ['POST', 'proposals/{proposalUuid}/applications/{applicationUuid}/retry', 'proposals.applications.retry'],
            ['POST', 'proposals/{proposalUuid}/applications/{applicationUuid}/cancel', 'proposals.applications.cancel'],
            ['GET', 'proposals/{proposalUuid}/successor-source-scope', 'proposals.successor-source-scope'],
            ['GET', 'proposals/{proposalUuid}/successor-preview', 'proposals.successor-preview'],
            ['POST', 'proposals/{proposalUuid}/successors', 'proposals.successors.store'],
        ];
        if ($registerCourseTemplateLifecycleRoutes) {
            array_push($routes,
                ['POST', 'proposals/{proposalUuid}/node-approvals', 'proposals.approve-node'],
                ['GET', 'proposals/{proposalUuid}/inherited-draft-preview', 'proposals.inherited-draft-preview'],
                ['POST', 'proposals/{proposalUuid}/inherited-drafts', 'proposals.inherit-draft'],
                ['GET', 'proposals/{proposalUuid}/rebase-preview', 'proposals.rebase-preview'],
                ['POST', 'proposals/{proposalUuid}/rebases', 'proposals.rebase-selection'],
            );
        }
        foreach ($routes as [$method, $path, $operation]) {
            Route::match([$method], $path, AiAuthoringController::class)
                ->defaults('authoringOperation', $operation)->name($operation)
                ->whereUuid(['proposalUuid', 'applicationUuid', 'generationRequestUuid']);
        }
    });
