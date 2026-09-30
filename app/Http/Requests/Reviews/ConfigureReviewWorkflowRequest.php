<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfigureReviewWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAllPermissions(['workspace.settings.manage'], $this->user()->current_workspace_id) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_workspace_id' => ['required', 'uuid', Rule::in([$this->user()->current_workspace_id])],
            'mode' => ['required', Rule::in(['off', 'internal', 'internal_client'])],
        ];
    }
}
