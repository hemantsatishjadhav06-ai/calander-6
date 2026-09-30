<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveContentIdeaRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:200'],
            'brief' => ['nullable', 'string', 'max:10000'],
            'caption' => ['nullable', 'string', 'max:20000'],
            'category' => ['nullable', 'string', 'max:100'],
            'tags' => ['present', 'array', 'list', 'max:20'],
            'tags.*' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'status' => ['required', Rule::in(['inbox', 'planned', 'archived'])],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'template_id' => ['nullable', 'uuid', Rule::exists('content_templates', 'id')->where('workspace_id', $this->user()->current_workspace_id)->whereNull('archived_at')],
        ];
    }
}
