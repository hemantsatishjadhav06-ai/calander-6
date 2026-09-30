<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class AssignClientReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAllPermissions(['workspace.settings.manage'], $this->user()?->current_workspace_id) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_user_id' => ['nullable', 'uuid'],
            'revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
        ];
    }
}
