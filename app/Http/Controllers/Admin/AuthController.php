<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AccountBans;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $admin = $request->routeIs('admin.*');
        if ($request->user() && (! $admin || $request->user()->is_admin)) {
            return redirect()->route($admin ? 'admin.dashboard' : 'billing.index');
        }

        return view('admin.login', compact('admin'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $admin = $request->routeIs('admin.*');
        $phone = PhoneNumber::normalize($data['login']);
        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : (preg_match('/^\\+[1-9][0-9]{7,14}$/D', $phone ?? '') ? 'phone' : 'username');
        $credentials = [$field => $field === 'phone' ? $phone : $data['login'], 'password' => $data['password']];
        if ($admin) {
            $credentials['is_admin'] = true;
        }
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['login' => 'These credentials do not have access to this portal.']);
        }
        if ($request->user()->isBanned()) {
            $details = app(AccountBans::class)->details($request->user());
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('account.banned')->with('ban', $details);
        }
        $request->session()->regenerate();
        $request->session()->put('account_ban_version', $request->user()->ban_version);
        $request->session()->put('account_auth_version', $request->user()->auth_session_version);

        return redirect()->route($admin ? 'admin.dashboard' : 'billing.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($request->routeIs('admin.*') ? 'admin.login' : 'account.login');
    }
}
