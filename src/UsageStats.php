<?php

/**
 * Totals time checked out per device and per user.
 */
class UsageStats
{
    /** @var array<string, int> asset_number => total_seconds, sorted descending */
    public $deviceSeconds = [];

    /**
     * @var array[] user key => ['name', 'email', 'seconds']. Directory-linked checkouts are
     *              grouped by Google ID (so renames don't split a person); typed names by name.
     */
    private $users = [];

    /**
     * @param array $records Checkout rows, newest first; active checkouts count up to $now
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

                $userKey = !empty($record['user_id']) ? 'id:' . $record['user_id'] : 'name:' . $record['user_name'];
                if (!isset($this->users[$userKey])) {
                    // Records are newest first, so this is the person's latest name
                    $this->users[$userKey] = ['name' => $record['user_name'], 'email' => $record['user_email'] ?? null, 'seconds' => 0];
                }
                $this->users[$userKey]['seconds'] += $duration;
            }
        }

        arsort($this->deviceSeconds);
    }

    /**
     * @return array[] ['name', 'email' (null for typed names), 'seconds'], most time first
     */
    public function topUsers(int $limit): array
    {
        $users = array_values($this->users);
        usort($users, function (array $a, array $b): int {
            return $b['seconds'] <=> $a['seconds'];
        });
        return array_slice($users, 0, $limit);
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
