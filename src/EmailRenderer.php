<?php

/**
 * Renders HTML emails from templates/emails/ inside the shared layout, and adds the
 * matching footer to their plain-text versions.
 */
class EmailRenderer
{
    /** @var string */
    private $templateDir;

    public function __construct(?string $templateDir = null)
    {
        $this->templateDir = $templateDir ?: dirname(__DIR__) . '/templates/emails';
    }

    /**
     * Renders a content template inside the shared email layout.
     *
     * @param string $title     Email subject
     * @param string $preheader Preview text shown next to the subject in many inboxes
     */
    public function html(string $template, string $title, string $preheader, string $schoolName, array $vars): string
    {
        $content = $this->render($template, $vars);
        return $this->render('layout', [
            'title' => $title,
            'preheader' => $preheader,
            'content' => $content,
            'schoolName' => $schoolName,
        ]);
    }

    /**
     * Joins plain-text lines and adds the signature footer.
     */
    public static function text(array $lines, string $schoolName): string
    {
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = $schoolName . ' - sent automatically by the Loaner Chromebook Kiosk.';
        return implode("\n", $lines) . "\n";
    }

    private function render(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require "{$this->templateDir}/$template.php";
        return ob_get_clean();
    }
}
