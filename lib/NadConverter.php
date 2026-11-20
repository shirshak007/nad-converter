<?php
/**
 * NAD converter core.
 *
 * Takes the three student records exports (stumaster, stugrade, stucourses) and turns them
 * into the NAD CSV layout using one of the templates in /templates.
 *
 * Templates define two functions:
 *   mapStudentRow(array $m, array $g, array $calc): array
 *   mapCourseSlot(?array $c, int $slot): array
 */

class NadException extends RuntimeException {}

/** Formats numeric values to 2 decimal places, leaves strings untouched. */
function fmt2($val): string
{
    return is_numeric($val) ? number_format((float)$val, 2, '.', '') : (string)$val;
}

/** Columns whose values must be numbers when present. */
const NAD_NUMERIC_COLUMNS = [
    'coursesem', 'coursecredit', 'coursegradept', 'overall_marks', 'overall_total_marks',
    'paper_full_marks', 'paper_pass_marks', 'totcr', 'acctotcr', 'totmarks', 'securedmarks',
    'acctotalmarks', 'accsecuredmarks', 'sgpa', 'cgpa',
];

/** Columns that must have a value on every data row. */
const NAD_REQUIRED_VALUE_COLUMNS = ['sturegno', 'coursesem', 'coursecredit'];

/** Converts text to UTF-8 using mbstring if available, otherwise iconv. */
function nadToUtf8(string $text, string $from): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($text, 'UTF-8', $from);
    }
    $converted = iconv($from, 'UTF-8//TRANSLIT', $text);
    if ($converted === false) {
        throw new NadException("Could not convert the file from {$from} to UTF-8.");
    }
    return $converted;
}

/**
 * Reads an uploaded file as UTF-8 text. Handles UTF-8 with or without BOM,
 * UTF-16 (Excel "Unicode text"), and Windows-1252 (older Excel exports).
 * Line endings are normalised to \n.
 *
 * @throws NadException
 */
function nadReadText(string $path, string $label): string
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new NadException("Could not read the {$label} file.");
    }

    if (str_starts_with($raw, "\xFF\xFE")) {
        $raw = nadToUtf8(substr($raw, 2), 'UTF-16LE');
    } elseif (str_starts_with($raw, "\xFE\xFF")) {
        $raw = nadToUtf8(substr($raw, 2), 'UTF-16BE');
    } elseif (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    if (!preg_match('//u', $raw)) {
        $raw = nadToUtf8($raw, 'Windows-1252');
    }

    return str_replace(["\r\n", "\r"], "\n", $raw);
}

/** Picks the delimiter used in the header line: comma, semicolon or tab. */
function nadDetectDelimiter(string $text): string
{
    $firstLine = strtok($text, "\n");
    if ($firstLine === false) {
        return ',';
    }
    $counts = [
        ','  => substr_count($firstLine, ','),
        ';'  => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
    ];
    arsort($counts);
    $best = array_key_first($counts);
    return $counts[$best] > 0 ? $best : ',';
}

/**
 * Parses an uploaded CSV into rows keyed by column name.
 *
 * Robust to: BOM, UTF-16 and Windows-1252 encodings, CRLF/CR line endings,
 * comma/semicolon/tab delimiters, header case and stray spaces, blank lines,
 * empty (unnamed) columns, short or long rows, and quoted multi-line values.
 * Each row gets a '__row' key holding its data row number, used in error messages.
 *
 * @throws NadException
 */
