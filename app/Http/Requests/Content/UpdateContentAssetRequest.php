<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContentAssetRequest extends FormRequest
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
            'expected_revision' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:200'],
            'folder' => ['nullable', 'string', 'max:100'],
            'tags' => ['present', 'array', 'list', 'max:20'],
            'tags.*' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'starred' => ['required', 'boolean'],
            'archived' => ['required', 'boolean'],
        ];
    }
}
