<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ProfileController as ApiProfileController;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Support\AccountBans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.profile', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request, ApiProfileController $profiles): RedirectResponse
    {
        if ($request->has('email') && $request->input('email') !== $request->user()->email && ! Hash::check((string) $request->input('current_password'), $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Enter your current password to change your email address.']);
        }
        $profiles->update($request);

        return back()->with('status', 'Your profile has been updated.');
    }

    public function password(Request $request, AccountBans $sessions): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']]);
        $user = DB::transaction(function () use ($request, $data, $sessions): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
            }
            $user->forceFill(['password' => $data['password']])->save();
            $sessions->revokeAccess($user);

            return $user;
        });
        Auth::guard('web')->setUser($user);
        $request->session()->regenerate();
        $request->session()->put('account_auth_version', $user->auth_session_version);
        $request->session()->put('account_ban_version', $user->ban_version);

        return back()->with('status', 'Your password has been changed. Other sessions have been signed out.');
    }

    public function avatar(Request $request): RedirectResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);
        $path = $request->file('avatar')->store('avatars', 'public');
        try {
            $old = DB::transaction(function () use ($request, $path): ?string {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $old = $user->avatar_path;
                $user->update(['avatar_path' => $path]);

                return $old;
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return back()->with('status', 'Your profile photo has been updated.');
    }
}
