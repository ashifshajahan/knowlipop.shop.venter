@extends('admin.layout')
@section('content')
<div class="card" style="max-width:460px;margin:60px auto"><h1>Admin sign in</h1><p class="muted">Sign in with the admin account created by the project owner.</p>
@if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
<form method="POST" action="{{ route('admin.login.submit') }}">@csrf<label>Admin email</label><input type="email" name="email" value="{{ old('email') }}" required autocomplete="username"><label>Password</label><input type="password" name="password" required autocomplete="current-password"><br><br><button type="submit">Sign in</button></form></div>
@endsection
