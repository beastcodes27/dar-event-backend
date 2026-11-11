<?php

namespace App\Http\Middleware;

use App\Support\AccountBans;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $expired = $user && ! $request->is('api/*') && $request->hasSession() && (int) $request->session()->get('account_ban_version', 0) !== $user->ban_version;
        if (! $user || (! $user->isBanned() && ! $expired)) {
            return $next($request);
        }
        $details = $user->isBanned() ? app(AccountBans::class)->details($user) : null;
        if ($request->is('api/*')) {
            return response()->json(['message' => 'Your account is banned.', 'code' => 'account_banned', 'ban' => $details], 403);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $details ? 'Your account is banned.' : 'Your session expired. Please sign in again.', 'code' => $details ? 'account_banned' : 'session_expired', 'ban' => $details], $details ? 403 : 401);
        }

        return $details ? redirect()->route('account.banned')->with('ban', $details) : redirect()->route($request->is('admin*') ? 'admin.login' : 'account.login');
    }
}
