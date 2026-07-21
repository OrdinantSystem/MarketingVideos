<?php

namespace App\Http\Controllers;

use App\Creative\CreativeWorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CreativeController extends Controller
{
    public function __construct(private readonly CreativeWorkspaceRepository $workspace) {}

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

        return Inertia::render('creative/workspace', $workspace + [
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function simulate(string $project, string $run, string $phase): RedirectResponse
    {
        try {
            $this->workspace->simulate($project, $run, $phase);

            return back()->with('success', 'Sample outputs created as new immutable files.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function select(string $project, string $run, string $phase, string $artifact): RedirectResponse
    {
        try {
            $selection = $this->workspace->select($project, $run, $phase, $artifact);

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
}
