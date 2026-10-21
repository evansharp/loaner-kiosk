<?php
/**
 * Overdue note to a borrower about their own devices (inside layout.php).
 *
 * @var string  $heading
 * @var string  $name
 * @var string  $intro
 * @var array[] $devices ['asset', 'checkedOut']
 * @var string  $it      "it" or "them"
 */
?>
<h1 style="margin: 0 0 16px 0; font-size: 22px; line-height: 1.3; color: #212529;"><?= htmlspecialchars($heading) ?></h1>
<p style="margin: 0 0 12px 0;">Hi <?= htmlspecialchars($name) ?>,</p>
<p style="margin: 0 0 16px 0;"><?= htmlspecialchars($intro) ?></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 20px 0; background-color: #fff3cd; border-radius: 8px;">
    <?php foreach ($devices as $device): ?>
        <tr>
            <td style="padding: 12px 16px; font-size: 15px;">
                <span style="font-family: Menlo, Consolas, monospace; font-weight: 700;">Chromebook <?= htmlspecialchars($device['asset']) ?></span>
                <br><span style="font-size: 13px; color: #6c757d;">Checked out <?= htmlspecialchars($device['checkedOut']) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p style="margin: 0 0 12px 0;"><strong>Please return <?= $it ?> to the loaner kiosk as soon as you can.</strong></p>
<p style="margin: 0; color: #6c757d;">Already returned <?= $it ?>? Reply to this email and let us know.</p>
