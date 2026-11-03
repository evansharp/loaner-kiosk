<?php
/**
 * Usage report for administrators (inside layout.php).
 *
 * @var string  $heading
 * @var string  $period     e.g. "Sep 1 – Nov 3, 2026"
 * @var string  $intro
 * @var array[] $summary    ['label', 'value']
 * @var array[] $topDevices ['asset', 'time']
 * @var array[] $topUsers   ['name', 'email', 'checkouts', 'time'] (the first of $users)
 * @var array[] $users      ['name', 'email', 'checkouts', 'time'], most time first
 * @var int     $topCount
 */
$cell = 'padding: 10px 8px; border-bottom: 1px solid #e9ecef; vertical-align: top; font-size: 14px; line-height: 1.5;';
$headCell = 'padding: 8px; border-bottom: 2px solid #dee2e6; text-align: left; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6c757d;';
$rankCell = 'width: 28px; color: #6c757d; font-weight: 700;';
$timeCell = 'text-align: right; white-space: nowrap; font-family: Menlo, Consolas, monospace; font-weight: 700;';
$detail = 'font-size: 13px; color: #6c757d;';
$sectionHeading = 'margin: 28px 0 8px 0; font-size: 16px; line-height: 1.3; color: #212529;';

$userTable = function (array $rows) use ($cell, $headCell, $rankCell, $timeCell, $detail): void { ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr>
            <th style="<?= $headCell ?> width: 28px;">#</th>
            <th style="<?= $headCell ?>">User</th>
            <th style="<?= $headCell ?> text-align: right;">Time</th>
        </tr>
        <?php foreach ($rows as $i => $row): ?>
            <tr>
                <td style="<?= $cell ?> <?= $rankCell ?>"><?= $i + 1 ?></td>
                <td style="<?= $cell ?>">
                    <strong><?= htmlspecialchars($row['name']) ?></strong>
                    <?php if ($row['email'] !== ''): ?>
                        <br><span style="<?= $detail ?> word-break: break-all;"><?= htmlspecialchars($row['email']) ?></span>
                    <?php endif; ?>
                    <br><span style="<?= $detail ?>"><?= (int)$row['checkouts'] ?> checkout<?= $row['checkouts'] === 1 ? '' : 's' ?></span>
                </td>
                <td style="<?= $cell ?> <?= $timeCell ?>"><?= htmlspecialchars($row['time']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php };
?>
<h1 style="margin: 0 0 4px 0; font-size: 22px; line-height: 1.3; color: #212529;"><?= htmlspecialchars($heading) ?></h1>
<p style="margin: 0 0 12px 0; font-weight: 700; color: #0d6efd;"><?= htmlspecialchars($period) ?></p>
<p style="margin: 0 0 20px 0; <?= $detail ?>"><?= htmlspecialchars($intro) ?></p>

<!-- Summary: two tiles per row so it stays readable on phones -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: separate; border-spacing: 6px;">
    <?php foreach (array_chunk($summary, 2) as $pair): ?>
        <tr>
            <?php foreach ($pair as $item): ?>
                <td width="50%" style="padding: 12px 14px; background-color: #f4f6f9; border-radius: 8px;">
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6c757d;"><?= htmlspecialchars($item['label']) ?></div>
                    <div style="font-size: 22px; font-weight: 700; color: #212529;"><?= htmlspecialchars($item['value']) ?></div>
                </td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
</table>

<?php if (empty($users)): ?>
    <p style="margin: 24px 0 0 0; color: #6c757d;">No checkouts in this period.</p>
<?php else: ?>
    <h2 style="<?= $sectionHeading ?>">Top <?= (int)$topCount ?> devices by time</h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr>
            <th style="<?= $headCell ?> width: 28px;">#</th>
            <th style="<?= $headCell ?>">Asset</th>
            <th style="<?= $headCell ?> text-align: right;">Time</th>
        </tr>
        <?php foreach ($topDevices as $i => $device): ?>
            <tr>
                <td style="<?= $cell ?> <?= $rankCell ?>"><?= $i + 1 ?></td>
                <td style="<?= $cell ?> font-family: Menlo, Consolas, monospace; font-weight: 700;"><?= htmlspecialchars($device['asset']) ?></td>
                <td style="<?= $cell ?> <?= $timeCell ?>"><?= htmlspecialchars($device['time']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2 style="<?= $sectionHeading ?>">Top <?= (int)$topCount ?> users by time</h2>
    <?php $userTable($topUsers); ?>

    <h2 style="<?= $sectionHeading ?>">All users by time (<?= count($users) ?>)</h2>
    <?php $userTable($users); ?>
<?php endif; ?>

<p style="margin: 20px 0 0 0; font-size: 13px; color: #6c757d;">
    Typed names that aren't linked to the Google directory are totalled separately. Link them from a record's Edit dialog on the admin page.
</p>
