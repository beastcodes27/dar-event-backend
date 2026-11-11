<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\PublisherPayment;
use App\Models\PublisherSubscription;
use App\Models\Story;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Support\SonicPesa;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(SonicPesa $gateway): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'Users' => User::count(), 'Published events' => Event::withoutGlobalScopes()->whereNull('archived_at')->count(),
                'Active stories' => Story::active()->count(), 'Verification queue' => VerificationRequest::where('status', 'pending')->count(),
                'Active subscriptions' => PublisherSubscription::where('status', 'active')->where('ends_at', '>', now())->count(),
                'Open support requests' => DB::table('support_requests')->whereIn('status', ['open', 'in_progress'])->count(),
            ],
            'payments' => PublisherPayment::with('user', 'plan')->latest()->limit(8)->get(),
            'configured' => $gateway->configured(),
        ]);
    }
}
