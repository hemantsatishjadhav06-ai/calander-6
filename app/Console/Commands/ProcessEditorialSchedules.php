<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RecurringPostSeries;
use App\Models\Workspace;
use App\Services\Scheduling\PriorityQueueScheduler;
use App\Services\Scheduling\RecurringDraftGenerator;
use Illuminate\Console\Command;
use Throwable;

class ProcessEditorialSchedules extends Command
{
    protected $signature = 'posts:process-editorial-schedules';

    protected $description = 'Generate review-required recurring drafts and fill priority queues using existing posting slots.';

    public function handle(RecurringDraftGenerator $generator, PriorityQueueScheduler $scheduler): int
    {
        $failed = false;
        foreach (RecurringPostSeries::withoutGlobalScopes()->where('state', 'active')->lazyById(100) as $series) {
            try {
                $generator->generate($series);
            } catch (Throwable $exception) {
                report($exception);
                $series->forceFill(['last_error' => 'Draft generation failed. Retry after checking the source post and media.'])->save();
                $failed = true;
            }
        }
        foreach (Workspace::query()->whereIn('id', function ($query): void {
            $query->select('workspace_id')->from('editorial_queues');
        })->lazyById(100) as $workspace) {
            try {
                $scheduler->fill($workspace);
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
