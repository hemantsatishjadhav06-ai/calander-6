<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Models\EditorialQueue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEditorialQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAllPermissions(['workspace.settings.manage'], $this->user()->current_workspace_id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $queue = $this->route('editorialQueue');

        return [
            'expected_workspace_id' => ['required', 'uuid', Rule::in([$this->user()->current_workspace_id])],
            'expected_revision' => [$this->isMethod('post') ? 'nullable' : 'required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:100', Rule::unique('editorial_queues')->where('workspace_id', $this->user()->current_workspace_id)->ignore($queue instanceof EditorialQueue ? $queue->id : null)],
            'category' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
