<?php
/**
 * Shared HTML email layout. Email clients ignore most CSS, so this uses tables and inline styles.
 *
 * @var string $title      Email subject, for the document title
 * @var string $preheader  Preview text shown next to the subject in many inboxes
 * @var string $content    Rendered HTML of the content template
 * @var string $schoolName
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        /* Narrow screens (for mail apps that support it): less side padding */
        @media (max-width: 480px) {
            .email-outer { padding: 12px 6px !important; }
            .email-pad { padding-left: 18px !important; padding-right: 18px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0;"><?= htmlspecialchars($preheader) ?></div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f6f9;">
        <tr>
            <td align="center" class="email-outer" style="padding: 24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 640px; background-color: #ffffff; border-radius: 12px; border-top: 4px solid #0d6efd;">
                    <tr>
                        <td class="email-pad" style="padding: 28px 32px 8px 32px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #6c757d;">
                            <?= htmlspecialchars($schoolName) ?> &middot; Loaner Chromebooks
                        </td>
                    </tr>
                    <tr>
                        <td class="email-pad" style="padding: 0 32px 28px 32px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.55; color: #212529;">
                            <?= $content ?>
                        </td>
                    </tr>
                </table>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 640px;">
                    <tr>
                        <td style="padding: 16px 32px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 12px; line-height: 1.5; color: #6c757d; text-align: center;">
                            Sent automatically by the <?= htmlspecialchars($schoolName) ?> Loaner Chromebook Kiosk.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
