<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class QueueInspector
{
    /**
     * Count jobs due within the given timeframe (minutes), including reserved jobs.
     *
     * @param int $minutes Time window in minutes.
     
     * @return int
     */
    public static function countJobsWithinTimeframe(int $minutes): int
    {
        $defaultConnection = config('queue.default', 'database');
        $defaultDriver = config("queue.connections.{$defaultConnection}.driver", 'database');
        $queue = config("queue.connections.{$defaultConnection}.queue", 'default');

        if ($defaultDriver !== 'database') {
            return Queue::size();
        }

        $table = config("queue.connections.{$defaultConnection}.table", config('queue.connections.database.table', 'jobs'));
        $timestamp = Carbon::now()->addMinutes($minutes)->timestamp;

        try {
            return DB::table($table)
                ->where('queue', $queue)
                ->where(function ($query) use ($timestamp) {
                    $query->where('available_at', '<=', $timestamp)
                        ->orWhereNotNull('reserved_at');
                })
                ->count();
        } catch (Throwable) {
            return Queue::size();
        }
    }
}
