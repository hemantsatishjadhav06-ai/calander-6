<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBrandProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAllPermissions(['workspace.settings.manage'], $this->user()->current_workspace_id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_workspace_id' => ['required', 'uuid', Rule::in([(string) $this->user()->current_workspace_id])],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'tagline' => ['nullable', 'string', 'max:300'],
            'voice' => ['nullable', 'string', 'max:5000'],
            'audience' => ['nullable', 'string', 'max:5000'],
            'guidelines' => ['nullable', 'string', 'max:10000'],
            'palette' => ['present', 'array', 'list', 'max:12'],
            'palette.*' => ['required', 'string', 'regex:/^#[a-fA-F0-9]{6}$/', 'distinct:ignore_case'],
            'logo_asset_id' => ['nullable', 'uuid', Rule::exists('creator_assets', 'id')->where('workspace_id', $this->user()->current_workspace_id)->where('kind', 'logo')],
            'default_hashtags' => ['present', 'array', 'list', 'max:30'],
            'default_hashtags.*' => ['required', 'string', 'max:100', 'regex:/^#[\pL\pN_]+$/u', 'distinct:ignore_case'],
            'first_comment_enabled' => ['required', 'boolean'],
            'first_comment' => ['nullable', 'string', 'max:5000', 'required_if:first_comment_enabled,true'],
        ];
    }
}
