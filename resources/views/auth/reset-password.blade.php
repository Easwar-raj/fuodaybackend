@extends('fuoday.app')

@section('content')
<div class="container d-flex align-items-center justify-content-center min-vh-100 bg-light">
    <div class="col-md-6 col-lg-5 bg-white rounded shadow p-5">
        <h2 class="text-center mb-4">Reset Your Password</h2>
        <p class="text-muted text-center mb-4">Please enter your new password below.</p>

        {{-- Session status --}}
        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            {{-- Email --}}
            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    class="form-control"
                    value="{{ old('email', request('email')) }}"
                    readonly
                >
            </div>

            {{-- Password --}}
            <div class="mb-3 position-relative">
                <label for="password" class="form-label">New Password</label>
                <div class="input-group">
                    <input
                        type="password"
                        name="password"
                        id="password"
                        class="form-control"
                        required
                    >
                    <span class="input-group-text">
                        <i class="bi bi-eye-slash toggle-password" data-target="password" style="cursor: pointer;"></i>
                    </span>
                </div>
            </div>

            {{-- Confirm Password --}}
            <div class="mb-4 position-relative">
                <label for="password_confirmation" class="form-label">Confirm Password</label>
                <div class="input-group">
                    <input
                        type="password"
                        name="password_confirmation"
                        id="password_confirmation"
                        class="form-control"
                        required
                    >
                    <span class="input-group-text">
                        <i class="bi bi-eye-slash toggle-password" data-target="password_confirmation" style="cursor: pointer;"></i>
                    </span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                Reset Password
            </button>
        </form>
    </div>
</div>
@endsection