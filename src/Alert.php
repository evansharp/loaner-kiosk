<?php

/**
 * A one-off feedback message for the admin dashboard.
 */
class Alert
{
    public function __construct(
        public readonly string $message,
        public readonly string $type // success, danger
    ) {
    }
}
