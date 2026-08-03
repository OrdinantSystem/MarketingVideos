<?php

namespace App\Http\Controllers;

use App\Creative\Automation\CreativeRunOrchestrator;
use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativeMediaDelivery;
use App\Creative\CreativeTraceabilityReader;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Learning\PerformanceRecordStore;
use App\Creative\Rendering\DeterministicRenderService;
use App\Creative\Rendering\RenderStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class CreativeController extends Controller
{
    public function __construct(
        private readonly CreativeWorkspaceRepository $workspace,
        private readonly CreativeRunOrchestrator $automation,
        private readonly CreativeIntegrityAuditor $integrity,
        private readonly CreativeTraceabilityReader $traceability,
        private readonly DeterministicRenderService $renderer,
        private readonly RenderStore $renders,
        private readonly PerformanceRecordStore $performance,
        private readonly CreativeMediaDelivery $mediaDelivery,
    ) {}

    public function index(): RedirectResponse
    {
        $target = $this->workspace->firstWorkspace();

        return redirect()->route('creative.workspace', $target);
    }

    public function create(): Response
    {
        return Inertia::render('creative/create', [
            'projects' => $this->workspace->projects(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'idea' => ['required', 'string', 'max:3000'],
            'start_phase' => ['required', 'in:ideas,concepts'],
        ]);

        $target = $this->workspace->createProject(
            $validated['name'],
            $validated['idea'],
            $validated['start_phase'],
        );

        return redirect()->route('creative.workspace', $target)
            ->with('success', 'Project created. Start the first creative phase when you are ready.');
    }

    public function storeRun(string $project): RedirectResponse
    {
        try {
            $target = $this->workspace->createRun($project);

            return redirect()->route('creative.workspace', $target)
                ->with('success', 'A fresh creative run is ready. Previous runs remain unchanged.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function show(Request $request, string $project, string $run, string $phase): Response
    {
        try {
            $workspace = $this->workspace->workspace(
                $project,
                $run,
                $phase,
                $request->string('artifact')->toString() ?: null,
            );
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        try {
            $automation = $this->automation->status($project, $run);
        } catch (RuntimeException $exception) {
            $automation = ['error' => $exception->getMessage()];
        }
        $integrityReport = $this->integrity->audit($project, $run);

        $renders = array_map(function (array $render) use ($project, $run): array {
            $renderId = is_string($render['id'] ?? null) ? $render['id'] : '';

            return $render + [
                'media_url' => $renderId !== ''
                    ? $this->mediaDelivery->renderAssetUrl($project, $run, $renderId, 'final')
                    : null,
                'audio_url' => $renderId !== ''
                    ? $this->mediaDelivery->renderAssetUrl($project, $run, $renderId, 'audio')
                    : null,
            ];
        }, $this->renders->renders($project, $run));

        return Inertia::render('creative/workspace', $workspace + [
            'automation' => $automation,
            'integrity' => $integrityReport['summary'] + [
                'issues' => $integrityReport['issues'],
            ],
            'traceability' => $this->traceability->phase($project, $run, $phase),
            'renders' => $renders,
            'performance_count' => count($this->performance->records($project, $run)),
            'learning_count' => count($this->performance->learning($project)),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function artifactMedia(
        string $project,
        string $run,
        string $phase,
        string $artifact,
    ): BinaryFileResponse {
        try {
            return $this->inlineMediaResponse(
                $this->mediaDelivery->artifact($project, $run, $phase, $artifact),
            );
        } catch (RuntimeException) {
            abort(404, 'The requested verified artifact binary is unavailable.');
        }
    }

    public function renderMedia(
        string $project,
        string $run,
        string $render,
        string $asset,
    ): BinaryFileResponse {
        try {
            return $this->inlineMediaResponse(
                $this->mediaDelivery->renderAsset($project, $run, $render, $asset),
            );
        } catch (RuntimeException) {
            abort(404, 'The requested verified render asset is unavailable.');
        }
    }

    public function generate(string $project, string $run, string $phase): RedirectResponse
    {
        try {
            $mode = $this->workspace->simulate($project, $run, $phase);

            return back()->with('success', $mode === 'provider'
                ? 'AI outputs created as new immutable files.'
                : 'Sample outputs created as new immutable files.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    /** @deprecated Kept as a simulation-only compatibility route for existing links and tests. */
    public function simulate(string $project, string $run, string $phase): RedirectResponse
    {
        try {
            $this->workspace->simulate($project, $run, $phase, true);

            return back()->with('success', 'Sample outputs created as new immutable files.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function select(Request $request, string $project, string $run, string $phase, string $artifact): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $selection = $this->workspace->select(
                $project,
                $run,
                $phase,
                $artifact,
                reason: $validated['reason'] ?? null,
            );

            if ($selection['next_phase']) {
                return redirect()->route('creative.workspace', [
                    'project' => $project,
                    'run' => $run,
                    'phase' => $selection['next_phase'],
                ])->with('success', 'Selected and handed to the next phase.');
            }

            return back()->with('success', $selection['complete']
                ? 'Selection recorded.'
                : 'Selection recorded. Choose the remaining shots to continue.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function skipIdeas(Request $request, string $project, string $run): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->workspace->skipIdeas(
                $project,
                $run,
                reason: $validated['reason'] ?? null,
            );

            return redirect()->route('creative.workspace', [
                'project' => $project,
                'run' => $run,
                'phase' => 'concepts',
            ])->with('success', 'Ideas explicitly skipped. Concepts now uses the broad project idea; existing Ideas files remain unchanged.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function feedback(Request $request, string $project, string $run, string $phase): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:phase,cycle'],
            'feedback' => ['required', 'string', 'max:5000'],
        ]);

        $this->workspace->appendFeedback($project, $run, $phase, $validated['scope'], $validated['feedback']);

        return back()->with('success', 'Feedback added to the current inbox.');
    }

    public function archiveFeedback(Request $request, string $project, string $run, string $phase): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:phase,cycle'],
        ]);

        try {
            $this->workspace->archiveFeedback($project, $run, $phase, $validated['scope']);

            return back()->with('success', 'Feedback snapshot moved to immutable history.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function startAutomation(Request $request, string $project, string $run): RedirectResponse
    {
        $validated = $request->validate([
            'max_attempts_per_phase' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'max_total_attempts' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'max_simulation_units' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'score_threshold' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'human_gates' => ['sometimes', 'array'],
            'human_gates.*' => ['string', 'in:ideas,concepts,brief-script,images,scenes,sequence'],
        ]);

        try {
            $state = $this->automation->start($project, $run, $validated);

            return back()->with('success', $this->automationMessage($state));
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function resumeAutomation(string $project, string $run): RedirectResponse
    {
        try {
            $state = $this->automation->resume($project, $run);

            return back()->with('success', $this->automationMessage($state));
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function cancelAutomation(string $project, string $run): RedirectResponse
    {
        try {
            $this->automation->cancel($project, $run);

            return back()->with('success', 'Automatic simulation cancelled. Existing files and history were preserved.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function decideAutomation(Request $request, string $project, string $run): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,manual_override,retry_with_feedback,cancel'],
            'feedback' => ['nullable', 'string', 'max:5000', 'required_if:decision,retry_with_feedback'],
            'gate_token' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9.-]*$/'],
        ]);

        try {
            $state = $this->automation->decide(
                $project,
                $run,
                $validated['decision'],
                $validated['feedback'] ?? null,
                $validated['gate_token'],
            );

            return back()->with('success', $this->automationMessage($state));
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function render(string $project, string $run): RedirectResponse
    {
        try {
            $submission = $this->renderer->submit($project, $run);

            return back()->with('success', ($submission['state']['status'] ?? null) === 'completed'
                ? 'A deterministic local render, captions, timing audio, and final manifest were created.'
                : 'The deterministic local render was queued with an immutable request.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function cancelRender(string $project, string $run, string $render): RedirectResponse
    {
        try {
            $this->renderer->cancel($project, $run, $render);

            return back()->with('success', 'Render cancellation recorded without deleting its request or files.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function recordPerformance(Request $request, string $project, string $run, string $render): RedirectResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'max:80'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'observed_at' => ['nullable', 'date'],
            'metrics' => ['required', 'array', 'min:1'],
            'metrics.impressions' => ['sometimes', 'integer', 'min:0'],
            'metrics.views' => ['sometimes', 'integer', 'min:0'],
            'metrics.completions' => ['sometimes', 'integer', 'min:0'],
            'metrics.clicks' => ['sometimes', 'integer', 'min:0'],
            'metrics.conversions' => ['sometimes', 'integer', 'min:0'],
            'metrics.spend_usd' => ['sometimes', 'numeric', 'min:0'],
            'metrics.revenue_usd' => ['sometimes', 'numeric', 'min:0'],
        ]);

        try {
            $this->performance->record(
                $project,
                $run,
                $render,
                $validated['platform'],
                $validated['metrics'],
                $validated['observations'] ?? '',
                $validated['observed_at'] ?? null,
            );

            return back()->with('success', 'Observed performance was stored as immutable evidence.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function summarizePerformance(Request $request, string $project): RedirectResponse
    {
        $validated = $request->validate([
            'primary_metric' => ['required', 'string', 'in:view_rate,completion_rate,ctr,conversion_rate,cpm_usd,cpc_usd,roas'],
        ]);

        try {
            $this->performance->summarize($project, $validated['primary_metric']);

            return back()->with('success', 'A source-hashed performance ranking was stored without claiming causation.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $state */
    private function automationMessage(array $state): string
    {
        return match ($state['status'] ?? null) {
            'complete' => 'Automatic simulation completed with traceable evaluations and selections.',
            'awaiting_review' => 'Automatic simulation paused for human review at '.str_replace('-', ' & ', (string) ($state['current_phase'] ?? 'the current phase')).'.',
            'cancelled' => 'Automatic simulation is cancelled; its checkpoint can be resumed.',
            default => 'Automatic simulation advanced and saved its checkpoint.',
        };
    }

    /**
     * @param  array{path: string, mime_type: string, filename: string, sha256: string}  $media
     */
    private function inlineMediaResponse(array $media): BinaryFileResponse
    {
        $response = response()->file($media['path'], [
            'Content-Type' => $media['mime_type'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $media['filename'],
            ),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ]);
        $response->setEtag($media['sha256']);

        return $response;
    }
}
