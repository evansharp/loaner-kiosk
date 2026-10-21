<?php
/**
 * Overdue summary for administrators (inside layout.php).
 * Two columns (asset, then stacked details) so it stays readable on phones.
 *
 * @var string  $heading
 * @var string  $intro
 * @var array[] $rows ['asset', 'name', 'email', 'checkedOut', 'outFor', 'status', 'statusTone' (ok|muted|error)]
 */
$toneColors = ['ok' => '#198754', 'muted' => '#6c757d', 'error' => '#dc3545'];
$cell = 'padding: 12px 8px; border-bottom: 1px solid #e9ecef; vertical-align: top; font-size: 14px; line-height: 1.5;';
$headCell = 'padding: 8px; border-bottom: 2px solid #dee2e6; text-align: left; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6c757d;';
$detail = 'font-size: 13px; color: #6c757d;';
?>
<h1 style="margin: 0 0 8px 0; font-size: 22px; line-height: 1.3; color: #212529;"><?= htmlspecialchars($heading) ?></h1>
<p style="margin: 0 0 20px 0;"><?= htmlspecialchars($intro) ?></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
    <tr>
        <th style="<?= $headCell ?> width: 64px;">Asset</th>
        <th style="<?= $headCell ?>">Borrower</th>
    </tr>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td style="<?= $cell ?> font-family: Menlo, Consolas, monospace; font-weight: 700; white-space: nowrap;"><?= htmlspecialchars($row['asset']) ?></td>
            <td style="<?= $cell ?>">
                <strong><?= htmlspecialchars($row['name']) ?></strong>
                <?php if ($row['email'] !== ''): ?>
                    <br><span style="<?= $detail ?> word-break: break-all;"><?= htmlspecialchars($row['email']) ?></span>
                <?php endif; ?>
                <br><span style="<?= $detail ?>">Checked out <?= htmlspecialchars($row['checkedOut']) ?> &middot; out for <?= htmlspecialchars($row['outFor']) ?></span>
                <br><span style="font-size: 13px; color: <?= $toneColors[$row['statusTone']] ?? '#6c757d' ?>;"><?= htmlspecialchars($row['status']) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p style="margin: 20px 0 0 0; font-size: 13px; color: #6c757d;">
    Returned devices can be checked in at the kiosk, and records corrected on its admin page.
</p>
