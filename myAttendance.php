<?php
include('dbconfig.php');

// ── Auto-create lecmapping table if missing ───────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `lecmapping` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `faculty`     VARCHAR(50)  NOT NULL,
    `term`        VARCHAR(20)  NOT NULL,
    `sem`         VARCHAR(10)  NOT NULL,
    `subject`     VARCHAR(100) NOT NULL,
    `class`       VARCHAR(5)   NOT NULL,
    `slot`        VARCHAR(50)  NOT NULL,
    `start_date`  DATE         NOT NULL,
    `end_date`    DATE         NOT NULL,
    `repeat_days` VARCHAR(20)  NOT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$session_faculty_name = $_SESSION['Name'] ?? '';

// Get logged-in faculty id
$fac_id_stmt = $conn->prepare("SELECT id FROM faculty WHERE Name = ?");
$fac_id_stmt->bind_param('s', $session_faculty_name);
$fac_id_stmt->execute();
$fac_row = $fac_id_stmt->get_result()->fetch_assoc();
$fac_id_stmt->close();
$logged_faculty_id = $fac_row ? (string)$fac_row['id'] : '0';

// ── Filters from GET ──────────────────────────────────────────────────────────
$filter_status  = $_GET['status']  ?? 'all';   // all | filled | unfilled
$filter_mapping = (int)($_GET['mapping'] ?? 0); // specific mapping id, 0 = all

// ── Load all mappings for this faculty ───────────────────────────────────────
$mappings_stmt = $conn->prepare("SELECT * FROM lecmapping WHERE faculty = ? ORDER BY start_date, id");
$mappings_stmt->bind_param('s', $logged_faculty_id);
$mappings_stmt->execute();
$mappings_rows = $mappings_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mappings_stmt->close();

// ── Expand each mapping into individual date slots ───────────────────────────
// slot_list: array of [mapping_id, date, faculty, term, sem, subject, class, slot]
$slot_list = [];
foreach ($mappings_rows as $m) {
    if ($filter_mapping > 0 && $m['id'] !== $filter_mapping) continue;

    $repeat_days = array_map('intval', explode(',', $m['repeat_days']));
    $cur = new DateTime($m['start_date']);
    $end = new DateTime($m['end_date']);
    $end->modify('+1 day'); // make end inclusive

    while ($cur < $end) {
        $dow = (int)$cur->format('w'); // 0=Sun … 6=Sat
        if (in_array($dow, $repeat_days, true)) {
            $slot_list[] = [
                'mapping_id' => $m['id'],
                'date'       => $cur->format('Y-m-d'),
                'faculty'    => $m['faculty'],
                'term'       => $m['term'],
                'sem'        => $m['sem'],
                'subject'    => $m['subject'],
                'class'      => $m['class'],
                'slot'       => $m['slot'],
            ];
        }
        $cur->modify('+1 day');
    }
}

// Sort by date descending (newest first)
usort($slot_list, fn($a, $b) => strcmp($b['date'], $a['date']));

// ── Check which slots are already filled ─────────────────────────────────────
// Build a lookup: "term|sem|subject|class|date|slot" => attendance_id
$filled_lookup = [];
if (!empty($slot_list)) {
    // Collect unique term/sem combos to query efficiently
    $unique_terms = array_values(array_unique(array_column($slot_list, 'term')));
    $unique_sems  = array_values(array_unique(array_column($slot_list, 'sem')));

    if (!empty($unique_terms) && !empty($unique_sems)) {
        $t_placeholders = implode(',', array_fill(0, count($unique_terms), '?'));
        $s_placeholders = implode(',', array_fill(0, count($unique_sems),  '?'));
        $types = str_repeat('s', count($unique_terms) + count($unique_sems));
        $params = array_merge($unique_terms, $unique_sems);

        $att_stmt = $conn->prepare("SELECT id, date, time, term, sem, subject, class FROM lecattendance WHERE term IN ($t_placeholders) AND sem IN ($s_placeholders)");
        $att_stmt->bind_param($types, ...$params);
        $att_stmt->execute();
        $att_res = $att_stmt->get_result();
        while ($ar = $att_res->fetch_assoc()) {
            $key = $ar['term'] . '|' . $ar['sem'] . '|' . $ar['subject'] . '|' . $ar['class'] . '|' . $ar['date'] . '|' . $ar['time'];
            $filled_lookup[$key] = (int)$ar['id'];
        }
        $att_stmt->close();
    }
}

// ── Annotate each slot with filled status ─────────────────────────────────────
foreach ($slot_list as &$slot) {
    $key = $slot['term'] . '|' . $slot['sem'] . '|' . $slot['subject'] . '|' . $slot['class'] . '|' . $slot['date'] . '|' . $slot['slot'];
    $slot['filled']        = isset($filled_lookup[$key]);
    $slot['attendance_id'] = $filled_lookup[$key] ?? null;
}
unset($slot);

// ── Apply status filter ───────────────────────────────────────────────────────
if ($filter_status === 'filled') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => $s['filled']));
} elseif ($filter_status === 'unfilled') {
    $slot_list = array_values(array_filter($slot_list, fn($s) => !$s['filled']));
}

// ── Faculty name lookup ───────────────────────────────────────────────────────
$faculty_map = [];
$fres = $conn->query("SELECT id, Name FROM faculty");
while ($fr = $fres->fetch_assoc()) {
    $faculty_map[(string)$fr['id']] = $fr['Name'];
}

