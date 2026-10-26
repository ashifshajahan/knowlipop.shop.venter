@extends('admin.layout')
@section('content')
<h1>Dashboard</h1><p class="muted">Manage Venter seller accounts from one place.</p>
@if(session('success'))<div class="success">{{ session('success') }}</div>@endif
<div class="grid"><section class="card"><div class="muted">Registered sellers</div><div class="stat">{{ $sellerCount }}</div></section><section class="card"><h3>Admin access</h3><p class="muted">Only authenticated accounts with the admin role can access this page.</p></section></div>
<section class="card"><h2>Create seller account</h2><p class="muted">Phone number must include country code, e.g. +919876543210.</p>
@if($errors->any())<div class="error">Please check the fields below. @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<form method="POST" action="{{ route('admin.sellers.store') }}">@csrf<div class="grid">
<div><label>Seller name</label><input name="seller_name" value="{{ old('seller_name') }}" required maxlength="120"></div>
<div><label>Phone (+country code)</label><input name="phone" value="{{ old('phone') }}" required placeholder="+919876543210"></div>
<div><label>Address</label><input name="address" value="{{ old('address') }}" required></div>
<div><label>Landmark</label><input name="landmark" value="{{ old('landmark') }}" required></div>
<div><label>State</label><input name="state" value="{{ old('state') }}" required></div>
<div><label>Country</label><input name="country" value="{{ old('country', 'India') }}" required></div>
<div><label>Password (minimum 8 characters)</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
<div><label>Confirm password</label><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></div>
</div><br><button type="submit">Create seller</button></form></section>
<section class="card"><h2>Seller accounts</h2><table><thead><tr><th>Name</th><th>Phone</th><th>State</th><th>Created</th></tr></thead><tbody>@forelse($sellers as $seller)<tr><td>{{ $seller->seller_name ?? $seller->name }}</td><td>{{ $seller->phone ?? '—' }}</td><td>{{ $seller->state ?? '—' }}</td><td>{{ $seller->created_at?->format('d M Y') }}</td></tr>@empty<tr><td colspan="4">No sellers yet.</td></tr>@endforelse</tbody></table><div style="margin-top:16px">{{ $sellers->links() }}</div></section>
@endsection
