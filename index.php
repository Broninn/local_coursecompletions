<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->libdir . '/phpspreadsheet/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$courseid = required_param('id', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = get_config('moodle', 'userlistperpage') ?: 20;
$download = optional_param('download', '', PARAM_ALPHA); // '' ou 'csv'

require_login($courseid);
$context = context_course::instance($courseid);
require_capability('local/coursecompletions:view', $context);

$PAGE->set_url(new moodle_url('/local/coursecompletions/index.php', ['id' => $courseid, 'groupid' => $groupid]));
$PAGE->set_context($context);
$PAGE->set_title('Relatório de Conclusão de Curso');
$PAGE->set_heading('Relatório de Conclusão de Curso');

$groupmenu = groups_get_all_groups($courseid);
$groupoptions = [0 => 'Todos os participantes'] + array_column($groupmenu, 'name', 'id');

// SQL dinâmico com contagem para paginação
$groupfilter = '';
// Pega total de critérios de conclusão desse curso
$totalcriteria = $DB->count_records('course_completion_criteria', ['course' => $courseid]);
$params = [
    'courseid' => $courseid,
    // 'courseid2' => $courseid,
    'courseid3' => $courseid,
    'courseid4' => $courseid,
    'courseid5' => $courseid,
    'courseid6' => $courseid,
    'groupidcourse' => $courseid,
    'groupid' => $groupid,
    'dedicationcourseid' => $courseid, // novo alias
];

$params['totalcriteria'] = $totalcriteria;

// Mesmo que $groupid seja 0, ainda devemos preencher para evitar erro.
$params['groupid'] = $groupid;

if ($groupid > 0) {
    $groupfilter = "INNER JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid";
    $params['groupid'] = $groupid;
}

$countsql = "
    SELECT COUNT(DISTINCT u.id)
    FROM {user} u
    INNER JOIN {user_enrolments} ue ON ue.userid = u.id
    INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
    INNER JOIN {role_assignments} ra ON ra.userid = u.id AND ra.roleid = 5
    INNER JOIN {context} ct ON ct.contextlevel = 50 AND ct.instanceid = e.courseid AND ct.id = ra.contextid
    $groupfilter
";
$totalusers = $DB->count_records_sql($countsql, $params);

$datasql = "
    SELECT 
        u.id,
        CONCAT(u.firstname, ' ', u.lastname) AS fullname,
        COALESCE((
            SELECT STRING_AGG(g.name, ', ')
            FROM {groups} g
            JOIN {groups_members} gm ON gm.groupid = g.id
            WHERE gm.userid = u.id AND g.courseid = :groupidcourse
        ), 'Sem grupo') AS groupname,
        CASE
        WHEN ue.timestart = 0 THEN 'Aluno egresso'
        ELSE TO_CHAR (
            TO_TIMESTAMP (ue.timestart) AT TIME ZONE 'UTC' AT TIME ZONE INTERVAL '+03:00',
            'DD/MM/YYYY HH24:MI:SS'
        )
    END AS data_inicio_turma_disciplina,
    CASE
        WHEN ue.timeend = 0 THEN 'Aluno egresso'
        ELSE TO_CHAR (
            TO_TIMESTAMP (ue.timeend) AT TIME ZONE 'UTC' AT TIME ZONE INTERVAL '+03:00',
            'DD/MM/YYYY HH24:MI:SS'
        )
    END AS data_fim_turma_disciplina,

        CASE 
            WHEN COUNT(DISTINCT ccc.criteriaid) = :totalcriteria THEN 'Concluído'
            ELSE 'Possível evasão'
        END AS status,

        CASE
        WHEN SUM(bd.total) IS NULL THEN 'Nunca acessou'
        ELSE TRIM(
            BOTH
            FROM
                CONCAT (
                    CASE
                        WHEN EXTRACT(
                            hour
                            FROM
                                make_interval (secs => SUM(bd.total))
                        ) > 0 THEN EXTRACT(
                            hour
                            FROM
                                make_interval (secs => SUM(bd.total))
                        ) || 'h '
                        ELSE ''
                    END,
                    EXTRACT(
                        minute
                        FROM
                            make_interval (secs => SUM(bd.total))
                    ) || 'min'
                )
        )
    END AS tempo_formatado,
    
    ROUND(
        100.0 * (
            (
                SELECT
                    COUNT(*)
                FROM
                    mdl_course_completion_criteria
                WHERE
                    course = :courseid3
            ) - COUNT(DISTINCT ccc.criteriaid)
        ) / NULLIF(
            (
                SELECT
                    COUNT(*)
                FROM
                    mdl_course_completion_criteria
                WHERE
                    course = :courseid4
            ),
            0
        )
    ,1) || '%' AS porcentagem_pendente,
    CASE
        WHEN TO_TIMESTAMP (ue.timestart) > NOW () THEN 'A iniciar'
        --WHEN COUNT(DISTINCT ccc.criteriaid) = 0 THEN 'Possível evasão'
        WHEN COUNT(DISTINCT ccc.criteriaid) < (
            SELECT
                COUNT(*)
            FROM
                mdl_course_completion_criteria
            WHERE
                course = :courseid5
        )
        AND ue.timeend > 0
        AND TO_TIMESTAMP (ue.timeend) > NOW () THEN 'Em andamento'
        WHEN COUNT(DISTINCT ccc.criteriaid) < (
            SELECT
                COUNT(*)
            FROM
                mdl_course_completion_criteria
            WHERE
                course = :courseid6
        ) THEN 'Possível evasão' -- aluno com critérios incompletos, mas data final já passou
        ELSE 'Concluído'
    END AS status

    FROM {user} u
    INNER JOIN {user_enrolments} ue ON ue.userid = u.id
    INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
    INNER JOIN {role_assignments} ra ON ra.userid = u.id AND ra.roleid = 5
    INNER JOIN {context} ct ON ct.contextlevel = 50 AND ct.instanceid = e.courseid AND ct.id = ra.contextid
    LEFT JOIN {course_completion_crit_compl} ccc ON ccc.userid = u.id AND ccc.course = e.courseid
    LEFT JOIN (
        SELECT userid, courseid, SUM(timespent) AS total
        FROM {block_dedication}
        WHERE courseid = :dedicationcourseid
        GROUP BY userid, courseid
    ) bd ON bd.userid = u.id AND bd.courseid = e.courseid
    $groupfilter
    GROUP BY u.id, u.firstname, u.lastname, bd.total, ue.timestart, ue.timeend
    ORDER BY fullname
