<?php

declare(strict_types=1);

namespace App\Http\Requests\Blogs;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ReviewBlogDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isOwnerOfWorkspace($user->current_workspace_id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
