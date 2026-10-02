<?php

declare(strict_types=1);

namespace App\Http\Requests\Blogs;

use App\Models\BlogDraft;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBlogDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isMemberOfWorkspace($user->current_workspace_id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $draft = $this->route('blogDraft');
        $slugRule = Rule::unique('blog_drafts', 'slug')->where('workspace_id', $this->user()->current_workspace_id);
        if ($draft instanceof BlogDraft) {
            $slugRule->ignore($draft->id);
        }

        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'body' => ['required', 'string', 'max:100000'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'featured_image_url' => ['nullable', 'url:https', 'max:2048', $this->publicUrl()],
            'featured_image_alt' => ['nullable', 'string', 'max:300'],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url:https', 'max:2048', $this->publicUrl()],
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    private function publicUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = parse_url((string) $value);
            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
                || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)
                || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host)
                || in_array($host, ['localhost', 'metadata.google.internal'], true)
                || preg_match('/\.(?:localhost|local|internal|test|invalid)$/', $host)
                || ($attribute === 'featured_image_url' && preg_match('/\.svg(?:$|[?#])/i', (string) $value))) {
                $fail('Use a public HTTPS URL without credentials or a private hostname.');
            }
        };
    }
}
