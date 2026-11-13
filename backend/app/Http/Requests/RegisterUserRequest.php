<?php

namespace App\Http\Requests;

use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'user_type' => ['required', 'string', Rule::in(UserType::publicRegistrationValues())],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome completo.',
            'name.string' => 'O nome completo informado é inválido.',
            'name.max' => 'O nome completo não pode ultrapassar :max caracteres.',
            'email.required' => 'Informe seu e-mail institucional.',
            'email.string' => 'O e-mail institucional informado é inválido.',
            'email.email' => 'Informe um endereço de e-mail válido.',
            'email.max' => 'O e-mail institucional não pode ultrapassar :max caracteres.',
            'email.unique' => 'Este e-mail já está cadastrado.',
            'user_type.required' => 'Selecione seu vínculo com a instituição.',
            'user_type.string' => 'O vínculo com a instituição informado é inválido.',
            'user_type.in' => 'Selecione estudante, professor(a) ou funcionário(a).',
            'phone.string' => 'O telefone informado é inválido.',
            'phone.max' => 'O telefone não pode ultrapassar :max caracteres.',
            'password.required' => 'Informe uma senha.',
            'password.min' => 'A senha deve ter pelo menos :min caracteres.',
            'password.confirmed' => 'A confirmação da senha não corresponde.',
            'password.mixed' => 'A senha deve conter letras maiúsculas e minúsculas.',
            'password.letters' => 'A senha deve conter pelo menos uma letra.',
            'password.numbers' => 'A senha deve conter pelo menos um número.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nome completo',
            'email' => 'e-mail institucional',
            'user_type' => 'vínculo com a instituição',
            'phone' => 'telefone',
            'password' => 'senha',
            'password_confirmation' => 'confirmação da senha',
        ];
    }
}
