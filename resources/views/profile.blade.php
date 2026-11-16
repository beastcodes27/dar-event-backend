<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $user->name }} ({{ '@'.$user->username }}) · Veylo</title>
<meta name="description" content="{{ \Illuminate\Support\Str::limit($user->bio ?: 'Find your next experience on Veylo.', 150) }}">
<meta property="og:title" content="{{ $user->name }} ({{ '@'.$user->username }}) · Veylo">
<meta property="og:description" content="{{ \Illuminate\Support\Str::limit($user->bio ?: 'Find your next experience on Veylo.', 150) }}">
<meta property="og:type" content="profile">
@if($avatar)<meta property="og:image" content="{{ $avatar }}">@endif
<style>
:root{--ink:#14141a;--muted:#6c6c78;--line:#ececf1;--brand:#ea7a16;--soft:#fff6ee}
*{box-sizing:border-box}body{margin:0;background:#fff;color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.5}
.profile{max-width:760px;margin:0 auto;padding:28px 20px 56px}
.brand{display:inline-block;font-size:24px;font-weight:800;letter-spacing:-1px;color:var(--ink);text-decoration:none;margin-bottom:28px}.brand span{color:var(--brand)}
header{display:flex;gap:22px;align-items:flex-start;padding-bottom:24px;border-bottom:1px solid var(--line)}
.avatar{width:104px;height:104px;flex:0 0 104px;border-radius:50%;background:#f4e9dc;display:grid;place-items:center;overflow:hidden;font-size:34px;font-weight:700;color:#8a5a2b}.avatar img{width:100%;height:100%;object-fit:cover}
.identity{min-width:0}.eyebrow{margin:0;font-size:10px;font-weight:700;letter-spacing:2px;color:var(--muted)}
h1{margin:6px 0 2px;font-size:28px;line-height:1.2;letter-spacing:-.5px;overflow-wrap:anywhere}
.verified{display:inline-grid;place-items:center;width:20px;height:20px;border-radius:50%;background:#1877f2;color:#fff;font-size:12px;vertical-align:middle}
.username{margin:0;color:var(--muted);font-size:14px;overflow-wrap:anywhere}
.pill{display:inline-block;margin:10px 0 0;padding:4px 10px;border-radius:7px;background:var(--soft);color:#a45710;font-size:11px;font-weight:700}
.bio{margin:10px 0 0;font-size:14px;line-height:21px;overflow-wrap:anywhere}
.stats{display:flex;gap:10px;list-style:none;margin:22px 0 6px;padding:0}.stats li{flex:1;text-align:center;padding:12px 6px;border:1px solid var(--line);border-radius:14px}.stats strong{display:block;font-size:20px}.stats span{font-size:12px;color:var(--muted)}
h2{font-size:15px;margin:28px 0 12px;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted)}
.event{display:flex;gap:14px;align-items:center;padding:14px;border:1px solid var(--line);border-radius:14px;margin-bottom:10px}.event img{width:64px;height:64px;border-radius:10px;object-fit:cover;flex:0 0 64px}
.event h3{margin:0;font-size:15px;overflow-wrap:anywhere}.muted{margin:3px 0 0;font-size:13px;color:var(--muted)}
footer{margin-top:30px;padding-top:22px;border-top:1px solid var(--line);text-align:center}
.cta{display:inline-block;background:var(--brand);color:#fff;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:12px}
@media(max-width:520px){header{flex-direction:column;align-items:center;text-align:center}.identity{text-align:center}.avatar{width:92px;height:92px;flex-basis:92px}}
@media(prefers-color-scheme:dark){body{background:#101016;color:#f2f2f5}header,.stats li,.event,footer{border-color:#2c2c36}.avatar{background:#3a2a1b;color:#f2c394}.pill{background:#3a2a1b;color:#ffbe7a}}
</style></head>
<body><main class="profile">
<a class="brand" href="{{ route('account.login') }}">Vey<span>lo</span></a>
<header>
<div class="avatar">@if($avatar)<img src="{{ $avatar }}" alt="{{ $user->name }}">@else<span>{{ $user->initials() }}</span>@endif</div>
<div class="identity">
<p class="eyebrow">VEYLO PROFILE</p>
<h1>{{ $user->name }} @if($user->verified)<span class="verified" title="Verified account" aria-label="Verified account">✓</span>@endif</h1>
<p class="username">{{ '@'.$user->username }}</p>
@if($user->category)<p class="pill">{{ $user->category }}</p>@endif
@if($user->bio)<p class="bio">{{ $user->bio }}</p>@endif
</div>
</header>
<ul class="stats">
<li><strong>{{ number_format($user->events_count) }}</strong><span>Posts</span></li>
<li><strong>{{ number_format($user->followers_count) }}</strong><span>Followers</span></li>
<li><strong>{{ number_format($user->following_count) }}</strong><span>Following</span></li>
</ul>
<h2>Public events</h2>
@forelse($events as $event)
<article class="event">
@if($event->image_path)<img src="{{ \App\Support\Media::url($event->image_path) }}" alt="">@endif
<div><h3>{{ $event->title }}</h3><p class="muted">{{ $event->starts_at?->format('M j, Y · g:i A') }}{{ $event->location ? ' · '.$event->location : '' }}</p></div>
</article>
@empty
<p class="muted">No public events yet.</p>
@endforelse
<footer>
<a class="cta" href="{{ route('account.login') }}">Open Veylo</a>
<p class="muted">Sign in to follow {{ $user->name }} or send a message.</p>
</footer>
</main></body></html>
