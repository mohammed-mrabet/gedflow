<?php

use App\Http\Controllers\Account\ApiTokenController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DelegationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReviewTaskController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

// Page publique ouverte par le QR code d'un certificat de validation.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/verifier/{token}', [VerificationController::class, 'show'])->name('verification.show');
    Route::post('/verifier/{token}', [VerificationController::class, 'check'])->name('verification.check');
});

// Documentation de l'API (OpenAPI 3).
Route::view('/api/docs', 'api-docs')->name('api.docs');
Route::get('/api/openapi.json', fn () => response()->json(json_decode(file_get_contents(resource_path('openapi/openapi.json')), true)))->name('api.spec');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/nouveau', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::put('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{document}/versions', [DocumentController::class, 'storeVersion'])->name('documents.versions.store');
    Route::get('/documents/{document}/versions/{version}/fichier', [DocumentController::class, 'download'])->name('documents.versions.download');
    Route::post('/documents/{document}/versions/{version}/verification', [DocumentController::class, 'verify'])->name('documents.versions.verify');
    Route::post('/documents/{document}/soumettre', [DocumentController::class, 'submit'])->name('documents.submit');
    Route::post('/documents/{document}/retirer', [DocumentController::class, 'withdraw'])->name('documents.withdraw');
    Route::get('/documents/{document}/certificat', [DocumentController::class, 'certificate'])->name('documents.certificate');
    Route::post('/documents/{document}/archiver', [DocumentController::class, 'archive'])->name('documents.archive');
    Route::post('/documents/{document}/partages', [DocumentController::class, 'share'])->name('documents.share');
    Route::delete('/documents/{document}/partages/{user}', [DocumentController::class, 'unshare'])->name('documents.unshare');

    Route::get('/validations', [ReviewTaskController::class, 'index'])->name('tasks.index');
    Route::post('/validations/{task}/decision', [ReviewTaskController::class, 'decide'])->name('tasks.decide');

    Route::get('/absences', [DelegationController::class, 'index'])->name('delegations.index');
    Route::post('/absences', [DelegationController::class, 'store'])->name('delegations.store');
    Route::post('/absences/{delegation}/annuler', [DelegationController::class, 'cancel'])->name('delegations.cancel');

    Route::get('/statistiques', StatisticsController::class)->name('statistics');

    Route::get('/profil/api', [ApiTokenController::class, 'index'])->name('tokens.index');
    Route::post('/profil/api', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/profil/api/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

    Route::post('/notifications/lues', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::get('/admin/circuits', [WorkflowController::class, 'index'])->name('admin.workflows');
    Route::put('/admin/circuits/{type}', [WorkflowController::class, 'update'])->name('admin.workflows.update');
    Route::get('/admin/journal', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/admin/journal/export', [AuditController::class, 'export'])->name('audit.export');
});
