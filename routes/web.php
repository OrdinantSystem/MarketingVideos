<?php

use App\Http\Controllers\CreativeController;
use App\Http\Middleware\EnsureCreativeLocalAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureCreativeLocalAccess::class)->group(function (): void {
    Route::get('/', [CreativeController::class, 'index'])->name('home');
    Route::get('/projects/new', [CreativeController::class, 'create'])->name('creative.projects.create');
    Route::post('/projects', [CreativeController::class, 'store'])->name('creative.projects.store');
    Route::post('/projects/{project}/runs', [CreativeController::class, 'storeRun'])->name('creative.runs.store');
    Route::get('/projects/{project}/runs/{run}/{phase}/artifacts/{artifact}/media', [CreativeController::class, 'artifactMedia'])->name('creative.artifacts.media');
    Route::get('/projects/{project}/runs/{run}/renders/{render}/media/{asset}', [CreativeController::class, 'renderMedia'])
        ->whereIn('asset', ['final', 'audio'])
        ->name('creative.renders.media');
    Route::get('/projects/{project}/runs/{run}/{phase}', [CreativeController::class, 'show'])->name('creative.workspace');
    Route::post('/projects/{project}/runs/{run}/{phase}/generate', [CreativeController::class, 'generate'])->name('creative.generate');
    Route::post('/projects/{project}/runs/{run}/{phase}/simulate', [CreativeController::class, 'simulate'])->name('creative.simulate');
    Route::post('/projects/{project}/runs/{run}/ideas/skip', [CreativeController::class, 'skipIdeas'])->name('creative.ideas.skip');
    Route::post('/projects/{project}/runs/{run}/{phase}/artifacts/{artifact}/select', [CreativeController::class, 'select'])->name('creative.select');
    Route::post('/projects/{project}/runs/{run}/{phase}/feedback', [CreativeController::class, 'feedback'])->name('creative.feedback');
    Route::post('/projects/{project}/runs/{run}/{phase}/feedback/archive', [CreativeController::class, 'archiveFeedback'])->name('creative.feedback.archive');
    Route::post('/projects/{project}/runs/{run}/automation/start', [CreativeController::class, 'startAutomation'])->name('creative.automation.start');
    Route::post('/projects/{project}/runs/{run}/automation/resume', [CreativeController::class, 'resumeAutomation'])->name('creative.automation.resume');
    Route::post('/projects/{project}/runs/{run}/automation/cancel', [CreativeController::class, 'cancelAutomation'])->name('creative.automation.cancel');
    Route::post('/projects/{project}/runs/{run}/automation/decision', [CreativeController::class, 'decideAutomation'])->name('creative.automation.decision');
    Route::post('/projects/{project}/runs/{run}/render', [CreativeController::class, 'render'])->name('creative.render');
    Route::post('/projects/{project}/runs/{run}/renders/{render}/cancel', [CreativeController::class, 'cancelRender'])->name('creative.render.cancel');
    Route::post('/projects/{project}/runs/{run}/renders/{render}/performance', [CreativeController::class, 'recordPerformance'])->name('creative.performance.store');
    Route::post('/projects/{project}/learning', [CreativeController::class, 'summarizePerformance'])->name('creative.learning.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('/dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
