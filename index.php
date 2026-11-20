<?php
/**
 * NAD CSV Converter - single page app.
 *
 * Upload stumaster.csv, stugrade.csv and stucourses.csv, pick a template and
 * semester, and download the NAD CSV. Nothing is written to disk: files are
 * read in memory and the output is streamed straight to the browser.
 */

require_once __DIR__ . '/lib/NadConverter.php';

const TEMPLATES_DIR = __DIR__ . '/templates';


$error   = null;
$old     = ['template' => '', 'sem' => 'all', 'issueDate' => ''];
$templates = nadListTemplates(TEMPLATES_DIR);

if (empty($templates)) {
    $error = 'No templates found in the templates/ folder. Add at least one template .php file.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    $old['template']  = trim($_POST['template'] ?? '');
    $old['sem']       = trim($_POST['sem'] ?? 'all');
    $old['issueDate'] = trim($_POST['issueDate'] ?? '');

    try {
        // 1. Template: must be one of the files in templates/, and must
        //    define the functions and the required columns it needs
        if (!in_array($old['template'], $templates, true)) {
            throw new NadException('Please choose a valid template.');
        }
        $required = nadLoadTemplate(TEMPLATES_DIR . '/' . $old['template']);

        // 2. Semester: 'all' or a number 1-6
        if ($old['sem'] === 'all' || $old['sem'] === '') {
            $sem = null;
        } elseif (ctype_digit($old['sem']) && (int)$old['sem'] > 0) {
            $sem = (int)$old['sem'];
        } else {
            throw new NadException('Please choose a valid semester.');
        }

        // 3. Issue date: optional, but must be dd-mm-yyyy if given
        if ($old['issueDate'] !== '') {
            $d = DateTime::createFromFormat('d-m-Y', $old['issueDate']);
            if (!$d || $d->format('d-m-Y') !== $old['issueDate']) {
                throw new NadException('Issue date must be in dd-mm-yyyy format.');
            }
        }

        // 4. The three uploaded CSVs
        $inputs = ['stumaster' => 'Student master', 'stugrade' => 'Student grade', 'stucourses' => 'Student courses'];
        $parsed = [];
        foreach ($inputs as $field => $label) {
            $f = $_FILES[$field] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                throw new NadException("Please upload the {$label} CSV ({$field}.csv).");
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new NadException("Upload failed for {$label} CSV (PHP upload error {$f['error']}). Check upload_max_filesize in php.ini.");
            }
            if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new NadException("{$label} file must be a .csv file.");
            }
            $parsed[$field] = nadParseCsv($f['tmp_name'], $label);
            nadValidateColumns($parsed[$field], $required[$field], $label, $old['template']);
        }

        // 5. Convert
        $result = nadConvert($parsed, $sem, $old['issueDate']);

        // 6. Stream CSV download
        $fileName = 'output_sem_' . $result['semLabel'] . '_' . date('YmdHis') . '.csv';
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $out = fopen('php://output', 'w');
        fputcsv($out, $result['headers']);
        foreach ($result['rows'] as $row) {
            fputcsv($out, array_values($row));
        }
        fclose($out);
        exit;

    } catch (NadException $e) {
        $error = $e->getMessage();
    }
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function templateLabel(string $file): string
{
    return preg_replace('/\.php$/', '', $file);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NAD CSV Converter</title>
<style>
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f4f6f8; color: #222; }
    main { max-width: 680px; margin: 40px auto; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 28px 32px; }
    h1 { font-size: 1.4rem; margin: 0 0 4px; }
    p.sub { margin: 0 0 24px; color: #666; font-size: .9rem; }
    label { display: block; font-weight: 600; font-size: .9rem; margin: 16px 0 6px; }
    input[type=text], input[type=file], select { width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1px solid #ccc; border-radius: 5px; font-size: .95rem; background: #fff; }
    .hint { font-size: .8rem; color: #777; margin-top: 4px; }
    .row { display: flex; gap: 16px; }
    .row > div { flex: 1; }
    button { margin-top: 24px; background: #2563eb; color: #fff; border: 0; padding: 11px 20px; border-radius: 5px; font-size: 1rem; cursor: pointer; }
    button:hover { background: #1d4ed8; }
    .error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 10px 12px; border-radius: 5px; margin-bottom: 16px; font-size: .9rem; }
    fieldset { border: 0; padding: 0; margin: 0; }
    legend { font-weight: 700; margin-bottom: 4px; }
</style>
</head>
<body>
<main>
    <h1>NAD CSV Converter</h1>
    <p class="sub">Upload the three student records exports, choose a template, and download the NAD CSV.</p>

    <?php if ($error): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="">
        <fieldset>
            <legend>Input files</legend>

            <label for="stumaster">Student master (stumaster.csv)</label>
            <input type="file" id="stumaster" name="stumaster" accept=".csv" required>

            <label for="stugrade">Student grades (stugrade.csv)</label>
            <input type="file" id="stugrade" name="stugrade" accept=".csv" required>

            <label for="stucourses">Student courses (stucourses.csv)</label>
            <input type="file" id="stucourses" name="stucourses" accept=".csv" required>
        </fieldset>

        <fieldset>
            <legend>Export options</legend>
            <div class="row">
                <div>
                    <label for="template">Template</label>
                    <select id="template" name="template" required>
                        <option value="">Choose a template</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= h($t) ?>" <?= $old['template'] === $t ? 'selected' : '' ?>><?= h(templateLabel($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="sem">Semester</label>
                    <select id="sem" name="sem">
                        <option value="all" <?= $old['sem'] === 'all' ? 'selected' : '' ?>>All semesters</option>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <option value="<?= $i ?>" <?= $old['sem'] === (string)$i ? 'selected' : '' ?>>Semester <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <label for="issueDate">Issue date</label>
            <input type="text" id="issueDate" name="issueDate" placeholder="dd-mm-yyyy" value="<?= h($old['issueDate']) ?>">
            <div class="hint">Optional. Used for the DOI column by templates that need it.</div>
        </fieldset>

        <button type="submit">Download NAD CSV</button>
    </form>
</main>
</body>
</html>
