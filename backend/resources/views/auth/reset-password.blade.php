@extends('layouts.auth', ['title' => 'Redefinir senha'])

@section('content')
    <h2>Defina uma nova senha</h2>
    <p class="intro">Escolha uma senha forte para proteger sua conta.</p>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        </div>
        <div class="field">
            <label for="password">Nova senha</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
            <p class="hint">Use pelo menos 12 caracteres, com letras maiúsculas e minúsculas e números.</p>
        </div>
        <div class="field">
            <label for="password_confirmation">Confirme a senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>
        <button class="button" type="submit">Redefinir senha</button>
    </form>
@endsection
