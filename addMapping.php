<?php
include('dbconfig.php');

$tab_pages = [
    'lecture' => 'addLectureMapping.php',
    'lab' => 'addLabMapping.php',
    'tutorial' => 'addTutMapping.php',
];

$active_tab = (string)($_GET['tab'] ?? 'lecture');
if (!array_key_exists($active_tab, $tab_pages)) {
    $active_tab = 'lecture';
}

$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$iframe_src = $tab_pages[$active_tab] . '?embedded=1';
if ($edit_id > 0) {
    $iframe_src .= '&edit_id=' . $edit_id;
}

$open_page_src = $tab_pages[$active_tab];
if ($edit_id > 0) {
    $open_page_src .= '?edit_id=' . $edit_id;
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
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h1 class="app-page-title mb-0"><i class="bi bi-diagram-3 me-2"></i>Add Mapping</h1>
                <a href="<?= htmlspecialchars($open_page_src) ?>" class="btn btn-outline-secondary btn-sm">Open Active Tab Full Page</a>
            </div>

            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'lecture' ? 'active' : '' ?>" href="addMapping.php?tab=lecture">Lecture Mapping</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'lab' ? 'active' : '' ?>" href="addMapping.php?tab=lab">Lab Mapping</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'tutorial' ? 'active' : '' ?>" href="addMapping.php?tab=tutorial">Tutorial Mapping</a>
                </li>
            </ul>

            <div class="app-card shadow-sm">
                <div class="app-card-body p-0">
                    <iframe
                        id="mapping-frame"
                        src="<?= htmlspecialchars($iframe_src) ?>"
                        style="width:100%; border:0; min-height:1450px;"
                        loading="lazy"
                        title="Mapping Editor"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const frame = document.getElementById('mapping-frame');

    function resizeFrame() {
        try {
            if (!frame || !frame.contentWindow || !frame.contentWindow.document) {
                return;
            }
            const doc = frame.contentWindow.document;
            const bodyHeight = doc.body ? doc.body.scrollHeight : 0;
            const htmlHeight = doc.documentElement ? doc.documentElement.scrollHeight : 0;
            const newHeight = Math.max(bodyHeight, htmlHeight, 900);
            frame.style.height = newHeight + 'px';
        } catch (e) {
            // Ignore cross-document resize issues.
        }
    }

    frame.addEventListener('load', resizeFrame);
    window.addEventListener('resize', resizeFrame);
</script>

<?php include('footer.php'); ?>
</body>
</html>
<?php $conn->close(); ?>