// Stats
$total    = count($slot_list);
$filled   = count(array_filter($slot_list, fn($s) => $s['filled']));
$unfilled = $total - $filled;

$day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h1 class="app-page-title mb-0"><i class="bi bi-calendar2-check me-2"></i>My Attendance</h1>
                <a href="addMapping.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>Add / Manage Mappings
                </a>
            </div>

            <?php if (empty($mappings_rows)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No lecture mappings found for your account.
                    <a href="addMapping.php" class="alert-link">Create a mapping</a> to get started.
                </div>
            <?php else: ?>

            <!-- Stats row -->
            <div class="row g-3 mb-3">
                <div class="col-4 col-md-2">
                    <div class="app-card shadow-sm text-center">
                        <div class="app-card-body py-2">
                            <div class="fs-4 fw-bold"><?= $total ?></div>
                            <div class="text-muted" style="font-size:0.75rem;">Total</div>
                        </div>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="app-card shadow-sm text-center">
                        <div class="app-card-body py-2">
                            <div class="fs-4 fw-bold text-success"><?= $filled ?></div>
                            <div class="text-muted" style="font-size:0.75rem;">Filled</div>
                        </div>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="app-card shadow-sm text-center">
                        <div class="app-card-body py-2">
                            <div class="fs-4 fw-bold text-danger"><?= $unfilled ?></div>
                            <div class="text-muted" style="font-size:0.75rem;">Pending</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body py-2">
                    <form method="GET" action="myAttendance.php" class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold me-1" style="font-size:0.85rem;">Filter:</span>

                        <div class="btn-group btn-group-sm" role="group">
                            <a href="?status=all&mapping=<?= $filter_mapping ?>"
                               class="btn <?= $filter_status === 'all'      ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
                            <a href="?status=unfilled&mapping=<?= $filter_mapping ?>"
                               class="btn <?= $filter_status === 'unfilled' ? 'btn-danger'    : 'btn-outline-danger' ?>">
                               <i class="bi bi-exclamation-circle me-1"></i>Pending</a>
                            <a href="?status=filled&mapping=<?= $filter_mapping ?>"
                               class="btn <?= $filter_status === 'filled'   ? 'btn-success'   : 'btn-outline-success' ?>">
                               <i class="bi bi-check-circle me-1"></i>Filled</a>
                        </div>

                        <select name="mapping" class="form-select form-select-sm" style="max-width:260px;" onchange="this.form.submit()">
                            <option value="0">All Mappings</option>
                            <?php foreach ($mappings_rows as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= ($filter_mapping === (int)$m['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['subject'] . ' · Class ' . $m['class'] . ' · ' . $m['slot']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                    </form>
                </div>
            </div>

            <!-- Slot list -->
            <div class="app-card shadow-sm">
                <div class="app-card-body p-0">
                    <?php if (empty($slot_list)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-calendar-x display-6 d-block mb-2"></i>
                            No slots match the current filter.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="width:36px;">#</th>
                                        <th>Date</th>
                                        <th>Day</th>
                                        <th>Subject</th>
                                        <th>Class</th>
                                        <th>Slot</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($slot_list as $i => $slot):
                                    $date_obj = new DateTime($slot['date']);
                                    $dow_name = $day_names[(int)$date_obj->format('w')];
                                    $is_today = ($slot['date'] === date('Y-m-d'));

                                    // Build URL to takelecatt.php
                                    $params = http_build_query([
                                        'faculty' => $slot['faculty'],
                                        'term'    => $slot['term'],
                                        'sem'     => $slot['sem'],
                                        'subject' => $slot['subject'],
                                        'class'   => $slot['class'],
                                        'date'    => $slot['date'],
                                        'slot'    => $slot['slot'],
                                    ]);
                                    $take_url = 'takelecatt.php?' . $params;
                                    $edit_url = $slot['filled'] ? 'editlecatt.php?id=' . $slot['attendance_id'] : null;
                                    $summary_url = $slot['filled'] ? 'attendanceSummary.php?type=lecture&id=' . $slot['attendance_id'] : null;
                                ?>
                                <tr class="<?= !$slot['filled'] && $is_today ? 'table-warning' : (!$slot['filled'] ? 'table-danger-subtle' : '') ?>">
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($slot['date']) ?></strong>
                                        <?php if ($is_today): ?>
                                            <span class="badge bg-warning text-dark ms-1">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $dow_name ?></td>
                                    <td><?= htmlspecialchars($slot['subject']) ?></td>
                                    <td><span class="badge bg-primary-subtle text-dark border"><?= htmlspecialchars($slot['class']) ?></span></td>
                                    <td><?= htmlspecialchars($slot['slot']) ?></td>
                                    <td>
                                        <?php if ($slot['filled']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Filled</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-exclamation-circle me-1"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($slot['filled']): ?>
                                            <a href="<?= htmlspecialchars($summary_url) ?>" class="btn btn-outline-success btn-sm me-1" title="View Summary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($edit_url) ?>" class="btn btn-outline-primary btn-sm" title="Edit Attendance">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($take_url) ?>" class="btn btn-warning btn-sm">
                                                <i class="bi bi-clipboard-check me-1"></i>Take Attendance
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.table-danger-subtle {
    background-color: rgba(220, 53, 69, 0.05);
}
.sticky-top {
    top: 0;
    z-index: 1;
}
</style>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>