";


if (!$download) {
    $datasql .= " LIMIT :limit OFFSET :offset";
    $params['limit'] = $perpage;
    $params['offset'] = $page * $perpage;
}

$data = $DB->get_records_sql($datasql, $params);

if ($download === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_conclusao_sala_' . $courseid . '.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['Nome Completo', 'Grupo(s)', 'Data Início Turma Disciplina', 'Data Fim Turma Disciplina', 'Tempo de Acesso', 'Atividades Pendentes', 'Status'], ';');

    // Dados
    foreach ($data as $row) {
        fputcsv($out, [
            $row->fullname,
            $row->groupname,
            $row->data_inicio_turma_disciplina,
            $row->data_fim_turma_disciplina,
            $row->tempo_formatado,
            $row->porcentagem_pendente,
            $row->status

        ], ';');
    }


    fclose($out);
    exit;
}

if ($download === 'xlsx') {

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Cabeçalhos
    $sheet->fromArray(['Nome Completo', 'Grupo(s)', 'Data Início Turma Disciplina', 'Data Fim Turma Disciplina', 'Tempo de Acesso', 'Atividades Pendentes', 'Status'], NULL, 'A1');

    // Dados
    $rownum = 2;
    foreach ($data as $row) {
        $sheet->fromArray([
            $row->fullname,
            $row->groupname,
            $row->data_inicio_turma_disciplina,
            $row->data_fim_turma_disciplina,
            $row->tempo_formatado,
            $row->porcentagem_pendente,
            $row->status

        ], NULL, "A$rownum");
        $rownum++;
    }

    // Cabeçalhos HTTP
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="relatorio_conclusao_sala_' . $courseid . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}


// Renderização HTML
echo $OUTPUT->header();
echo $OUTPUT->heading('Relatório de Conclusão de Curso');

// Filtro de Grupo
echo html_writer::start_tag('form', ['method' => 'get']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::label('Grupo: ', 'groupid', ['style' => 'margin-bottom: 0.75rem;']);
echo html_writer::select($groupoptions, 'groupid', $groupid, false, ['style' => 'margin-left: 0.75rem;', 'margin-bottom: 0.75rem;']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Filtrar', 'class' => 'btn btn-primary', 'style' => 'margin-left: 0.75rem;', 'margin-bottom: 0.75rem;']);
echo html_writer::end_tag('form');

// Tabela
$table = new html_table();
$table->head = ['Nome Completo', 'Grupo(s)', 'Data Início Turma Disciplina', 'Data Fim Turma Disciplina', 'Tempo de Acesso', 'Atividades Pendentes', 'Status'];
foreach ($data as $row) {
    $table->data[] = [
        $row->fullname,
        $row->groupname,
        $row->data_inicio_turma_disciplina,
        $row->data_fim_turma_disciplina,
        $row->tempo_formatado,
        $row->porcentagem_pendente,
        $row->status

    ];
}
echo html_writer::table($table);

// Botão CSV
// $csvurl = new moodle_url('/local/coursecompletions/index.php', [
//     'id' => $courseid,
//     'groupid' => $groupid,
//     'download' => 'csv'
// ]);
// echo html_writer::link(
//     $csvurl,
//     'Exportar CSV',
//     [
//         'class' => 'btn btn-primary',  // btn-primary, btn-secondary, btn-success etc.
//         'style' => 'margin-top:0.75rem; margin-bottom: 0.75rem'
//     ]
// );

// Botão XLSX
$xlsxurl = new moodle_url('/local/coursecompletions/index.php', [
    'id' => $courseid,
    'groupid' => $groupid,
    'download' => 'xlsx'
]);
echo html_writer::link(
    $xlsxurl,
    'Exportar XLSX',
    [
        'class' => 'btn btn-primary',
        'style' => 'margin-top:0.75rem; margin-bottom: 0.75rem'

    ]
);

// Paginação
$baseurl = new moodle_url('/local/coursecompletions/index.php', ['id' => $courseid, 'groupid' => $groupid]);
echo $OUTPUT->paging_bar($totalusers, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
