@extends('layouts.auth', ['title' => 'Recuperar senha'])

@section('content')
    <h2>Recuperar senha</h2>
    <p class="intro">Informe seu e-mail. Se ele estiver cadastrado, enviaremos instruções para redefinir sua senha.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        </div>
        <button class="button" type="submit">Enviar link de recuperação</button>
    </form>

    <div class="links"><a href="{{ route('login') }}">Voltar para entrar</a></div>
@endsection
