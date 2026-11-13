@extends('layouts.auth', ['title' => 'Criar cadastro'])

@section('content')
    <h2>Crie sua conta</h2>
    <p class="intro">O cadastro público é destinado a estudantes, professores e funcionários.</p>

    <form method="POST" action="{{ route('register.store') }}">
        @csrf
        <div class="field">
            <label for="name">Nome completo</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="120" required autofocus>
        </div>
        <div class="field">
            <label for="email">E-mail institucional</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
        </div>
        <div class="field">
            <label for="phone">Telefone <span class="hint">(opcional)</span></label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" maxlength="32">
        </div>
        <div class="field">
            <label for="user_type">Vínculo com a instituição</label>
            <select id="user_type" name="user_type" required>
                <option value="">Selecione seu vínculo</option>
                @foreach ($userTypes as $type)
                    <option value="{{ $type->value }}" @selected(old('user_type') === $type->value)>
                        {{ match ($type) { \App\Enums\UserType::Student => 'Estudante', \App\Enums\UserType::Professor => 'Professor(a)', \App\Enums\UserType::Staff => 'Funcionário(a)', default => '' } }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
            <p class="hint">Use pelo menos 12 caracteres, com letras maiúsculas e minúsculas e números.</p>
        </div>
        <div class="field">
            <label for="password_confirmation">Confirme a senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
        </div>
        <button class="button" type="submit">Criar conta</button>
    </form>

    <div class="links">Já tem conta? <a href="{{ route('login') }}">Entrar</a></div>
@endsection
