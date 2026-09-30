<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveContentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_workspace_id' => ['required', 'uuid', Rule::in([(string) $this->user()->current_workspace_id])],
            'expected_revision' => [$this->isMethod('PUT') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'brief' => ['present', 'nullable', 'string', 'max:10000'],
            'caption' => ['nullable', 'string', 'max:20000'],
            'hashtags' => ['present', 'array', 'list', 'max:30'],
            'hashtags.*' => ['required', 'string', 'max:100', 'regex:/^#[\pL\pN_]+$/u', 'distinct:ignore_case'],
            'first_comment' => ['nullable', 'string', 'max:5000'],
            'source_project_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('creator_projects', 'id')->where('workspace_id', $this->user()->current_workspace_id)],
            'source_project_revision' => ['nullable', 'required_with:source_project_id', 'integer', 'min:1'],
            'destination' => ['sometimes', 'array:kind,ids'],
            'destination.kind' => ['required_with:destination', Rule::in(['none', 'default', 'accounts'])],
            'destination.ids' => ['required_if:destination.kind,accounts', 'array', 'list', 'max:50'],
            'destination.ids.*' => ['required', 'uuid', 'distinct', Rule::exists('connected_accounts', 'id')->where('workspace_id', $this->user()->current_workspace_id)->whereNull('disabled_at')],
            'media_asset_ids' => ['sometimes', 'array', 'list', 'max:20'],
            'media_asset_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('creator_assets', 'id')->where('workspace_id', $this->user()->current_workspace_id)],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
