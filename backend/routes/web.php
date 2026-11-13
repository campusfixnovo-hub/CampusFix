<?php

use App\Http\Controllers\Admin\CampusController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\SlaPolicyController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TeamWorkloadController;
use App\Http\Controllers\TicketActionController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'home' : 'login'))->name('root');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1')->name('register.store');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1')->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('/home', DashboardController::class)->name('home');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::resource('tickets', TicketController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/tickets/{ticket}/status', [TicketActionController::class, 'status'])->name('tickets.status');
    Route::post('/tickets/{ticket}/priority', [TicketActionController::class, 'priority'])->name('tickets.priority');
    Route::patch('/tickets/{ticket}/location', [TicketActionController::class, 'location'])->name('tickets.location');
    Route::post('/tickets/{ticket}/assignment', [TicketActionController::class, 'assignment'])->name('tickets.assignment');
    Route::post('/tickets/{ticket}/comments', [TicketActionController::class, 'comment'])->name('tickets.comments.store');
    Route::post('/tickets/{ticket}/rating', [TicketActionController::class, 'rating'])->name('tickets.rating');
    Route::get('/attachments/{attachment}', [TicketActionController::class, 'attachment'])->name('attachments.show');
    Route::get('/reports', ReportController::class)->name('reports.index')->middleware('can:viewAny,App\\Models\\Ticket');
    Route::get('/reports/export.csv', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('/team-workload', TeamWorkloadController::class)->name('teams.workload');

    Route::middleware('role:manager')->prefix('manager/teams')->name('manager.teams.')->group(function (): void {
        Route::get('/', [TeamController::class, 'index'])->middleware('can:viewAny,App\\Models\\Team')->name('index');
        Route::get('/{team}/edit', [TeamController::class, 'edit'])->middleware('can:update,team')->name('edit');
        Route::put('/{team}', [TeamController::class, 'update'])->middleware('can:update,team')->name('update');
        Route::delete('/{team}', [TeamController::class, 'destroy'])->middleware('can:deactivate,team')->name('destroy');
    });

    Route::middleware('role:administrator')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users', [UserRoleController::class, 'index'])->name('users.index');
        Route::patch('/users/{user}/active', [UserRoleController::class, 'toggle'])->middleware('can:toggleActive,user')->name('users.active');
        Route::patch('/users/{user}/role', [UserRoleController::class, 'update'])->middleware('can:assignRole,user')->name('users.role');
        Route::resource('teams', TeamController::class)->except(['show']);
        Route::resource('campuses', CampusController::class)->except(['show']);
        Route::get('/places', [CampusController::class, 'places'])->name('places.index');
        Route::post('/places/buildings', [CampusController::class, 'storeBuilding'])->name('places.buildings.store');
        Route::patch('/places/buildings/{building}', [CampusController::class, 'updateBuilding'])->name('places.buildings.update');
        Route::post('/places/locations', [CampusController::class, 'storeLocation'])->name('places.locations.store');
        Route::patch('/places/locations/{location}', [CampusController::class, 'updateLocation'])->name('places.locations.update');
        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::resource('sla', SlaPolicyController::class)->except(['show'])->parameters(['sla' => 'slaPolicy']);
        Route::get('/calendars', [SlaPolicyController::class, 'calendars'])->name('calendars.index');
        Route::post('/calendars', [SlaPolicyController::class, 'saveCalendar'])->name('calendars.store');
        Route::post('/calendars/{calendar}/holidays', [SlaPolicyController::class, 'storeHoliday'])->name('calendars.holidays.store');
        Route::delete('/calendars/{calendar}/holidays/{holiday}', [SlaPolicyController::class, 'deleteHoliday'])->name('calendars.holidays.destroy');
    });

});
