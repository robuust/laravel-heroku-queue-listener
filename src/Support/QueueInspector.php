<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QueueInspector
{
    /**
     * Count jobs due within the given timeframe (minutes), including reserved jobs.
     *
     * @param int $minutes Time window in minutes.
     * 
     * @return int
     */
    public static function countJobsWithinTimeframe(int $minutes): int
    {
        $defaultConnection = config('queue.default', 'database');
        $queue = config("queue.connections.{$defaultConnection}.queue", 'default');
        $table = config("queue.connections.{$defaultConnection}.table", config('queue.connections.database.table', 'jobs'));
        $timestamp = Carbon::now()->addMinutes($minutes)->timestamp;

        return DB::table($table)
            ->where('queue', $queue)
            ->where(function ($query) use ($timestamp) {
                $query->where('available_at', '<=', $timestamp)
                    ->orWhereNotNull('reserved_at');
            })
            ->count();
    }
}
