<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Post::class);
    }

    public function workspace(): Workspace
    {
        return Workspace::query()->whereKey((string) Context::get('workspace_id'))->firstOrFail();
    }

    protected function prepareForValidation(): void
    {
        $today = Date::now($this->workspace()->timezone ?? 'UTC');
        $this->merge([
            'from' => $this->input('from', $today->subDays(29)->toDateString()),
            'to' => $this->input('to', Date::now($this->workspace()->timezone ?? 'UTC')->toDateString()),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'account_id' => ['nullable', 'uuid', Rule::exists('connected_accounts', 'id')->where('workspace_id', Context::get('workspace_id'))],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->hasAny(['from', 'to']) && Date::parse($this->string('from')->toString())->diffInDays(Date::parse($this->string('to')->toString())) > 365) {
                $validator->errors()->add('to', 'Choose a range of 366 days or fewer.');
            }
        }];
    }

    /** @return array{from: string, to: string, platform: string|null, account_id: string|null} */
    public function filters(): array
    {
        return [
            'from' => $this->string('from')->toString(),
            'to' => $this->string('to')->toString(),
            'platform' => $this->filled('platform') ? $this->string('platform')->toString() : null,
            'account_id' => $this->filled('account_id') ? $this->string('account_id')->toString() : null,
        ];
    }
}
