<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CommentLikeController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventLikeController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecentSearchController;
use App\Http\Controllers\Api\SavedEventController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\StoryHighlightController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('messages', [MessageController::class, 'index']);
    Route::get('messages/unread-count', [MessageController::class, 'unreadCount']);
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

    Route::post('events/{event}/like', [EventLikeController::class, 'toggle']);
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
    Route::delete('stories/{story}', [StoryController::class, 'destroy']);

    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('events/{event}/tickets', [TicketController::class, 'store']);
    Route::get('tickets/{ticket}', [TicketController::class, 'show']);
    Route::post('tickets/{ticket}/cancel', [TicketController::class, 'cancel']);
});
