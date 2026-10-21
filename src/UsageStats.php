<?php

/**
 * Totals time checked out per device and per user.
 */
class UsageStats
{
    /** @var array<string, int> asset_number => total_seconds, sorted descending */
    public array $deviceSeconds = [];

    /** @var array<string, int> user_name => total_seconds, sorted descending */
    public array $userSeconds = [];

    /**
     * @param array $records Checkout rows; active checkouts count up to $now
     */
    public function __construct(array $records, int $now)
    {
        foreach ($records as $record) {
            $outTime = strtotime($record['checkout_time']);
            $inTime = $record['checkin_time'] ? strtotime($record['checkin_time']) : $now;

            if ($outTime && $inTime && $inTime >= $outTime) {
                $duration = $inTime - $outTime;

                $asset = $record['asset_number'];
                $this->deviceSeconds[$asset] = ($this->deviceSeconds[$asset] ?? 0) + $duration;

                $user = $record['user_name'];
                $this->userSeconds[$user] = ($this->userSeconds[$user] ?? 0) + $duration;
            }
        }

        arsort($this->deviceSeconds);
        arsort($this->userSeconds);
    }

    public function topUsers(int $limit): array
    {
        return array_slice($this->userSeconds, 0, $limit, true);
    }

    /**
     * Formats seconds into readable hours/minutes (e.g. "3h 30m", "20m").
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) return '0m';
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        return "{$minutes}m";
    }
}
