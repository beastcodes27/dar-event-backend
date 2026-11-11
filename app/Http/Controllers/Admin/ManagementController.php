<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\PublisherPayment;
use App\Models\PublisherPlan;
use App\Models\PublisherSubscription;
use App\Models\Story;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Support\SubscriptionPayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementController extends Controller
{
    public function index(Request $request): View
    {
        $section = $request->route('section');
        $q = mb_substr(trim((string) $request->query('q')), 0, 100);
        $query = match ($section) {
            'users' => User::query()->when($q, fn ($query) => $query->where(fn ($query) => $query->where('username', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%'))),
            'events' => Event::withoutGlobalScopes()->with('host')->when($q, fn ($query) => $query->where('title', 'like', '%'.$q.'%')),
            'stories' => Story::with('user')->withCount('viewers', 'likes'),
            'verification' => VerificationRequest::with('user'),
            'support' => DB::table('support_requests')->join('users', 'users.id', '=', 'support_requests.user_id')->select('support_requests.*', 'users.username')->orderByDesc('support_requests.id'),
            'plans' => PublisherPlan::query(),
            'subscriptions' => PublisherSubscription::with('user', 'plan'),
            'payments' => PublisherPayment::with('user', 'plan'),
            default => abort(404),
        };
        $items = ($section === 'support' ? $query : $query->latest('id'))->paginate(20)->withQueryString();

        return view('admin.manage', compact('section', 'items', 'q'));
    }

    public function verifyUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['verified' => ['required', 'boolean']]);
        $user->update(['verified' => $data['verified'], 'verified_at' => $data['verified'] ? now() : null]);

        return back()->with('status', 'Publisher verification updated.');
    }

    public function review(Request $request, VerificationRequest $verificationRequest): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'notes' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($verificationRequest, $data): void {
            $application = VerificationRequest::whereKey($verificationRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($application->status === 'pending', 409, 'This application has already been reviewed.');
            $application->update($data + ['reviewed_at' => now()]);
            if ($data['status'] === 'approved') {
                $application->user->update(['verified' => true, 'verified_at' => now(), 'account_type' => $application->account_type]);
            }
        });

        return back()->with('status', 'Verification application reviewed.');
    }

    public function document(VerificationRequest $verificationRequest): StreamedResponse
    {
        $disk = Storage::disk('public');
        abort_unless($disk->exists($verificationRequest->document_path), 404);

        return $disk->download($verificationRequest->document_path, 'verification-'.$verificationRequest->id.'.'.pathinfo($verificationRequest->document_path, PATHINFO_EXTENSION));
    }

    public function archiveEvent(Request $request, int $eventId): RedirectResponse
    {
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        Event::withoutGlobalScopes()->findOrFail($eventId)->forceFill(['archived_at' => $data['archived'] ? now() : null, 'moderated_at' => $data['archived'] ? now() : null])->save();

        return back()->with('status', 'Event visibility updated.');
    }

    public function deleteStory(Story $story): RedirectResponse
    {
        if ($story->media_path) {
            Storage::disk('public')->delete($story->media_path);
        }
        $story->delete();

        return back()->with('status', 'Story removed.');
    }

    public function reply(Request $request, int $supportId): RedirectResponse
    {
        $data = $request->validate(['admin_reply' => ['required', 'string', 'max:5000'], 'status' => ['required', 'in:open,in_progress,resolved']]);
        abort_unless(DB::table('support_requests')->where('id', $supportId)->exists(), 404);
        DB::table('support_requests')->where('id', $supportId)->update($data + ['updated_at' => now()]);

        return back()->with('status', 'Reply saved. The user can read it in the app.');
    }

    private function planData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:1', 'max:9999999', 'decimal:0,2'],
            'currency' => ['required', 'in:TZS'], 'duration_days' => ['required', 'integer', 'min:1', 'max:366'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    public function createPlan(Request $request): RedirectResponse
    {
        PublisherPlan::create($this->planData($request));

        return back()->with('status', 'Publisher plan created.');
    }

    public function updatePlan(Request $request, PublisherPlan $publisherPlan): RedirectResponse
    {
        $publisherPlan->update($this->planData($request));

        return back()->with('status', 'Plan updated. Existing payment amounts and durations are preserved.');
    }

    public function reconcilePayment(Request $request, PublisherPayment $publisherPayment, SubscriptionPayments $payments): RedirectResponse
    {
        $data = $request->validate(['order_id' => ['required', 'string', 'max:150']]);
        $publisherPayment = DB::transaction(function () use ($publisherPayment, $data, $request): PublisherPayment {
            $locked = PublisherPayment::whereKey($publisherPayment->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'paid', 409, 'This payment is already verified.');
            if ($locked->provider_order_id && $locked->provider_order_id !== $data['order_id']) {
                throw ValidationException::withMessages(['order_id' => 'Use the existing SonicPesa order reference for this payment.']);
            }
            if (! $locked->provider_order_id) {
                $request->validate(['order_id' => ['unique:publisher_payments,provider_order_id']]);
                $locked->update(['provider_order_id' => $data['order_id'], 'status' => 'pending']);
            }

            return $locked;
        });
        try {
            $result = $payments->verify($publisherPayment);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['payment' => 'SonicPesa status is temporarily unavailable. No subscription was granted.']);
        }

        return back()->with('status', 'Verified SonicPesa status: '.$result->status.'.');
    }

    public function cancelSubscription(PublisherSubscription $publisherSubscription): RedirectResponse
    {
        $publisherSubscription->update(['status' => 'cancelled']);

        return back()->with('status', 'Subscription cancelled.');
    }
}