function nadParseCsv(string $path, string $label): array
{
    $text = nadReadText($path, $label);
    $delimiter = nadDetectDelimiter($text);

    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $text);
    rewind($fh);

    $header = fgetcsv($fh, 0, $delimiter, '"', '');
    if ($header === false || $header === [null]) {
        fclose($fh);
        throw new NadException("The {$label} file is empty or has no header row.");
    }

    // Normalise header names; ignore unnamed columns
    $names = [];
    $keep  = [];
    foreach ($header as $i => $name) {
        $name = strtolower(trim((string)$name));
        if ($name === '') {
            continue;
        }
        if (in_array($name, $names, true)) {
            fclose($fh);
            throw new NadException("The {$label} file has the column '{$name}' more than once.");
        }
        $names[] = $name;
        $keep[]  = $i;
    }

    $rows = [];
    $rowNo = 0;
    while (($r = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        if ($r === [null]) {
            continue; // blank line
        }
        $rowNo++;
        $values = [];
        foreach ($keep as $pos => $i) {
            $values[$names[$pos]] = trim((string)($r[$i] ?? ''));
        }
        if (!array_filter($values, fn($v) => $v !== '')) {
            continue; // row with no data
        }
        $values['__row'] = $rowNo;
        $rows[] = $values;
    }
    fclose($fh);

    return $rows;
}

/**
 * Checks an uploaded CSV against the template: every required column must
 * exist, required values must be present, and numeric columns must hold numbers.
 * Error messages include the data row number.
 *
 * @throws NadException
 */
function nadValidateColumns(array $rows, array $required, string $label, string $templateName): void
{
    if (empty($rows)) {
        throw new NadException("The {$label} file has no data rows.");
    }

    $present = array_diff(array_keys($rows[0]), ['__row']);
    $missing = array_values(array_diff($required, $present));
    if ($missing) {
        throw new NadException("The {$label} file is missing column(s) required by {$templateName}: " . implode(', ', $missing) . '.');
    }

    foreach ($rows as $row) {
        $where = "row {$row['__row']} of the {$label} file";

        foreach (NAD_REQUIRED_VALUE_COLUMNS as $col) {
            if (in_array($col, $required, true) && ($row[$col] ?? '') === '') {
                throw new NadException("{$where}: '{$col}' is empty.");
            }
        }

        foreach (NAD_NUMERIC_COLUMNS as $col) {
            $value = $row[$col] ?? '';
            if (isset($row[$col]) && $value !== '' && !is_numeric($value)) {
                throw new NadException("{$where}: '{$col}' must be a number, found '{$value}'.");
            }
        }
    }
}

/**
 * Loads a template file and returns the columns it requires.
 * A template must define mapStudentRow(), mapCourseSlot() and nadRequiredColumns().
 *
 * @return array{stumaster: string[], stugrade: string[], stucourses: string[]}
 * @throws NadException
 */
function nadLoadTemplate(string $templatePath): array
{
    if (!is_file($templatePath)) {
        throw new NadException('Template not found.');
    }
    require_once $templatePath;

    foreach (['mapStudentRow', 'mapCourseSlot', 'nadRequiredColumns'] as $fn) {
        if (!function_exists($fn)) {
            throw new NadException('Template ' . basename($templatePath) . " is missing the {$fn}() function.");
        }
    }

    $required = nadRequiredColumns();
    foreach (['stumaster', 'stugrade', 'stucourses'] as $key) {
        if (!isset($required[$key]) || !is_array($required[$key])) {
            throw new NadException('Template ' . basename($templatePath) . " does not list required columns for {$key}.");
        }
    }
    return $required;
}

