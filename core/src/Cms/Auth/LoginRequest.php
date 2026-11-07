<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Framework\Validation\FormRequest;

final class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|min:3',
            'password' => 'required|min:1',
        ];
    }

    public function aliases(): array
    {
        return [
            'email' => 'email or username',
        ];
    }
}
