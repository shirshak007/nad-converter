# NAD CSV Converter

Converts student records exports (student master, grades, courses) into the NAD CSV format.
It is a single PHP page. No database, no framework, no install step beyond PHP.

## Requirements

- PHP 8.0 or newer (tested on 8.3). No Composer packages.
- The `mbstring` extension is optional. Without it the app uses `iconv`.

## Run it

```bash
git clone <this-repo> nad-converter
cd nad-converter
php -S localhost:8000
```

Open http://localhost:8000 in your browser.

## Use it

1. Upload `stumaster.csv`, `stugrade.csv` and `stucourses.csv`.
2. Choose a template. The list comes from the `templates/` folder.
3. Choose a semester, or "All semesters".
4. Optionally enter the issue date as dd-mm-yyyy.
5. Click **Download NAD CSV**.

Uploaded files are read in memory and never saved to disk. The output is streamed straight to your browser.

## Input files

Each file can use a comma, semicolon or tab delimiter. It can be UTF-8 (with or without BOM), UTF-16 or
Windows-1252, and can have Windows or old Mac line endings. Header names are not case-sensitive and extra
spaces are ignored. Unnamed empty columns and blank lines are skipped.

Every template needs its own set of columns. The page checks the uploaded files against the chosen template
and reports the first problem it finds, with the row number. It rejects:

- missing required columns (the column names are listed)
- an empty `sturegno`, `coursesem` or `coursecredit`
- a numeric column with text in it (for example `coursecredit` = `four`)
- a column name that appears twice

### Required columns per template

The sample files in `sample/` contain every column that any template in `templates/` uses, so all three templates
run on them. To use the sample for a single template, remove the columns that template does not need. Use the
tables below to check which ones those are.

**Template-SampleCollege-NAD#MS.php (college) and nad-official-sample-template.php (university)**

| File | Required columns |
|---|---|
| `stumaster.csv` | `sturegno`, `stuname`, `studegree`, `stuprogram`, `gender`, `studob`, `stufather`, `stumother`, `stuyearofjoin`, `abcid` |
| `stugrade.csv` | `sturegno`, `coursesem`, `courseend`, `session`, `result`, `sem_grade`, `percentage`, `totmarks`, `securedmarks`, `totcr`, `acctotalmarks`, `accsecuredmarks`, `acctotcr`, `cgpa`, `sgpa` |
| `stucourses.csv` | `sturegno`, `coursesem`, `coursecode`, `coursenameorig`, `coursecredit`, `coursegrade`, `coursegradept`, `overall_total_marks`, `paper_full_marks`, `paper_pass_marks`, `result` |

The college and university templates differ only in the course name column: the college template uses `coursenameorig`,
and the university template uses `coursename`. The sample includes both.

**Template-CampusA-NAD#DGMST-2.php**

| File | Required columns |
|---|---|
| `stumaster.csv` | `sturegno`, `stuname`, `studegree`, `stuprogram`, `stucampus`, `studep_alt`, `stualtschool`, `stufather`, `stumother`, `gender`, `studob`, `stuyearofjoin`, `abcid` |
| `stugrade.csv` | `sturegno`, `coursesem`, `courseend`, `session`, `result`, `sem_grade`, `percentage`, `totmarks`, `securedmarks`, `totcr`, `acctotalmarks`, `accsecuredmarks`, `acctotcr`, `cgpa`, `sgpa` |
| `stucourses.csv` | `sturegno`, `coursesem`, `coursecode`, `coursename`, `coursecredit`, `coursegrade`, `coursegradept`, `overall_marks`, `result` |

The Campus A template needs `stucampus`, `studep_alt` and `stualtschool` in the master file, and `overall_marks`
in the courses file. These are included in the sample. The Campus A template uses `stucampus` to choose the
organisation name: `Campus A` gives `studep_alt`, and any other value gives `stualtschool`.

### Optional columns

Templates can also use columns that are not required. The template reads them only if they are in the file,
and leaves the output field blank if they are not. Remove any you do not need.

| File | Column | Used by | Output field |
|---|---|---|---|
| `stumaster.csv` | `studep` | nad-official-sample-template | `DEPARTMENT` |
| `stumaster.csv` | `project_title` | nad-official-sample-template | `THESIS` |

The official template has many other output fields (addresses, blood group, mobile, marks breakdown and so on)
that the input files do not provide. They are left blank.

## Sample data

`sample/` has the three input files for two students. The personal details are masked (names, registration
numbers, parents, date of birth, ABC ID). The grades and course data are unchanged. Upload them to see the
expected output.

## Folder layout

```
index.php            the page: upload form, validation, CSV download
lib/NadConverter.php the conversion logic (no web code)
templates/           NAD templates; each .php file here shows up in the dropdown
sample/              masked two-student input files for testing
```

## Adding or changing a template

Copy an existing file in `templates/` and rename it. A template must define three functions:

- `mapStudentRow(array $m, array $g, array $calc): array` returns the student-level NAD columns.
  `$m` is a row from the master file, `$g` is a row from the grades file, and `$calc` holds the computed values
  (`degree_short`, `year`, `month`, `sem_roman`, `credit_points`, `grade_points`,
  `grand_credit_points`, `doi`).
- `mapCourseSlot(?array $c, int $slot): array` returns the columns for one course, named `SUB1`, `SUB2`, and so on.
  `$c` is a row from the courses file, or `null` if the student has fewer courses than the largest count.
- `nadRequiredColumns(): array` lists the columns the template reads from each file, under the keys
  `stumaster`, `stugrade` and `stucourses`. Only list columns the template actually uses. The page checks
  uploads against this list.

To add a column, add an entry to the array. The file appears in the dropdown automatically.

A template can also define `mapTrailingColumns(array $calc): array` for columns that must come after all
course blocks. `nad-official-sample-template.php` uses this for `NON_CREDIT_HOURS`.

### nad-official-sample-template

This template produces the full 211-column official NAD marksheet layout (107 student columns, a 103-column
block for each course, then `NON_CREDIT_HOURS`). Columns the input files do not provide are left blank.
Set `ORG_NAME` inside `mapStudentRow()` to your institution before using it in production.

Templates are PHP and run on the server. Only put files you trust in `templates/`.

## Notes

- The "Re-order" option from the original version is not included. It depended on a template-specific
  `reorder.php` script.
