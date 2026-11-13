@extends('layouts.app')
@section('title', 'Usuários')
@section('page-title', 'Usuários e funções')
@section('eyebrow', 'Administração')

@section('content')
    @if (session('status'))
        <div class="mb-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    @can('create', \App\Models\User::class)
        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">Criar conta institucional</h2>
            <p class="mt-1 text-sm text-slate-500">Somente administradores autorizados podem criar contas e definir funções.</p>

            @if ($errors->any())
                <div class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.store') }}" class="mt-4 grid gap-4 md:grid-cols-2">
                @csrf
                <label class="text-sm font-medium">Nome
                    <input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                <label class="text-sm font-medium">E-mail institucional
                    <input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                <label class="text-sm font-medium">Telefone (opcional)
                    <input name="phone" value="{{ old('phone') }}" maxlength="30" autocomplete="tel" class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                <label class="text-sm font-medium">Função institucional
                    <select name="role" required class="mt-1 block w-full rounded-lg border-slate-300">
                        <option value="">Selecione uma função</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->name }}" @selected(old('role') === $role->name)>{{ \App\Enums\UserType::tryFrom($role->name)?->label() ?? $role->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium">Senha (12 caracteres, maiúsculas, minúsculas, número e símbolo)
                    <input name="password" type="password" required minlength="12" autocomplete="new-password" class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                <label class="text-sm font-medium">Confirme a senha
                    <input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                <div class="md:col-span-2"><button class="rounded-lg bg-campus-700 px-4 py-2 font-semibold text-white">Criar usuário</button></div>
            </form>
        </section>
    @endcan

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-semibold">Contas do sistema</h2>
            <p class="mt-1 text-xs text-slate-500">Funções institucionais só podem ser alteradas por administrador e nunca na própria conta.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Usuário</th><th class="px-4 py-3">Perfil</th><th class="px-4 py-3">Acesso</th><th class="px-5 py-3">Ações</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($users as $u)
                        <tr>
                            <td class="px-5 py-4"><p class="font-semibold">{{ $u->name }}</p><p class="text-xs text-slate-500">{{ $u->email }}</p></td>
                            <td class="px-4 py-4">{{ $u->user_type->label() }}</td>
                            <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs {{ $u->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $u->is_active ? 'Ativo' : 'Inativo' }}</span></td>
                            <td class="px-5 py-4">
                                @can('assignRole', $u)
                                    <form method="POST" action="{{ route('admin.users.role', $u) }}" class="flex gap-2">@csrf @method('PATCH')<select name="role" class="rounded-lg border-slate-200 text-xs">@foreach ($roles as $role)<option value="{{ $role->name }}" @selected($u->hasRole($role->name))>{{ \App\Enums\UserType::tryFrom($role->name)?->label() ?? $role->name }}</option>@endforeach</select><button class="rounded-lg bg-campus-700 px-3 py-2 text-xs font-semibold text-white">Salvar</button></form>
                                    <form method="POST" action="{{ route('admin.users.active', $u) }}" class="mt-2">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $u->is_active ? 0 : 1 }}"><button class="text-xs font-semibold {{ $u->is_active ? 'text-rose-600' : 'text-emerald-700' }}">{{ $u->is_active ? 'Desativar conta' : 'Ativar conta' }}</button></form>
                                @else
                                    <span class="text-xs text-slate-400">Sua própria conta</span>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $users->links() }}</div>
    </section>
@endsection
