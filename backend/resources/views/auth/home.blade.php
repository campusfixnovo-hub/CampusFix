@extends('layouts.auth', ['title' => 'Área autenticada', 'cardClass' => 'home-card'])

@section('content')
    <h2>Olá, {{ auth()->user()->name }}</h2>
    <p class="intro">Sua conta está autenticada no CampusFix.</p>
    <div class="home-meta">
        <strong>E-mail:</strong> {{ auth()->user()->email }}<br>
        <strong>Perfil:</strong> {{ auth()->user()->user_type->value }}<br>
        <strong>Funções:</strong> {{ auth()->user()->getRoleNames()->join(', ') }}
    </div>
    <p class="intro">O espaço de acompanhamento de solicitações será disponibilizado nas próximas fases.</p>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="button logout" type="submit">Sair</button>
    </form>
@endsection
