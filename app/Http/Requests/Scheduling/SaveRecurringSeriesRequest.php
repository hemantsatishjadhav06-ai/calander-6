<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRecurringSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAllPermissions(['workspace.settings.manage'], $this->user()->current_workspace_id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;
        $timezone = $this->string('timezone')->toString();
        $today = CarbonImmutable::now(in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ? $timezone : 'UTC')->toDateString();

        return [
            'expected_workspace_id' => ['required', 'uuid', Rule::in([$workspaceId])],
            'expected_revision' => [$this->isMethod('post') ? 'nullable' : 'required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:100'],
            'source_post_id' => ['required', 'uuid', Rule::exists('posts', 'id')->where('workspace_id', $workspaceId)->where('status', '!=', 'deleted')],
            'editorial_queue_id' => ['nullable', 'uuid', Rule::exists('editorial_queues', 'id')->where('workspace_id', $workspaceId)->where('state', '!=', 'stopped')],
            'timezone' => ['required', 'timezone:all'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'interval' => ['required', 'integer', 'min:1', 'max:366'],
            'starts_on' => [$this->isMethod('post') ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before:2100-01-01'],
            'local_time' => ['required', 'date_format:H:i'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before:2100-01-01'],
            'max_occurrences' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'lead_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ];
    }
}
