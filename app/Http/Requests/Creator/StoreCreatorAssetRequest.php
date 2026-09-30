<?php

declare(strict_types=1);

namespace App\Http\Requests\Creator;

use App\Models\CreatorAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreatorAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CreatorAsset::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_workspace_id' => ['required', 'uuid', Rule::in([(string) $this->user()->current_workspace_id])],
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:8192'],
            'name' => ['required', 'string', 'max:200'], 'kind' => ['required', Rule::in(['image', 'logo'])]];
    }
}
