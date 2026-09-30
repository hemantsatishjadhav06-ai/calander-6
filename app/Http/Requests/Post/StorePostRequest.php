<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Http\Requests\Post\Concerns\DerivesMentionHandleRules;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    use DerivesMentionHandleRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_workspace_id' => ['sometimes', 'required', 'uuid', Rule::in([$this->user()->current_workspace_id])],
            'base_text' => ['sometimes', 'nullable', 'string'],
            'segments' => ['present', 'array'],
            'segments.*' => ['nullable', 'string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            ...$this->mentionHandleRules(),
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'none', 'set', 'account', 'accounts'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'destination.ids' => ['array', 'required_if:destination.kind,accounts'],
            'destination.ids.*' => ['string'],
            'auto_repost' => ['sometimes', 'nullable', 'boolean'],
            'first_comment_enabled' => ['sometimes', 'boolean'],
            'first_comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'targets.*.first_comment_enabled' => ['sometimes', 'nullable', 'boolean'],
            'targets.*.first_comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'segment_breaks' => ['array'],
            'segment_breaks.*' => ['string'],
            'placements' => ['array'],
            'placements.*.media_id' => ['required', 'string'],
            'placements.*.segment_ref' => ['required', 'string'],
            'placements.*.position' => ['required', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['expected_workspace_id.in' => 'Your active company changed in another tab. Reload before saving this draft.'];
    }
}
