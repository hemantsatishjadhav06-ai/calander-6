<?php

declare(strict_types=1);

namespace App\Http\Requests\Creator;

use App\Models\CreatorProject;
use App\Services\Creator\CreatorDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SaveCreatorProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('creatorProject');

        return $project instanceof CreatorProject
            ? $this->user()->can('update', $project)
            : $this->user()->can('create', CreatorProject::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->isJson()) {
            $payload = json_decode($this->getContent(), true);
            if (is_array($payload) && array_key_exists('document', $payload)) {
                $this->merge(['document' => $payload['document']]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_workspace_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'uuid', Rule::in([(string) $this->user()->current_workspace_id])],
            'name' => ['required', 'string', 'max:200'], 'document' => ['required', 'array'],
            'expected_revision' => [$this->isMethod('put') ? 'required' : 'prohibited', 'integer', 'min:1']];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                CreatorDocument::validate($this->input('document'), (string) $this->user()->current_workspace_id);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field === 'document' ? $field : 'document.'.$field, $message);
                    }
                }
            }
        }];
    }
}
