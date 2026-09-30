<?php

declare(strict_types=1);

namespace App\Http\Requests\Creator;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteCreatorGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_workspace_id' => ['required', 'uuid', Rule::in([(string) $this->user()?->current_workspace_id])],
            'operation' => ['required', 'string', 'max:40'], 'prompt' => ['nullable', 'string', 'max:10000'],
            'asset_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'], 'options' => ['sometimes', 'array'],
            'idempotency_key' => ['required', 'uuid'],
            'endpoint_id' => ['prohibited'], 'image_url' => ['prohibited'], 'image_urls' => ['prohibited'], 'input' => ['prohibited'],
        ];
    }
}
