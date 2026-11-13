<?php

namespace App\Http\Requests;

use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignRole', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return [
            'role' => [
                'required',
                'string',
                Rule::in(array_map(static fn (UserType $type): string => $type->value, UserType::cases())),
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}
