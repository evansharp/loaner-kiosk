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

    /** @var string Where to show it: page (top of page) or settings (in the settings card) */
    public $section;

    public function __construct(string $message, string $type, string $section = 'page')
    {
        $this->message = $message;
        $this->type = $type;
        $this->section = $section;
    }
}
