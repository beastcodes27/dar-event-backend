<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CommentLikeController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventLikeController;
use App\Http\Controllers\Api\EventShareController;
use App\Http\Controllers\Api\EventVideoController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\LegalController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecentSearchController;
use App\Http\Controllers\Api\SavedEventController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\StoryHighlightController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\BanAppealController;
use App\Http\Controllers\BillingController;
use App\Http\Middleware\EnsureAccountActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('recovery/find', [PasswordRecoveryController::class, 'find'])->middleware('throttle:5,1,recovery-find');
    Route::post('recovery/send', [PasswordRecoveryController::class, 'send'])->middleware('throttle:3,1,recovery-send');
    Route::post('recovery/verify', [PasswordRecoveryController::class, 'verify'])->middleware('throttle:10,1,recovery-verify');
    Route::post('recovery/reset', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:5,1,recovery-reset');
    Route::post('availability', [AuthController::class, 'availability'])->middleware('throttle:60,1,registration-availability');
    Route::post('register', [AuthController::class, 'register']);
    Route::post('ban-review', [BanAppealController::class, 'store'])->middleware('throttle:5,1');
    Route::post('login', [AuthController::class, 'login']);
});

Route::get('legal', [LegalController::class, 'index']);
Route::get('legal/{document}', [LegalController::class, 'show'])->whereIn('document', ['terms', 'privacy']);

Route::get('categories', fn () => response()->json([
    'data' => [
        ['id' => 'for_you', 'label' => 'For You'],
        ['id' => 'music', 'label' => 'Music'],
        ['id' => 'university', 'label' => 'University'],
        ['id' => 'birthday', 'label' => 'Birthday'],
        ['id' => 'weddings', 'label' => 'Weddings'],
        ['id' => 'education', 'label' => 'Education'],
        ['id' => 'business', 'label' => 'Business'],
        ['id' => 'other', 'label' => 'Other'],
    ],
    'profile_categories' => [
        'Digital Creator', "NGO's", 'Education', 'Event Organizer', 'Artist', 'Business', 'Personal',
    ],
]));

Route::get('events', [EventController::class, 'index']);
Route::get('events/{event}', [EventController::class, 'show']);
Route::get('events/{event}/comments', [CommentController::class, 'index']);
Route::get('events/{event}/video/{streamId}/{asset}', [EventVideoController::class, 'stream'])->whereUuid('streamId')->where('asset', '.*');

Route::get('search', SearchController::class);

Route::get('users/{user:username}', [UserController::class, 'show']);
Route::get('users/{user:username}/events', [UserController::class, 'events']);
Route::get('users/{user:username}/followers', [UserController::class, 'followers']);
Route::get('users/{user:username}/following', [UserController::class, 'following']);
Route::get('users/{user:username}/highlights', [StoryHighlightController::class, 'index']);
Route::get('users/{user:username}/stories', [StoryController::class, 'index']);

