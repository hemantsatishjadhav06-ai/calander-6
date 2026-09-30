<?php

declare(strict_types=1);

namespace App\Http\Requests\Creator;

use Illuminate\Foundation\Http\FormRequest;

class StoreCreatorExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'uuid'],
            'project_revision' => ['required', 'integer', 'min:1'],
            'expected_post_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'idempotency_key' => ['required', 'uuid'],
            'target_segment_ref' => ['sometimes', 'string', 'regex:/^[a-zA-Z0-9_-]{1,100}$/'],
            'slides' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'slides.*' => ['array:slide_id,file,alt_text'],
            'slides.*.slide_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,100}$/', 'distinct:strict'],
            'slides.*.file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:8192'],
            'slides.*.alt_text' => ['nullable', 'string', 'max:1000'],
            'replace_media_ids' => ['sometimes', 'array', 'list', 'max:10'],
            'replace_media_ids.*' => ['required', 'uuid', 'distinct:strict'],
        ];
    }
}
