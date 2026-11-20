<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public Pixelforge Webhook (CSRF excluded in bootstrap/app.php)
Route::post('/webhooks/pixelforge', WebhookController::class)->name('webhooks.pixelforge');

// Root redirect
Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('generations.index');
    }
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard redirects directly to image generation studio
    Route::get('/dashboard', function () {
        return redirect()->route('generations.index');
    })->name('dashboard');

    // Generations Studio & History
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/create', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('/generations/{generation}/sync', [GenerationController::class, 'sync'])->name('generations.sync');

    // Credit Balance & Purchases
    Route::post('/credits/topup', [CreditController::class, 'topup'])->name('credits.topup');

    // Admin Reports & Reconciliation
    Route::get('/admin/report', [ReportController::class, 'index'])->name('admin.report');
    Route::post('/admin/report/reconcile', [ReportController::class, 'reconcile'])->name('admin.reconcile');

    // Dev Convenience: Toggle Admin Status
    Route::post('/dev/toggle-admin', function (Request $request) {
        $user = $request->user();
        $user->is_admin = ! $user->is_admin;
        $user->save();
        return back()->with('success', 'Admin role updated to: ' . ($user->is_admin ? 'Admin' : 'Standard User'));
    })->name('dev.toggle-admin');

    // User Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
