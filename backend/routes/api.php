<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TicketFileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'api.active'])->group(function (): void {
        Route::get('/catalogs', CatalogController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/tickets', [TicketController::class, 'index']);
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
        Route::patch('/tickets/{ticket}', [TicketController::class, 'update']);
        Route::post('/tickets/{ticket}/status', [TicketController::class, 'status']);
        Route::get('/tickets/{ticket}/assignment-options', [TicketController::class, 'assignmentOptions']);
        Route::post('/tickets/{ticket}/assignment', [TicketController::class, 'assignment']);
        Route::get('/tickets/{ticket}/history', [TicketController::class, 'history']);
        Route::get('/tickets/{ticket}/comments', [CommentController::class, 'index']);
        Route::post('/tickets/{ticket}/comments', [CommentController::class, 'store']);
        Route::post('/tickets/{ticket}/attachments', [TicketFileController::class, 'store']);
        Route::get('/tickets/{ticket}/attachments', [TicketFileController::class, 'index']);
        Route::get('/attachments/{attachment}', [TicketFileController::class, 'download'])->name('api.v1.attachments.download');
        Route::get('/tickets/{ticket}/rating', [TicketController::class, 'rating']);
        Route::post('/tickets/{ticket}/rating', [TicketController::class, 'rate']);

        Route::get('/dashboard', DashboardController::class);
        Route::get('/reports', ReportController::class);
        Route::get('/reports/export.csv', [ReportController::class, 'export']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    });
});
