<?php

use App\Http\Controllers\CreativeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CreativeController::class, 'index'])->name('home');
Route::get('/projects/new', [CreativeController::class, 'create'])->name('creative.projects.create');
Route::post('/projects', [CreativeController::class, 'store'])->name('creative.projects.store');
Route::post('/projects/{project}/runs', [CreativeController::class, 'storeRun'])->name('creative.runs.store');
Route::get('/projects/{project}/runs/{run}/{phase}', [CreativeController::class, 'show'])->name('creative.workspace');
Route::post('/projects/{project}/runs/{run}/{phase}/simulate', [CreativeController::class, 'simulate'])->name('creative.simulate');
Route::post('/projects/{project}/runs/{run}/{phase}/artifacts/{artifact}/select', [CreativeController::class, 'select'])->name('creative.select');
Route::post('/projects/{project}/runs/{run}/{phase}/feedback', [CreativeController::class, 'feedback'])->name('creative.feedback');
Route::post('/projects/{project}/runs/{run}/{phase}/feedback/archive', [CreativeController::class, 'archiveFeedback'])->name('creative.feedback.archive');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('/dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
