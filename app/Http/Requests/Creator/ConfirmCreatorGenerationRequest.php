<?php

declare(strict_types=1);

namespace App\Http\Requests\Creator;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmCreatorGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => ['required', 'accepted']];
    }
}
