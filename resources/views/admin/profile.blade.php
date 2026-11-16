@extends('admin.layout')
@section('title', 'My profile')
@section('content')
<p class="muted">Manage your Veylo account and sign-in details.</p>
<section class="panel"><h2>Profile photo</h2>
@if($user->avatar_path)<img src="{{ \App\Support\Media::url($user->avatar_path) }}" alt="Your current profile photo" class="profile-avatar">@endif
<form method="post" enctype="multipart/form-data" action="{{ route('admin.profile.avatar') }}" class="compact-form">@csrf<label>Choose a photo<input type="file" name="avatar" accept="image/*" required></label><small class="muted">Choose an image up to 5 MB.</small>@error('avatar')<p class="field-error">{{ $message }}</p>@enderror<button class="button">Update photo</button></form></section>
<section class="panel"><h2>Account information</h2><form method="post" action="{{ route('admin.profile.update') }}" class="form-grid">@csrf @method('PATCH')
@foreach(['name'=>'Display name','username'=>'Username','email'=>'Email address','country'=>'Country'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key, $user->$key) }}" type="{{ $key === 'email' ? 'email' : 'text' }}" maxlength="{{ $key === 'email' ? 255 : ($key === 'username' ? 30 : 100) }}" {{ in_array($key, ['name','username']) || ($key === 'email' && !$user->phone) ? 'required' : '' }}>@error($key)<span class="field-error">{{ $message }}</span>@enderror</label>@endforeach
@if($user->phone)<label>Phone number<input value="{{ $user->phone }}" readonly><small class="muted">Contact support to change your phone number.</small></label>@endif
<label>Bio<textarea name="bio" maxlength="150">{{ old('bio', $user->bio) }}</textarea>@error('bio')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Current password<input name="current_password" type="password" autocomplete="current-password"><small class="muted">Required only when changing your email address.</small>@error('current_password')<span class="field-error">{{ $message }}</span>@enderror</label>
<p class="small muted">Usernames can be changed once every seven days.</p><button class="button">Save profile</button>
</form></section>
<section class="panel"><h2>Change password</h2><p class="muted small">You will stay signed in here. Other browser and app sessions will be signed out.</p><form method="post" action="{{ route('admin.profile.password') }}" class="form-grid">@csrf @method('PATCH')
<label>Current password<input type="password" name="current_password" required autocomplete="current-password">@error('current_password')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>New password<input type="password" name="password" required minlength="8" maxlength="255" autocomplete="new-password"><small class="muted">Use at least 8 characters.</small>@error('password')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Confirm new password<input type="password" name="password_confirmation" required minlength="8" maxlength="255" autocomplete="new-password"></label><button class="button">Change password</button>
</form></section>
@endsection
