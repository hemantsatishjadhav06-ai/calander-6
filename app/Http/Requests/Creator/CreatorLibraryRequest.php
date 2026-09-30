<?php

declare(strict_types=1);

namespace App\Http\Requests\Creator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator as Validation;
use Illuminate\Validation\Validator;
use Throwable;

class CreatorLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->current_workspace_id !== null
            && $this->user()->hasAllPermissions(['workspace.read'], $this->user()->current_workspace_id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048']];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $encoded = $this->input('cursor');
            if ($validator->errors()->isNotEmpty() || ! is_string($encoded) || $encoded === '') {
                return;
            }
            try {
                $cursor = Cursor::fromEncoded($encoded);
                $values = $cursor?->toArray() ?? [];
                $valid = array_diff(array_keys($values), ['created_at', 'id', '_pointsToNextItems']) === []
                    && Validation::make($values, [
                        'created_at' => ['required', 'string', 'date_format:Y-m-d H:i:s', 'regex:/^[1-9][0-9]{3}-/'],
                        'id' => ['required', 'uuid'],
                        '_pointsToNextItems' => ['required', 'boolean:strict'],
                    ])->passes();
            } catch (Throwable) {
                $valid = false;
            }
            if (! $valid) {
                $validator->errors()->add('cursor', 'The library cursor is invalid. Start from the first page.');
            }
        }];
    }
}
