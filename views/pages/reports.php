<?php
declare(strict_types=1);

function reports_view(array $state): void
{
    [$from, $to, $rangeError] = report_range($state);
    $type = selected_report_type();
    $report = descriptive_report_data($state, $type, $from, $to);
    $exportUrl = page_url('reports', ['report' => $type, 'from' => $from, 'to' => $to, 'export' => 1]);
    $periodLabel = (new DateTimeImmutable($from))->format('M j, Y') . ' – ' . (new DateTimeImmutable($to))->format('M j, Y');
    ?>
    <header class="report-print-header">
        <div class="report-print-brand">
            <img src="assets/psa-logo.svg" width="64" height="64" alt="">
            <div class="report-print-brand-copy">
                <strong><?= h($state['settings']['company']) ?></strong>
                <span>Human Resource Management System</span>
            </div>
            <span class="report-print-badge">DESCRIPTIVE ANALYTICS</span>
        </div>
        <div class="report-print-title">
            <h1><?= h($report['title']) ?></h1>
            <p><?= h($report['description']) ?></p>
        </div>
        <div class="report-print-meta">
            <span><strong>Reporting period</strong><?= h($periodLabel) ?></span>
        </div>
    </header>

    <div class="page-head report-page-head">
        <div><h1>Reports</h1><p>Explore workforce, attendance, time off, and payroll trends.</p></div>
        <div class="actions report-actions">
            <a class="btn" href="<?= h($exportUrl) ?>"><?= icon('download') ?> Export CSV</a>
            <button class="btn primary" type="button" id="print-report">Print report</button>
        </div>
    </div>

    <section class="card report-controls">
        <div class="card-head"><div><h3>Build a report</h3><p>Select a subject and reporting period.</p></div></div>
        <div class="card-body">
            <?php get_form('reports');
            choice('report', 'Report type', report_types(), $type);
            field('from', 'From', $from, 'date');
            field('to', 'To', $to, 'date');
            end_form('Generate report'); ?>
        </div>
    </section>

    <?php if ($rangeError !== ''): ?><p class="card web-message" role="alert"><?= h($rangeError) ?></p><?php endif; ?>

    <div class="grid report-metrics">
        <?php foreach ($report['metrics'] as [$label, $value, $note]): ?>
            <section class="card report-metric">
                <span><?= h($label) ?></span>
                <strong><?= h($value) ?></strong>
                <small><?= h($note) ?></small>
            </section>
        <?php endforeach; ?>
    </div>

    <section class="card report-result">
        <div class="card-head">
            <div><h2><?= h($report['title']) ?></h2><p><?= h($report['description']) ?></p></div>
        </div>
        <p class="report-period">Reporting period: <?= h($periodLabel) ?></p>
        <div class="report-chart">
            <div class="report-chart-title"><?= h($report['chartTitle']) ?></div>
            <?php
            $chartType = $report['chartType'];
            $chartValues = [];
            foreach ($report['chart'] as $chartRow) {
                if ($chartType === 'grouped') {
                    $chartValues[] = (float) $chartRow[1];
                    $chartValues[] = (float) $chartRow[2];
                } elseif ($chartType === 'stacked') {
                    $chartValues[] = (float) $chartRow[1] + (float) $chartRow[2] + (float) $chartRow[3];
                } else {
                    $chartValues[] = (float) $chartRow[1];
                }
            }
            $max = max(array_merge([1], $chartValues));
            $chartTotal = array_sum(array_map(function ($row) { return (float) $row[1]; }, $report['chart']));
            $hasChartData = $report['chart'] && ($chartType !== 'donut' || $chartTotal > 0);
            $formatMeasure = function ($value) use ($report, $state) {
                if ($report['chartFormat'] === 'money') return money($value, $state);
                if ($report['chartFormat'] === 'percent') return number_format((float) $value, 1) . '%';
                if ($report['chartFormat'] === 'days') return number_format((float) $value, 0) . ((float) $value === 1.0 ? ' day' : ' days');
                return number_format((float) $value, 0);
            };
            ?>
            <?php if (!$hasChartData): ?>
                <div class="empty"><strong>No data for this period</strong><span>Choose a different date range or report type.</span></div>
            <?php elseif ($chartType === 'bar'): ?>
                <div class="report-bars">
                    <?php foreach ($report['chart'] as [$label, $value]): ?>
                        <div class="hbar">
                            <span><?= h($label) ?></span>
                            <div class="track"><span style="width:<?= h((string) min(100, max(0, (float) $value / $max * 100))) ?>%"></span></div>
                            <span class="v"><?= h($formatMeasure($value)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($chartType === 'grouped'): ?>
                <div class="report-chart-legend">
                    <span><i data-series="hires"></i> Hires</span>
                    <span><i data-series="departures"></i> Departures</span>
                </div>
                <div class="report-column-scroll">
                    <div class="report-columns">
                    <?php foreach ($report['chart'] as [$label, $hires, $departures]): ?>
                        <div class="report-column-group">
                            <div class="report-column-values">
                                <span><?= h($formatMeasure($hires)) ?></span>
                                <span><?= h($formatMeasure($departures)) ?></span>
                            </div>
                            <div class="report-column-pair" role="img" aria-label="<?= h($label . ': ' . $hires . ' hires, ' . $departures . ' departures') ?>">
                                <i data-series="hires" title="<?= h($hires . ' hires') ?>" style="height:<?= h((string) min(100, max(0, (float) $hires / $max * 100))) ?>%"></i>
                                <i data-series="departures" title="<?= h($departures . ' departures') ?>" style="height:<?= h((string) min(100, max(0, (float) $departures / $max * 100))) ?>%"></i>
                            </div>
                            <strong><?= h($label) ?></strong>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($chartType === 'stacked'): ?>
                <div class="report-chart-legend">
                    <span><i data-series="office"></i> Office</span>
                    <span><i data-series="remote"></i> Remote</span>
                    <span><i data-series="away"></i> Away</span>
                </div>
                <div class="report-stacked-bars">
                    <?php foreach ($report['chart'] as [$label, $office, $remote, $away, $rate]): $recorded = $office + $remote + $away; ?>
                        <div class="report-stacked-row">
                            <strong><?= h($label) ?></strong>
                            <div class="report-stacked-track" aria-label="<?= h($label . ': ' . $office . ' office, ' . $remote . ' remote, ' . $away . ' away') ?>">
                                <?php foreach ([['office', $office], ['remote', $remote], ['away', $away]] as [$series, $value]): ?>
                                    <?php if ($recorded > 0 && $value > 0): ?>
                                        <i data-series="<?= h($series) ?>" style="width:<?= h((string) ($value / $recorded * 100)) ?>%"></i>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <span class="report-stacked-rate"><?= h($formatMeasure($rate)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($chartType === 'donut'): ?>
                <?php
                $donutColors = ['var(--bar-1)', 'var(--bad)', 'var(--bar-3)', 'var(--attendance-remote)', 'var(--bar-2)'];
                $donutStops = [];
                $donutStart = 0;
                foreach ($report['chart'] as $index => $chartRow) {
                    if ((float) $chartRow[1] <= 0) continue;
                    $donutEnd = $donutStart + (float) $chartRow[1] / $chartTotal * 100;
                    $donutStops[] = $donutColors[$index % count($donutColors)] . ' ' . number_format($donutStart, 3, '.', '') . '% ' . number_format($donutEnd, 3, '.', '') . '%';
                    $donutStart = $donutEnd;
                }
                ?>
                <div class="report-donut-layout">
                    <div class="report-donut" style="--report-donut-stops:<?= h(implode(', ', $donutStops)) ?>" role="img" aria-label="<?= h($report['chartTitle'] . ': ' . $formatMeasure($chartTotal)) ?>">
                        <span><?= h($formatMeasure($chartTotal)) ?><small> total</small></span>
                    </div>
                    <div class="report-donut-legend">
                        <?php foreach ($report['chart'] as $index => [$label, $value]): ?>
                            <div><i data-color="<?= h((string) $index) ?>"></i><span><?= h($label) ?></span><strong><?= h($formatMeasure($value)) ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($chartType === 'line'): ?>
                <?php
                $plot = ['left' => 46, 'right' => 18, 'top' => 18, 'bottom' => 42, 'width' => 720, 'height' => 250];
                $plotWidth = $plot['width'] - $plot['left'] - $plot['right'];
                $plotHeight = $plot['height'] - $plot['top'] - $plot['bottom'];
                $linePoints = [];
                foreach ($report['chart'] as $index => [$label, $value]) {
                    $x = count($report['chart']) === 1 ? $plot['left'] + $plotWidth / 2 : $plot['left'] + $index * $plotWidth / (count($report['chart']) - 1);
                    $y = $plot['top'] + $plotHeight * (1 - (float) $value / $max);
                    $linePoints[] = ['x' => $x, 'y' => $y, 'label' => $label, 'value' => $value];
                }
                ?>
                <div class="report-line-scroll">
                    <svg class="report-line-chart" viewBox="0 0 <?= h((string) $plot['width']) ?> <?= h((string) $plot['height']) ?>" role="img" aria-label="<?= h($report['chartTitle']) ?>">
                        <?php for ($grid = 0; $grid <= 4; $grid++): $y = $plot['top'] + $plotHeight * $grid / 4; ?>
                            <line x1="<?= h((string) $plot['left']) ?>" y1="<?= h((string) $y) ?>" x2="<?= h((string) ($plot['width'] - $plot['right'])) ?>" y2="<?= h((string) $y) ?>" class="report-line-grid"/>
                        <?php endfor; ?>
                        <polyline points="<?php foreach ($linePoints as $point): ?><?= h(number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', '') . ' ') ?><?php endforeach; ?>" class="report-line"/>
                        <?php foreach ($linePoints as $point): ?>
                            <circle cx="<?= h((string) $point['x']) ?>" cy="<?= h((string) $point['y']) ?>" r="4" class="report-line-point">
                                <title><?= h($point['label'] . ': ' . $formatMeasure($point['value'])) ?></title>
                            </circle>
                            <text x="<?= h((string) $point['x']) ?>" y="<?= h((string) ($plot['height'] - 12)) ?>" text-anchor="middle" class="report-line-label"><?= h($point['label']) ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
            <?php endif; ?>
        </div>
        <div class="report-print-table">
            <table class="data">
                <thead><tr><?php foreach ($report['headers'] as $header): ?><th scope="col"><?= h($header) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php if (!$report['rows']): ?>
                        <tr><td colspan="<?= count($report['headers']) ?>">No records found for this period.</td></tr>
                    <?php else: foreach ($report['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $index => $value):
                                if ($value === null) $display = '—';
                                elseif (($type === 'headcount' && $index === 2) || ($type === 'payroll' && $index >= 3)) $display = money($value, $state);
                                elseif ($type === 'attendance' && $index === 5) $display = number_format((float) $value, 1) . '%';
                                elseif ($type === 'leave' && $index === 2) $display = number_format((float) $value, 0) . ((float) $value === 1.0 ? ' day' : ' days');
                                else $display = (string) $value;
                            ?>
                                <td><?= h($display) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="report-print-footer">Generated <?= h((new DateTimeImmutable($state['today']))->format('M j, Y')) ?> · <?= h($state['settings']['company']) ?></div>
    </section>
    <script>
        (function () {
            var button = document.getElementById('print-report');
            if (button) button.addEventListener('click', function () { window.print(); });
        }());
    </script>
    <?php
}

reports_view($state);
