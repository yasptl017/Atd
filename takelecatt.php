<?php
include('dbconfig.php');

function short_name($full_name) {
    $full_name = trim((string)$full_name);
    if ($full_name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $full_name);
    if (count($parts) >= 2) {
        return $parts[0] . ' ' . $parts[1];
    }
    return $full_name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $date    = $_POST['date'];
    $time    = $_POST['slot'];
    $term    = $_POST['term'];
    $faculty = $_POST['faculty'];
    $sem     = $_POST['sem'];
    $subject = $_POST['subject'];
    $class   = $_POST['class'];
    $present = isset($_POST['present']) ? implode(",", $_POST['present']) : '';

    $stmt = $conn->prepare("INSERT INTO lecattendance (date, logdate, time, term, faculty, sem, subject, class, presentNo) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $date, $time, $term, $faculty, $sem, $subject, $class, $present);
    $stmt->execute();
    $attendance_id = (int)$conn->insert_id;
    header("Location: attendanceSummary.php?type=lecture&id=" . $attendance_id);
    exit();
}

$data = $_POST ?: $_GET;

$faculty_id     = $data['faculty'];
$faculty_result = $conn->query("SELECT Name FROM faculty WHERE id = '$faculty_id'");
$faculty_name   = ($faculty_result->num_rows > 0) ? $faculty_result->fetch_assoc()['Name'] : $faculty_id;

$escaped_term = $conn->real_escape_string($data['term']);
$escaped_sem = $conn->real_escape_string($data['sem']);
$escaped_class = $conn->real_escape_string($data['class']);
$students_result = $conn->query("SELECT id, enrollmentNo, name, class FROM students WHERE term = '{$escaped_term}' AND sem = '{$escaped_sem}' AND class = '{$escaped_class}' ORDER BY enrollmentNo, name");
$total_students  = $students_result->num_rows;

// ── Autofill: today's related attendance records ──────────────────────────────
$class_enrollments = [];
if ($total_students > 0) {
    $students_result->data_seek(0);
    while ($s = $students_result->fetch_assoc()) {
        if (!empty($s['enrollmentNo'])) $class_enrollments[] = $s['enrollmentNo'];
    }
    $students_result->data_seek(0);
}

$autofill_records = [];
$att_date_esc = $conn->real_escape_string($data['date']);

// Other lecture records today (same class, different subject/slot)
$lec_res = $conn->query("SELECT id, subject, time, presentNo FROM lecattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND class='{$escaped_class}' AND date='{$att_date_esc}' ORDER BY id DESC");
if ($lec_res) {
    while ($row = $lec_res->fetch_assoc()) {
        $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
        $filt = array_values(array_intersect($all, $class_enrollments));
        if (!empty($filt)) {
            $autofill_records[] = ['type' => 'Lecture', 'label' => 'Lecture · ' . $row['subject'] . ' · ' . $row['time'], 'present' => $filt];
        }
    }
}

// Lab records today (same term, sem, date) — filter to students of this class
$lab_res = $conn->query("SELECT id, subject, batch, presentNo FROM labattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$att_date_esc}' AND labNo IS NOT NULL AND labNo!='' ORDER BY id DESC");
if ($lab_res) {
    while ($row = $lab_res->fetch_assoc()) {
        $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
        $filt = array_values(array_intersect($all, $class_enrollments));
        if (!empty($filt)) {
            $autofill_records[] = ['type' => 'Lab', 'label' => 'Lab · ' . $row['subject'] . ' · Batch ' . $row['batch'], 'present' => $filt];
        }
    }
}

// Tutorial records today — from tutattendance table
$tut_res = $conn->query("SELECT id, subject, batch, presentNo FROM tutattendance WHERE term='{$escaped_term}' AND sem='{$escaped_sem}' AND date='{$att_date_esc}' ORDER BY id DESC");
if ($tut_res) {
    while ($row = $tut_res->fetch_assoc()) {
        $all  = array_filter(array_map('trim', explode(',', (string)$row['presentNo'])));
        $filt = array_values(array_intersect($all, $class_enrollments));
        if (!empty($filt)) {
            $autofill_records[] = ['type' => 'Tutorial', 'label' => 'Tutorial · ' . $row['subject'] . ' · Batch ' . $row['batch'], 'present' => $filt];
        }
    }
}
// ─────────────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-check2-square me-2"></i>Take Lecture Attendance</h1>

            <!-- Lecture Details Card -->
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <h4>Lecture Details</h4>
                    <div class="row g-2" style="font-size:0.9rem;">
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Faculty</span>
                            <strong><?= htmlspecialchars($faculty_name) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Term</span>
                            <strong><?= htmlspecialchars($data['term']) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Semester</span>
                            <strong><?= htmlspecialchars($data['sem']) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Subject</span>
                            <strong><?= htmlspecialchars($data['subject']) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Class / Date</span>
                            <strong><?= htmlspecialchars($data['class']) ?> &bull; <?= htmlspecialchars($data['date']) ?></strong>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <span class="text-muted d-block" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;">Slot</span>
                            <strong><?= htmlspecialchars($data['slot']) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Autofill Panel -->
            <?php if (!empty($autofill_records)): ?>
            <div class="app-card shadow-sm mb-3">
                <div class="app-card-body">
                    <h5 class="mb-2"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Today's Attendance — Click to Autofill</h5>
                    <p class="text-muted mb-2" style="font-size:0.82rem;">Only students belonging to Class <strong><?= htmlspecialchars($data['class']) ?></strong> will be marked.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        $badge_colors = ['Lecture' => 'primary', 'Lab' => 'danger', 'Tutorial' => 'success'];
                        foreach ($autofill_records as $idx => $rec):
                            $color = $badge_colors[$rec['type']] ?? 'secondary';
                        ?>
                        <button type="button"
                                class="btn btn-outline-<?= $color ?> btn-sm autofill-btn"
                                data-present="<?= htmlspecialchars(json_encode($rec['present'])) ?>"
                                title="<?= htmlspecialchars($rec['label']) ?> (<?= count($rec['present']) ?> students from this class)">
                            
                            <?= htmlspecialchars($rec['label']) ?>
                            <span class="badge bg-<?= $color ?> ms-1"><?= count($rec['present']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attendance Form -->
            <form method="POST" action="takelecatt.php">
                <?php foreach ($data as $key => $value): ?>
                    <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
                <?php endforeach; ?>

                <div class="app-card shadow-sm">
                    <div class="app-card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <h4 class="mb-0">Mark Attendance
                                <span class="text-muted fw-normal" style="font-size:0.875rem;">(<?= $total_students ?> students)</span>
                            </h4>
                            <div class="attendance-actions mb-0">
                                <button type="button" class="btn btn-sm btn-outline-success" id="markAllBtn">
                                    <i class="bi bi-check-all me-1"></i>Mark All Present
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllBtn">
                                    <i class="bi bi-x-lg me-1"></i>Clear All
                                </button>
                            </div>
                        </div>

                        <?php if ($total_students > 0): ?>
                            <div class="row g-2" id="student-cards">
                                <?php
                                $students_result->data_seek(0);
                                while ($student = $students_result->fetch_assoc()):
                                    $student_roll = !empty($student['enrollmentNo']) ? $student['enrollmentNo'] : $student['id'];
                                    $display_name = short_name($student['name']);
                                    ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                        <label class="card shadow-sm p-2 text-center student-card" style="cursor:pointer;">
                                            <input type="checkbox" name="present[]" value="<?= htmlspecialchars((string)$student_roll); ?>" class="d-none attendance-checkbox">
                                            <div class="student-info">
                                                <strong><?= htmlspecialchars((string)$student_roll); ?></strong>
                                                <span class="d-block text-truncate" title="<?= htmlspecialchars($display_name); ?>">
                                                    <?= htmlspecialchars($display_name); ?>
                                                </span>
                                                <span class="d-block text-muted"><?= htmlspecialchars($student['class']); ?></span>
                                            </div>
                                        </label>
                                    </div>
                                <?php endwhile; ?>
                            </div>

                            <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                                <button type="submit" name="submit_attendance" class="btn btn-success px-4">
                                    Submit Attendance
                                </button>
                                <span class="text-muted" id="present-count" style="font-size:0.875rem;">0 marked present</span>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>No students found for the selected criteria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
    const cards = document.querySelectorAll('.student-card');
    const countEl = document.getElementById('present-count');

    function updateCount() {
        const checked = document.querySelectorAll('.attendance-checkbox:checked').length;
        if (countEl) countEl.textContent = checked + ' marked present';
    }

    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            const checkbox = card.querySelector('.attendance-checkbox');
            checkbox.checked = !checkbox.checked;
            card.classList.toggle('bg-success-subtle', checkbox.checked);
            card.classList.toggle('border-success', checkbox.checked);
            updateCount();
        });
    });

    document.getElementById('markAllBtn')?.addEventListener('click', function () {
        cards.forEach(card => {
            const cb = card.querySelector('.attendance-checkbox');
            cb.checked = true;
            card.classList.add('bg-success-subtle', 'border-success');
        });
        updateCount();
    });

    document.getElementById('clearAllBtn')?.addEventListener('click', function () {
        cards.forEach(card => {
            const cb = card.querySelector('.attendance-checkbox');
            cb.checked = false;
            card.classList.remove('bg-success-subtle', 'border-success');
        });
        updateCount();
    });

    // Autofill buttons
    document.querySelectorAll('.autofill-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const presentSet = new Set(JSON.parse(this.dataset.present));
            cards.forEach(function (card) {
                const cb = card.querySelector('.attendance-checkbox');
                if (presentSet.has(cb.value)) {
                    cb.checked = true;
                    card.classList.add('bg-success-subtle', 'border-success');
                }
            });
            updateCount();
        });
    });
</script>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>
