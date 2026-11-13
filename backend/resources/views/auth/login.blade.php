@extends('layouts.auth', ['title' => 'Entrar'])

@section('content')
    <h2>Boas-vindas</h2>
    <p class="intro">Entre para acompanhar as solicitações do campus.</p>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </div>
        <div class="field">
            <div class="row"><label for="password">Senha</label><a href="{{ route('password.request') }}">Esqueci minha senha</a></div>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <label class="remember"><input name="remember" type="checkbox" value="1"> Manter conectado</label>
        <button class="button" type="submit">Entrar</button>
    </form>

@endsection
