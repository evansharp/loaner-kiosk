<?php

/**
 * A one-off feedback message for the admin dashboard.
 */
class Alert
{
    /** @var string */
    public $message;

    /** @var string success, danger */
    public $type;

    /** @var string Where to show it: page (top of page), settings (notification card) or directory (directory card) */
    public $section;

    public function __construct(string $message, string $type, string $section = 'page')
    {
        $this->message = $message;
        $this->type = $type;
        $this->section = $section;
    }
}