/** Lists template files (*.php) available in the templates folder. */
function nadListTemplates(string $templatesDir): array
{
    $files = glob(rtrim($templatesDir, '/\\') . '/*.php') ?: [];
    $names = array_map('basename', $files);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

/**
 * Runs the conversion. The template must already be loaded with nadLoadTemplate().
 *
 * @param array    $inputs   ['stumaster' => rows, 'stugrade' => rows, 'stucourses' => rows]
 * @param int|null $sem      semester to export, or null for all semesters
 * @param string   $issueDate dd-mm-yyyy, or '' if not given
 *
 * @return array{headers: string[], rows: array<int, array>, semLabel: string}
 * @throws NadException
 */
function nadConvert(array $inputs, ?int $sem, string $issueDate = ''): array
{
    $masterRows = $inputs['stumaster'];
    $gradeRows  = $inputs['stugrade'];
    $courseRows = $inputs['stucourses'];

    // Index students and courses by registration number
    $master = [];
    foreach ($masterRows as $row) {
        $master[trim($row['sturegno'])] = $row;
    }

    $courses = [];
    foreach ($courseRows as $row) {
        $courses[trim($row['sturegno'])][] = $row;
    }

    $degreeShort = [
        'Master of Science'   => 'M.Sc.',
        'Master of Arts'      => 'M.A.',
        'Bachelor of Science' => 'B.Sc.',
        'Bachelor of Arts'    => 'B.A.',
    ];
    $romanSem = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];

    // Build one entry per student per semester
    $out = [];
    foreach ($gradeRows as $g) {
        $regno = trim($g['sturegno']);
        $s = (int)$g['coursesem'];

        if ($sem !== null && $s !== $sem) continue;
        if (!isset($master[$regno])) continue;

        $m = $master[$regno];
        $semCourses  = array_values(array_filter($courses[$regno] ?? [], fn($c) => (int)$c['coursesem'] === $s));
        $upToCourses = array_filter($courses[$regno] ?? [], fn($c) => (int)$c['coursesem'] <= $s);

        if (empty($semCourses)) continue;

        // Credit points and grade points for this semester (starred courses are non-graded)
        $crPoints = 0.0;
        $grPoints = 0.0;
        foreach ($semCourses as $c) {
            if (strpos($c['coursecode'], '*') === false) {
                $crPoints += (float)$c['coursegradept'] * (float)$c['coursecredit'];
                $grPoints += (float)$c['coursegradept'];
            }
        }

        // Cumulative credit points up to this semester
        $grandCrPoints = 0.0;
        foreach ($upToCourses as $c) {
            if (strpos($c['coursecode'], '*') === false) {
                $grandCrPoints += (float)$c['coursegradept'] * (float)$c['coursecredit'];
            }
        }

        // courseend looks like "June 2026": last word is the year, the rest is the month
        $dateParts = preg_split('/\s+/', trim($g['courseend'] ?? ''));
        $calc = [
            'degree_short'        => $degreeShort[$m['studegree']] ?? '',
            'year'                => array_pop($dateParts),
            'month'               => implode(' ', $dateParts),
            'sem_roman'           => $romanSem[$s] ?? (string)$s,
            'credit_points'       => $crPoints,
            'grade_points'        => $grPoints,
            'grand_credit_points' => $grandCrPoints,
            'doi'                 => $issueDate,
        ];

        $out[] = [
            'base'    => mapStudentRow($m, $g, $calc),
            'courses' => $semCourses,
            'sem_no'  => $s,
            'calc'    => $calc,
        ];
    }

    if (empty($out)) {
        $label = $sem === null ? 'any semester' : "semester {$sem}";
        throw new NadException("No matching records found for {$label}. Check that the files belong to the same students and semester.");
    }

    // Add course slots (SUB1, SUB2, ...) up to the largest number of courses in any row
    $maxCourses = max(array_map(fn($item) => count($item['courses']), $out));
    $finalRows = [];
    foreach ($out as $item) {
        $row = $item['base'];
        for ($i = 1; $i <= $maxCourses; $i++) {
            $c = $item['courses'][$i - 1] ?? null;
            $row += mapCourseSlot($c, $i);
        }
        // Optional: columns that come after all course blocks (only some templates use this)
        if (function_exists('mapTrailingColumns')) {
            $row += mapTrailingColumns($item['calc']);
        }
        $finalRows[] = $row;
    }

    if ($sem !== null) {
        $semLabel = (string)$sem;
    } else {
        $uniqueSems = array_values(array_unique(array_map(fn($item) => (int)$item['sem_no'], $out)));
        sort($uniqueSems);
        $semLabel = implode(',', $uniqueSems);
    }

    return [
        'headers'  => array_keys($finalRows[0]),
        'rows'     => $finalRows,
        'semLabel' => $semLabel,
    ];
}
