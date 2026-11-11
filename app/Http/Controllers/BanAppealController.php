<?php

namespace App\Http\Controllers;

use App\Models\BanAppeal;
use App\Models\User;
use App\Support\AccountBans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BanAppealController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('ban')) {
            return redirect()->route('account.login');
        }
        $ban = $request->session()->get('ban');
        $request->session()->keep('ban');

        return view('account-banned', compact('ban'));
    }

    public function store(Request $request, AccountBans $bans): JsonResponse|RedirectResponse
    {
        if ($request->hasSession()) {
            $request->session()->keep('ban');
        }
        $data = $request->validate(['review_token' => ['required', 'string', 'max:4096'], 'message' => ['required', 'string', 'min:10', 'max:2000']]);
        $appeal = $bans->submit($data['review_token'], $data['message']);
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Review requested. Your account remains banned while the review is pending.', 'data' => ['status' => $appeal->status]], 201);
        }
        $ban = $bans->details($appeal->user);

        return redirect()->route('account.banned')->with('ban', $ban);
    }

    public function ban(Request $request, User $user, AccountBans $bans): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $bans->ban($user, $request->user(), $data['reason']);

        return back()->with('status', 'Account banned. All login sessions have been revoked.');
    }

    public function review(Request $request, BanAppeal $banAppeal, AccountBans $bans): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected'], 'decision_reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $bans->review($banAppeal, $request->user(), $data['status'], $data['decision_reason']);

        return back()->with('status', $data['status'] === 'accepted' ? 'Review accepted. The account can sign in again.' : 'Review rejected. The account is permanently banned.');
    }
}
