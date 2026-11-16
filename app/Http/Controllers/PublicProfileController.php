<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Media;
use Illuminate\View\View;

class PublicProfileController extends Controller
{
    public function __invoke(User $user): View
    {
        abort_if($user->isBanned(), 404);
        $user->loadCount(['followers', 'following', 'events']);

        return view('profile', [
            'user' => $user,
            'avatar' => Media::url($user->avatar_path),
            'events' => $user->events()->where('is_public', true)->latest('starts_at')->limit(12)->get(),
        ]);
    }
}
