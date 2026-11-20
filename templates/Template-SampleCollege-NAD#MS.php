<?php
// mapping.php

/**
 * Maps student data to destination columns.
 * To add a column, just add an entry to the array!
 * Params available: $m (stumaster), $g (stugrade), $x (computed), $s (settings)
 */
function mapStudentRow(array $m, array $g, array $calc): array
{
    return [
        'ORG_NAME'                => 'SAMPLE COLLEGE (AUTONOMOUS)',
        'ACADEMIC_COURSE_ID'      => $calc['degree_short'],
        'COURSE_NAME'             => $m['studegree'] ?? '',
        'STREAM'                  => $m['stuprogram'] ?? '',
        'SESSION'                 => $g['session'] ?? '',
        'REGN_NO'                 => $m['sturegno'] ?? '',
        'RROLL'                   => '',
        'CNAME'                   => $m['stuname'] ?? '',
        'GENDER'                  => $m['gender'] ?? '',
        'DOB'                     => $m['studob'] ?? '',
        'FNAME'                   => trim($m['stufather'] ?? ''),
        'MNAME'                   => $m['stumother'] ?? '',
        'AADHAAR_NAME'            => $m['stuname'] ?? '',
        'ADMISSION_YEAR'          => $m['stuyearofjoin'] ?? '',
        'NCRF_LEVEL'              => '',
        'EXAM_CENTRE'             => '',
        'PHOTO'                   => '',
        'MRKS_REC_STATUS'         => 'O',
        'RESULT'                  => $g['result'] ?? '',
        'YEAR'                    => $calc['year'],
        'MONTH'                   => $calc['month'],
        'DIVISION'                => '',
        'GRADE'                   => $g['sem_grade'] ?? '',
        'PERCENT'                 => $g['percentage'] ?? '',
        'DOI'                     => '',
        'SEM'                     => $calc['sem_roman'],
        'TOT'                     => $g['totmarks'] ?? '',
        'TOT_MRKS'                => $g['securedmarks'] ?? '',
        'TOT_CREDIT'              => fmt2($g['totcr'] ?? 0),
        'TOT_CREDIT_POINTS'       => fmt2($calc['credit_points']),
        'TOT_GRADE_POINTS'        => $calc['grade_points'],
        'GRAND_TOT_MAX'           => $g['acctotalmarks'] ?? '',
        'GRAND_TOT_MRKS'          => $g['accsecuredmarks'] ?? '',
        'GRAND_TOT_CREDIT_POINTS' => fmt2($calc['grand_credit_points']),
        'GRAND_TOT_CREDIT'        => fmt2($g['acctotcr'] ?? 0),
        'CGPA'                    => fmt2($g['cgpa'] ?? 0),
        'REMARKS'                 => '',
        'GPA'                    => fmt2($g['sgpa'] ?? 0),
        'ABC_ACCOUNT_ID'          => $m['abcid'] ?? '',
        'TERM_TYPE'               => '',
        'TOT_GRADE'               => '',
        'GRAND_TOT_GRADE'         => '',
    ];
}

/**
 * Maps each dynamic course slot (SUB1, SUB2, etc.)
 */
function mapCourseSlot(?array $c, int $slot): array
{
    $isGraded = $c && strpos($c['coursecode'], '*') === false;
    $creditPts = '';
    if ($c) {
        $creditPts = $isGraded ? fmt2((float)$c['coursegradept'] * (float)$c['coursecredit']) : 'Non-graded';
    }

    return [
        "SUB{$slot}NM"            => $c['coursenameorig'] ?? '',
        "SUB{$slot}"              => $c['coursecode'] ?? '',
        "SUB{$slot}_TOT"          => $c['overall_total_marks'] ?? '', //total, not percentage
        "SUB{$slot}_GRADE"        => $c['coursegrade'] ?? '',
        "SUB{$slot}_GRADE_POINTS" => $c['coursegradept'] ?? '',
        "SUB{$slot}_CREDIT"       => $c['coursecredit'] ?? '',
        "SUB{$slot}_CREDIT_POINTS"=> $creditPts,
        // extra
        "SUB{$slot}_STATUS"      => $c['result'] ?? '',
        "SUB{$slot}MIN"      => $c['paper_pass_marks'] ?? '',
        "SUB{$slot}MAX"      => $c['paper_full_marks'] ?? '',
    ];
}

/**
 * Columns this template needs from each uploaded CSV.
 * The page rejects an upload if any of these are missing.
 */
function nadRequiredColumns(): array
{
    return [
        'stumaster'  => ['sturegno', 'stuname', 'studegree', 'stuprogram', 'gender', 'studob', 'stufather', 'stumother', 'stuyearofjoin', 'abcid'],
        'stugrade'   => ['sturegno', 'coursesem', 'courseend', 'session', 'result', 'sem_grade', 'percentage', 'totmarks', 'securedmarks', 'totcr', 'acctotalmarks', 'accsecuredmarks', 'acctotcr', 'cgpa', 'sgpa'],
        'stucourses' => ['sturegno', 'coursesem', 'coursecode', 'coursenameorig', 'coursecredit', 'coursegrade', 'coursegradept', 'overall_total_marks', 'paper_full_marks', 'paper_pass_marks', 'result'],
    ];
}
