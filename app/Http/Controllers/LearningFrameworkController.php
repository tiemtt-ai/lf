<?php

namespace App\Http\Controllers;

use App\Http\Requests\LearningFrameworkRequest;
use App\Http\Requests\LearningFrameworkVersionRequest;
use App\Http\Requests\LearningNodeDefinitionRequest;
use App\Http\Requests\LearningNodeRequest;
use App\Services\LearningFrameworkAuthoringService;
use App\Services\LearningFrameworkReadService;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class LearningFrameworkController extends Controller
{
    public function __construct(
        private readonly LearningFrameworkAuthoringService $authoring,
        private readonly LearningFrameworkReadService $reader,
    ) {}

    public function index(): View
    {
        $frameworks = $this->reader->listFrameworks((int) request()->user()->id);

        return view('learning-frameworks.index', compact('frameworks'));
    }

    public function create(): View
    {
        return view('learning-frameworks.create');
    }

    public function store(LearningFrameworkRequest $request): RedirectResponse
    {
        return $this->mutate(function () use ($request): RedirectResponse {
            $framework = $this->authoring->createFramework((int) $request->user()->id, $request->command());

            return redirect()->route('admin.learning-frameworks.show', $framework->id)
                ->with('success', __('lf.LF_learning_framework_created'));
        });
    }

    public function show(Request $request, int $framework): View
    {
        $detail = $this->reader->detail((int) $request->user()->id, $framework);
        // ?version= opens that Version read-only in the history tab; a foreign or unknown id opens nothing.
        $viewed = $detail['versions']->firstWhere('id', (int) $request->query('version'));
        $tab = $viewed !== null ? 'history' : (string) $request->query('tab', 'content');
        $tab = in_array($tab, ['content', 'scale', 'history'], true) ? $tab : 'content';

        return view('learning-frameworks.show', [...$detail, 'tab' => $tab, 'viewedVersion' => $viewed]);
    }

    public function update(LearningFrameworkRequest $request, int $framework): RedirectResponse
    {
        return $this->mutate(function () use ($request, $framework): RedirectResponse {
            $this->authoring->updateFramework((int) $request->user()->id, $framework, $request->command());

            return back()->with('success', __('lf.LF_learning_framework_updated'));
        });
    }

    public function storeVersion(LearningFrameworkVersionRequest $request, int $framework): RedirectResponse
    {
        return $this->mutate(function () use ($request, $framework): RedirectResponse {
            $command = $request->command();
            abort_unless((int) $command['framework_id'] === $framework, 422);
            $this->authoring->createDraftVersion((int) $request->user()->id, $command);

            return back()->with('success', __('lf.LF_learning_version_created'));
        });
    }

    public function updateVersion(LearningFrameworkVersionRequest $request, int $framework, int $version): RedirectResponse
    {
        return $this->mutate(function () use ($request, $framework, $version): RedirectResponse {
            $command = $request->command();
            abort_unless((int) $command['framework_id'] === $framework, 422);
            $this->authoring->updateDraftVersion((int) $request->user()->id, $version, $command);

            return back()->with('success', __('lf.LF_learning_version_updated'));
        });
    }

    public function publishVersion(int $framework, int $version): RedirectResponse
    {
        return $this->mutate(function () use ($framework, $version): RedirectResponse {
            $this->assertVersionInFramework($version, $framework);
            $this->authoring->publishVersion((int) request()->user()->id, $version);

            return back()->with('success', __('lf.LF_learning_version_published'));
        });
    }

    /** Throws the working draft away; the published Versions are untouched. */
    public function destroyVersion(int $framework, int $version): RedirectResponse
    {
        return $this->mutate(function () use ($framework, $version): RedirectResponse {
            $this->assertVersionInFramework($version, $framework);
            $this->authoring->discardDraft((int) request()->user()->id, $framework, $version);

            return back()->with('success', __('lf.LF_learning_draft_discarded'));
        });
    }

    public function storeDefinition(LearningNodeDefinitionRequest $request, int $framework): RedirectResponse
    {
        return $this->mutate(function () use ($request, $framework): RedirectResponse {
            $command = $request->command();
            abort_unless((int) $command['framework_id'] === $framework, 422);
            $this->authoring->createDefinition((int) $request->user()->id, $command);

            return back()->with('success', __('lf.LF_learning_definition_created'));
        });
    }

    public function updateDefinition(LearningNodeDefinitionRequest $request, int $framework, int $definition): RedirectResponse
    {
        return $this->mutate(function () use ($request, $framework, $definition): RedirectResponse {
            $command = $request->command();
            abort_unless((int) $command['framework_id'] === $framework, 422);
            $this->authoring->updateDefinition((int) $request->user()->id, $definition, $command);

            return back()->with('success', __('lf.LF_learning_definition_updated'));
        });
    }

    /** Simplified page: a new or existing competency goes into the working draft, created on demand. */
    public function storeCompetency(Request $request, int $framework): RedirectResponse
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);
        $data = $request->validate([
            'node_definition_id' => ['nullable', 'integer', 'min:1'],
            'node_type' => ['required_without:node_definition_id', 'nullable', 'string', 'in:objective,concept,competency'],
            'canonical_name' => ['required_without:node_definition_id', 'nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->mutate(function () use ($request, $framework, $data): RedirectResponse {
            $this->authoring->addCompetency((int) $request->user()->id, $framework, $data);

            return back()->with('success', __('lf.LF_learning_competency_added'));
        });
    }

    public function storeNode(LearningNodeRequest $request, int $framework, int $version): RedirectResponse
    {
        return $this->mutate(function () use ($request, $framework, $version): RedirectResponse {
            $command = $request->command();
            abort_unless((int) $command['framework_version_id'] === $version, 422);
            $this->assertVersionInFramework($version, $framework);
            $this->authoring->createNode((int) $request->user()->id, $command);

            return back()->with('success', __('lf.LF_learning_node_created'));
        });
    }

    public function updateNode(LearningNodeRequest $request, int $framework, int $version, int $node): RedirectResponse
    {
        return $this->mutate(function () use ($request, $framework, $version, $node): RedirectResponse {
            $command = $request->command();
            abort_unless((int) $command['framework_version_id'] === $version, 422);
            $this->assertVersionInFramework($version, $framework);
            $this->authoring->updateDraftNode((int) $request->user()->id, $node, $command);

            return back()->with('success', __('lf.LF_learning_node_updated'));
        });
    }

    private function mutate(callable $callback): RedirectResponse
    {
        try {
            return $callback();
        } catch (RecordsNotFoundException) {
            abort(404);
        } catch (DomainException $exception) {
            // Readable messages for the codes the simplified pages can trigger; others keep the code.
            $readable = [
                'LF_FRAMEWORK_AUTHORING_SCALE_INVALID' => ['mastery_scale', 'lf.LF_learning_simple_error_scale'],
                'LF_FRAMEWORK_AUTHORING_NODE_ALREADY_IN_DRAFT' => ['learning', 'lf.LF_learning_simple_error_already_in_draft'],
            ][$exception->getMessage()] ?? null;

            return back()->withInput()->withErrors($readable
                ? [$readable[0] => __($readable[1])]
                : ['learning' => __('lf.LF_learning_authoring_error', ['code' => $exception->getMessage()])]);
        } catch (QueryException $exception) {
            $detail = (string) ($exception->errorInfo[2] ?? '');
            preg_match('/(?:constraint|key) [`\'"]?([A-Za-z0-9_]+)/i', $detail, $matches);
            Log::warning('Learning authoring database invariant rejected a mutation.', [
                'correlation_id' => request()->header('X-Request-ID') ?: (string) Str::uuid(),
                'sqlstate' => $exception->errorInfo[0] ?? $exception->getCode(),
                'driver_code' => $exception->errorInfo[1] ?? null,
                'constraint' => $matches[1] ?? null,
                'route' => request()->route()?->getName(),
                'customer_id' => TenantContext::customerId(),
                'actor_id' => request()->user()?->id,
            ]);

            return back()->withInput()->withErrors([
                'learning' => __('lf.LF_learning_authoring_conflict'),
            ]);
        }
    }

    private function assertVersionInFramework(int $versionId, int $frameworkId): void
    {
        $exists = $this->reader->versionBelongsToFramework(
            (int) request()->user()->id, $versionId, $frameworkId
        );

        abort_unless($exists, 404);
    }
}
