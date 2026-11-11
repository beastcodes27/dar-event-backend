<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', 'Administration') · DarEvent</title><link rel="stylesheet" href="{{ asset('admin.css') }}"></head>
<body><div class="shell"><aside class="sidebar"><a class="brand" href="{{ route('admin.dashboard') }}">Dar<span>Event</span><small>ADMINISTRATION</small></a><nav aria-label="Administration">
@foreach(['dashboard'=>'Overview','users'=>'Users','appeals'=>'Ban reviews','verification'=>'Verification','events'=>'Events','stories'=>'Stories','plans'=>'Publisher plans','subscriptions'=>'Subscriptions','payments'=>'Payments','support'=>'Support'] as $key=>$label)
<a class="{{ request()->routeIs('admin.'.$key) ? 'selected' : '' }}" href="{{ route('admin.'.$key) }}"><span class="nav-dot"></span>{{ $label }}</a>
@endforeach
</nav><div class="sidebar-bottom"><span>{{ auth()->user()->name }}</span><form method="post" action="{{ route('admin.logout') }}">@csrf<button class="logout">Sign out</button></form></div></aside><main><header class="page-header"><div><p class="eyebrow">DAREVENT WORKSPACE</p><h1>@yield('title', 'Administration')</h1></div><a class="subtle" href="{{ route('billing.index') }}">Publisher account ↗</a></header>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@yield('content')</main></div></body></html>
