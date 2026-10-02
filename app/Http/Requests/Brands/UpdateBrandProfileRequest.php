<?php

namespace App\Http\Requests\Brands;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isOwnerOfWorkspace($user->current_workspace_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'website_url' => ['required', 'url:https', 'max:2048'],
            'instagram_username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._]{1,30}$/'],
            'facebook_page_id' => ['nullable', 'string', 'regex:/^[0-9]{1,30}$/'],
            'facebook_page_url' => ['nullable', 'url:https', 'max:2048'],
            'x_username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]{1,15}$/'],
            'netlify_site_id' => ['nullable', 'uuid'],
            'repository_url' => ['nullable', 'url:https', 'max:2048'],
        ];
    }
}
