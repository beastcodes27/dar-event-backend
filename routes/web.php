<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ManagementController;
use App\Http\Controllers\BanAppealController;
use App\Http\Controllers\BillingController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));
Route::get('/admin/login', [AuthController::class, 'create'])->name('admin.login');
Route::post('/admin/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('admin.authenticate');
Route::get('/account/login', [AuthController::class, 'create'])->name('account.login');
Route::post('/account/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('account.authenticate');
Route::get('/account/banned', [BanAppealController::class, 'show'])->name('account.banned');
Route::post('/account/ban-review', [BanAppealController::class, 'store'])->middleware('throttle:5,1')->name('account.ban-review');
Route::middleware('auth')->group(function (): void {
    Route::post('/admin/logout', [AuthController::class, 'destroy'])->name('admin.logout');
    Route::post('/account/logout', [AuthController::class, 'destroy'])->name('account.logout');
    Route::get('/subscriptions', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/subscriptions/plans/{publisherPlan}/checkout', [BillingController::class, 'checkout'])->middleware('throttle:5,1')->name('billing.checkout');
    Route::get('/subscriptions/payments/{publisherPayment}', [BillingController::class, 'show'])->name('billing.payment');
    Route::post('/subscriptions/payments/{publisherPayment}/check', [BillingController::class, 'check'])->middleware('throttle:20,1')->name('billing.check');
    Route::prefix('admin')->name('admin.')->middleware(EnsureAdmin::class)->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        foreach (['users', 'events', 'stories', 'verification', 'support', 'plans', 'subscriptions', 'payments', 'appeals'] as $section) {
            Route::get('/'.$section, [ManagementController::class, 'index'])->defaults('section', $section)->name($section);
        }
        Route::patch('/users/{user}/unban', [BanAppealController::class, 'unban'])->name('users.unban');
        Route::patch('/users/{user}/ban', [BanAppealController::class, 'ban'])->name('users.ban');
        Route::patch('/appeals/{banAppeal}', [BanAppealController::class, 'review'])->name('appeals.review');
        Route::patch('/users/{user}/verification', [ManagementController::class, 'verifyUser'])->name('users.verify');
        Route::patch('/verification/{verificationRequest}', [ManagementController::class, 'review'])->name('verification.review');
        Route::get('/verification/{verificationRequest}/document', [ManagementController::class, 'document'])->name('verification.document');
        Route::patch('/events/{eventId}/archive', [ManagementController::class, 'archiveEvent'])->whereNumber('eventId')->name('events.archive');
        Route::delete('/stories/{story}', [ManagementController::class, 'deleteStory'])->name('stories.destroy');
        Route::patch('/support/{supportId}', [ManagementController::class, 'reply'])->whereNumber('supportId')->name('support.reply');
        Route::post('/plans', [ManagementController::class, 'createPlan'])->name('plans.store');
        Route::patch('/plans/{publisherPlan}', [ManagementController::class, 'updatePlan'])->name('plans.update');
        Route::post('/payments/{publisherPayment}/verify', [ManagementController::class, 'reconcilePayment'])->middleware('throttle:20,1')->name('payments.verify');
        Route::patch('/subscriptions/{publisherSubscription}/cancel', [ManagementController::class, 'cancelSubscription'])->name('subscriptions.cancel');
    });
});

Route::get('/policies/{document}', fn (string $document) => view('legal', ['policy' => config('legal.'.$document)]))->whereIn('document', ['terms', 'privacy'])->name('policy');
