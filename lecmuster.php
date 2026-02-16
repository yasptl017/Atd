<?php
include('dbconfig.php');
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Fetch dropdown data
$faculty_result = $conn->query("SELECT id, Name FROM faculty WHERE status = 1");
$term_result = $conn->query("SELECT DISTINCT term FROM students ORDER BY term DESC");
$sem_result = $conn->query("SELECT sem FROM semester WHERE status = 1");
$subject_result = $conn->query("SELECT DISTINCT subjectName FROM subjects WHERE status = 1");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $faculty_id = $_POST['faculty'];
    $term = $_POST['term'];
    $sem = $_POST['sem'];
    $subject = $_POST['subject'];
    $class = $_POST['class'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    // Get faculty name
    $faculty_name = $conn->query("SELECT Name FROM faculty WHERE id = '$faculty_id'")->fetch_assoc()['Name'];

    // Generate list of weekdays (excluding Sat/Sun)
    $dates = [];
    $current = strtotime($start_date);
    $end = strtotime($end_date);
    while ($current <= $end) {
        if (date('N', $current) < 6) {
            $dates[] = date('n/j/Y', $current);  // Format to match stored varchar like "6/23/2025"
        }
        $current = strtotime('+1 day', $current);
    }

    // Get list of students
    $students = [];
    $stu_result = $conn->query("SELECT enrollmentNo FROM students WHERE term='$term' AND sem='$sem' AND class='$class' ORDER BY enrollmentNo");
    while ($row = $stu_result->fetch_assoc()) {
        $students[] = trim($row['enrollmentNo']);
    }

    // Create spreadsheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $totalCols = count($dates) + 1;
    $lastCol = Coordinate::stringFromColumnIndex($totalCols);

    // Header
    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue("A1", "K.D. Polytechnic, Patan");
    $sheet->getStyle("A1")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells("A2:{$lastCol}2");
    $sheet->setCellValue("A2", "Department of Computer Engineering");
    $sheet->getStyle("A2")->getFont()->setBold(true);
    $sheet->getStyle("A2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells("A3:{$lastCol}3");
    $sheet->setCellValue("A3", "Term: $term");
    $sheet->getStyle("A3")->getFont()->setBold(true);

    $sheet->mergeCells("A4:{$lastCol}4");
    $sheet->setCellValue("A4", "Faculty: $faculty_name, Semester: $sem, Class: $class, Subject: $subject");
    $sheet->getStyle("A4")->getFont()->setBold(true);

    // Date headers
    $sheet->setCellValue("A5", "Enrollment No");
    foreach ($dates as $i => $date) {
        $col = Coordinate::stringFromColumnIndex($i + 2);
        $sheet->setCellValue("{$col}5", $date);
    }
    $sheet->getStyle("A5:{$lastCol}5")->getFont()->setBold(true);
    $sheet->getStyle("A5:{$lastCol}5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Attendance
    $rowIndex = 6;
    foreach ($students as $enroll) {
        $sheet->setCellValue("A{$rowIndex}", $enroll);

        foreach ($dates as $i => $date) {
            $col = Coordinate::stringFromColumnIndex($i + 2);

            $query = "SELECT presentNo FROM lecattendance WHERE date='$date' AND faculty='$faculty_name' AND sem='$sem' AND subject='$subject' AND class='$class'";
            $res = $conn->query($query);

            $present = false;
            while ($row = $res->fetch_assoc()) {
                $presentNos = array_map('trim', explode(',', $row['presentNo']));
                if (in_array($enroll, $presentNos)) {
                    $present = true;
                    break;
                }
            }

            $sheet->setCellValue("{$col}{$rowIndex}", $present ? 'P' : 'A');
        }
        $rowIndex++;
    }

    // Add borders
    $sheet->getStyle("A5:{$lastCol}" . ($rowIndex - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // Descriptions
    $descRow = $rowIndex + 2;
    $sheet->setCellValue("A{$descRow}", "Descriptions:");
    $sheet->getStyle("A{$descRow}")->getFont()->setBold(true);
    $descRow++;

    foreach ($dates as $date) {
        $desc_q = "SELECT description FROM lecattendance WHERE date='$date' AND faculty='$faculty_name' AND sem='$sem' AND subject='$subject' AND class='$class' AND description IS NOT NULL";
        $desc_r = $conn->query($desc_q);
        while ($desc = $desc_r->fetch_assoc()) {
            $sheet->setCellValue("A{$descRow}", "$date - " . $desc['description']);
            $descRow++;
        }
    }

    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="lec_muster.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<?php include('head.php'); ?>
<body class="app">
<?php include('header.php'); ?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            <h1 class="app-page-title"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Generate Lecture Muster</h1>

            <div class="row mb-4">
                <div class="col-12 col-lg-8">
                    <div class="app-card shadow-sm">
                        <div class="app-card-body">
                            <h4>Filter Options</h4>
                            <form method="POST">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Faculty</label>
                                        <select name="faculty" class="form-control" required>
                                            <option value="">Select Faculty</option>
                                            <?php while ($f = $faculty_result->fetch_assoc()) { ?>
                                                <option value="<?= $f['id']; ?>"><?= htmlspecialchars($f['Name']); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Term</label>
                                        <select name="term" class="form-control" required>
                                            <option value="">Select Term</option>
                                            <?php while ($t = $term_result->fetch_assoc()) { ?>
                                                <option value="<?= $t['term']; ?>"><?= $t['term']; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Semester</label>
                                        <select name="sem" class="form-control" required>
                                            <option value="">Select Semester</option>
                                            <?php while ($s = $sem_result->fetch_assoc()) { ?>
                                                <option value="<?= $s['sem']; ?>"><?= $s['sem']; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Subject</label>
                                        <select name="subject" class="form-control" required>
                                            <option value="">Select Subject</option>
                                            <?php while ($sub = $subject_result->fetch_assoc()) { ?>
                                                <option value="<?= $sub['subjectName']; ?>"><?= htmlspecialchars($sub['subjectName']); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Class</label>
                                        <select name="class" class="form-control" required>
                                            <option value="">Select Class</option>
                                            <option value="A">A</option>
                                            <option value="B">B</option>
                                            <option value="C">C</option>
                                            <option value="D">D</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12 col-md-6">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-download me-1"></i>Generate &amp; Download Excel
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-4 mt-3 mt-lg-0">
                    <div class="app-card shadow-sm h-100" style="background:linear-gradient(135deg,#e8eaf6,#f3f4fd);">
                        <div class="app-card-body">
                            <h4>How it works</h4>
                            <ol style="font-size:0.875rem;padding-left:1.25rem;line-height:1.8;">
                                <li>Select Faculty, Term, Semester &amp; Subject</li>
                                <li>Choose the Class and date range</li>
                                <li>Click <strong>Generate &amp; Download Excel</strong></li>
                                <li>An Excel muster will download automatically</li>
                            </ol>
                            <div class="alert alert-info mb-0 mt-2" style="font-size:0.82rem;padding:0.6rem 0.9rem;">
                                <i class="bi bi-info-circle me-1"></i>Only weekdays (Mon–Fri) are included.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>