Route::get('stories', [StoryController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', EnsureAccountActive::class])->group(function () {
    Route::get('onboarding/suggestions', [OnboardingController::class, 'suggestions']);
    Route::patch('onboarding/progress', [OnboardingController::class, 'progress']);
    Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->middleware('throttle:10,1');
    Route::patch('presence', [PresenceController::class, 'update'])->middleware('throttle:30,1');
    Route::patch('settings/privacy', [SettingsController::class, 'privacy']);
    Route::patch('settings/theme', [SettingsController::class, 'theme']);
    Route::get('settings/activity', [SettingsController::class, 'activity']);
    Route::delete('settings/sessions', [SettingsController::class, 'revokeSessions']);
    Route::get('settings/archived-posts', [SettingsController::class, 'archived']);
    Route::patch('settings/posts/{eventId}/archive', [SettingsController::class, 'archive'])->whereNumber('eventId');
    Route::get('settings/blocked-accounts', [SettingsController::class, 'blocked']);
    Route::post('settings/blocked-accounts/{user:username}', [SettingsController::class, 'block']);
    Route::delete('settings/blocked-accounts/{user:username}', [SettingsController::class, 'unblock']);
    Route::get('settings/support', [SettingsController::class, 'support']);
    Route::post('settings/support', [SettingsController::class, 'contact'])->middleware('throttle:10,1');
    Route::get('admin/support', [SettingsController::class, 'adminSupport']);
    Route::patch('admin/support/{supportId}', [SettingsController::class, 'reply'])->whereNumber('supportId');
    Route::get('messages', [MessageController::class, 'index']);
    Route::get('messages/unread-count', [MessageController::class, 'unreadCount']);
    Route::get('messages/{message}/attachment', [MessageController::class, 'attachment'])->name('messages.attachment');
    Route::patch('messages/{message}', [MessageController::class, 'update'])->middleware('throttle:60,1');
    Route::delete('messages/{message}', [MessageController::class, 'destroy'])->middleware('throttle:60,1');
    Route::patch('message-requests/{messageRequest}', [MessageController::class, 'resolveRequest']);
    Route::get('users/{user:username}/messages', [MessageController::class, 'show']);
    Route::post('users/{user:username}/messages', [MessageController::class, 'store'])->middleware('throttle:60,1');

    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('password', [AuthController::class, 'updatePassword']);
    });

    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'avatar']);
    Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar']);

    Route::get('feed', [EventController::class, 'feed']);
    Route::get('my-events', [EventController::class, 'myEvents']);
    Route::post('events', [EventController::class, 'store']);
    Route::match(['put', 'patch'], 'events/{event}', [EventController::class, 'update']);
    Route::delete('events/{event}', [EventController::class, 'destroy']);
    Route::post('events/{event}/video', [EventVideoController::class, 'store'])->middleware('throttle:5,1,event-video');
    Route::delete('events/{event}/video', [EventVideoController::class, 'destroy']);

    Route::post('events/{event}/like', [EventLikeController::class, 'toggle']);
    Route::post('events/{event}/share', [EventShareController::class, 'store'])->middleware('throttle:60,1');
    Route::post('events/{event}/save', [SavedEventController::class, 'toggle']);
    Route::get('saved-events', [SavedEventController::class, 'index']);

    Route::post('events/{event}/comments', [CommentController::class, 'store']);
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);
    Route::post('comments/{comment}/like', [CommentLikeController::class, 'toggle']);

    Route::post('users/{user:username}/follow', [FollowController::class, 'toggle']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);

    Route::get('recent-searches', [RecentSearchController::class, 'index']);
    Route::post('recent-searches', [RecentSearchController::class, 'store']);
    Route::delete('recent-searches', [RecentSearchController::class, 'clear']);
    Route::delete('recent-searches/{recentSearch}', [RecentSearchController::class, 'destroy']);

    Route::get('verification-requests', [VerificationController::class, 'index']);
    Route::post('verification-requests', [VerificationController::class, 'store']);

    Route::get('story-highlights', [StoryHighlightController::class, 'index']);
    Route::post('story-highlights', [StoryHighlightController::class, 'store']);
    Route::delete('story-highlights/{storyHighlight}', [StoryHighlightController::class, 'destroy']);

    Route::post('stories', [StoryController::class, 'store']);
    Route::post('stories/{story}/view', [StoryController::class, 'view']);
    Route::post('stories/{story}/like', [StoryController::class, 'like']);
    Route::get('stories/{story}/views', [StoryController::class, 'viewers']);
    Route::delete('stories/{story}', [StoryController::class, 'destroy']);

    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('events/{event}/tickets', [TicketController::class, 'store']);
    Route::get('tickets/{ticket}', [TicketController::class, 'show']);
    Route::post('tickets/{ticket}/cancel', [TicketController::class, 'cancel']);
});

Route::post('billing/sonicpesa/webhook', [BillingController::class, 'webhook'])->middleware('throttle:120,1');
