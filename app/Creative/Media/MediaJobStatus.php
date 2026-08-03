<?php

namespace App\Creative\Media;

enum MediaJobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    public function terminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::TimedOut,
        ], true);
    }
}
