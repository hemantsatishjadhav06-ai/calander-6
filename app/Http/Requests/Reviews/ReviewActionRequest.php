<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasSomePermissions(['workspace.read', 'workspace.review.client'], $this->user()->current_workspace_id) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['submit', 'approve', 'request_changes', 'comment', 'hold', 'release_hold'])],
            'revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'note' => ['nullable', 'string', 'max:5000'],
            'target_id' => ['nullable', 'uuid'],
            'stage' => ['required', Rule::in(['internal', 'client'])],
        ];
    }
}
