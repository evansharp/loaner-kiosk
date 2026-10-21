<?php

/**
 * UX feedback state passed from the controller to the view.
 */
class KioskState
{
    // (Untyped properties: kiosks run macOS Catalina's built-in PHP 7.3)
    public $message = null;
    public $messageType = null; // success, warning, danger
    public $soundType = null;   // success, warning, error
    public $flashClass = null;  // flash-success, flash-warning, flash-danger
    public $prefillAsset = '';
    public $prefillUser = '';
    public $focusTarget = 'asset_number'; // Input ID to focus on load
    public $autoReset = false;            // Trigger auto-reset timer in JS

    public function success(string $message): void
    {
        $this->setFeedback($message, 'success', 'success', 'flash-success');
    }

    public function warning(string $message): void
    {
        $this->setFeedback($message, 'warning', 'warning', 'flash-warning');
    }

    public function error(string $message): void
    {
        $this->setFeedback($message, 'danger', 'error', 'flash-danger');
    }

    private function setFeedback(string $message, string $messageType, string $soundType, string $flashClass): void
    {
        $this->message = $message;
        $this->messageType = $messageType;
        $this->soundType = $soundType;
        $this->flashClass = $flashClass;
    }
}
