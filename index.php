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
$statusfilter = optional_param('status', '', PARAM_TEXT);

require_login($courseid);
$context = context_course::instance($courseid);

$roles = get_user_roles($context, $USER->id);
$iseditingteacher = false;
$isteacher = false;

foreach ($roles as $role) {
    if ($role->shortname === 'editingteacher') {
        $iseditingteacher = true;
    } else if ($role->shortname === 'teacher') {
        $isteacher = true;
    }
}

require_capability('local/coursecompletions:view', $context);

$PAGE->set_url(new moodle_url('/local/coursecompletions/index.php', ['id' => $courseid, 'groupid' => $groupid]));
$PAGE->set_context($context);
$PAGE->set_title('Relatório de Conclusão de Curso');
$PAGE->set_heading('Relatório de Conclusão de Curso');

// $groupmenu = groups_get_all_groups($courseid);

if ($iseditingteacher) {
    // Pode ver todos os grupos
    $groupmenu = groups_get_all_groups($courseid);
} else if ($isteacher) {
    // Pode ver somente os grupos que participa
    $groupmenu = groups_get_all_groups($courseid, $USER->id);
} else {
    // Gerentes ou outros com permissão total
    $groupmenu = groups_get_all_groups($courseid);
}

$grupoacessonegado = false;
if ($isteacher && $groupid > 0 && !array_key_exists($groupid, $groupmenu)) {
    $grupoacessonegado = true;
    // força groupid para 0 para evitar SQL inválido
    $groupid = 0;
}



$groupoptions = [0 => 'Todos os participantes'] + array_column($groupmenu, 'name', 'id');

$groupoptions = [0 => 'Todos os participantes'] + array_column($groupmenu, 'name', 'id');

// SQL dinâmico com contagem para paginação
$groupfilter = '';

// Pega total de critérios de conclusão desse curso
$totalcriteria = $DB->count_records('course_completion_criteria', ['course' => $courseid]);
$params = [
    'courseid' => $courseid,
    'courseid2' => $courseid,
    'courseid3' => $courseid,
    'courseid4' => $courseid,
    'courseid5' => $courseid,
    'courseid6' => $courseid,
    'courseid7' => $courseid,
    'groupidcourse' => $courseid,
    'groupid' => $groupid,
    'dedicationcourseid' => $courseid, // novo alias
];

$params['totalcriteria'] = $totalcriteria;

// Mesmo que $groupid seja 0, ainda deve ser preenchido para evitar erro.
$params['groupid'] = $groupid;
if (!empty($statusfilter)) {
    $params['statusfilter'] = $statusfilter;
}

$teacher_groupids = [];
if ($isteacher) {
    $usergroups = groups_get_all_groups($courseid, $USER->id);
    if (!empty($usergroups)) {
        $teacher_groupids = array_keys($usergroups);
    }
}

if ($isteacher) {
    if ($groupid > 0 && in_array($groupid, $teacher_groupids)) {
        // Moderador selecionou grupo permitido
        $groupfilter = "INNER JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid";
        $params['groupid'] = $groupid;
    } else {
        // Moderador selecionou "todos" ou tentou grupo que não tem acesso: filtra apenas os grupos que ele participa
        list($ingroupsql, $ingroupparams) = $DB->get_in_or_equal($teacher_groupids, SQL_PARAMS_NAMED, 'grp');
        $groupfilter = "INNER JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid $ingroupsql";
        $params = array_merge($params, $ingroupparams);
    }
} else if ($groupid > 0) {
    // Professor ou gerente com grupo selecionado
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
        WHEN ue.timeend = 6739200 THEN 'Aluno egresso'
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
        WHEN COUNT(DISTINCT ccc.criteriaid) < (
            SELECT COUNT(*)
            FROM mdl_course_completion_criteria
            WHERE course = :courseid5
        )
        AND ue.timeend > 0
        AND TO_TIMESTAMP (ue.timeend) > NOW () THEN 'Em andamento'
        WHEN COUNT(DISTINCT ccc.criteriaid) < (
            SELECT COUNT(*)
            FROM mdl_course_completion_criteria
            WHERE course = :courseid6
        ) THEN 'Possível evasão'
        ELSE 'Concluído'
    END AS status_final
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
    HAVING 1=1" . (!empty($statusfilter) ? " AND (
    CASE
        WHEN TO_TIMESTAMP (ue.timestart) > NOW () THEN 'A iniciar'
        WHEN COUNT(DISTINCT ccc.criteriaid) < (
            SELECT COUNT(*) FROM mdl_course_completion_criteria WHERE course = :courseid2
        ) AND ue.timeend > 0 AND TO_TIMESTAMP (ue.timeend) > NOW () THEN 'Em andamento'
        WHEN COUNT(DISTINCT ccc.criteriaid) < (
            SELECT COUNT(*) FROM mdl_course_completion_criteria WHERE course = :courseid7
        ) THEN 'Possível evasão'
        ELSE 'Concluído'
        END
    ) = :statusfilter" : "") . "
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
            $row->status_final

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
            $row->status_final

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
// Cores dos status
echo html_writer::tag('style', '
    .status-concluido td     { background-color: #d4edda !important; }       /* Verde claro */
    .status-ainiciar td      { background-color: #f8f9fa !important; }       /* Cinza claro */
    .status-emandamento td   { background-color: #fff3cd !important; }       /* Amarelo claro */
    .status-possivel-evasao td { background-color: #f8d7da !important; }     /* Vermelho claro */
');


// Renderização HTML
echo $OUTPUT->header();

if ($grupoacessonegado) {
    echo $OUTPUT->notification('Você não possui acesso a esse grupo. Por favor, selecione outro grupo abaixo.', 'notifyproblem');
}


// Filtro de Grupo
echo html_writer::start_tag('form', ['id' => 'formId', 'method' => 'get', 'style' => 'margin-bottom: 0.75rem;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::label('Grupo: ', 'groupid', ['style' => 'margin-bottom: 0.75rem;']);
echo html_writer::select(
    $groupoptions,
    'groupid',
    $groupid,
    false,
    [
        'id' => 'groupid',
        'style' => 'margin-left: 0.75rem;',
        'margin-bottom: 0.75rem;'
    ]
);
echo html_writer::label('Status: ', 'status', ['style' => 'margin-left: 0.5rem; margin-bottom: 0.75rem;']);
echo html_writer::select(
    [
        '' => 'Todos',
        'Concluído' => 'Concluído',
        'Em andamento' => 'Em andamento',
        'Possível evasão' => 'Possível evasão',
        'A iniciar' => 'A iniciar'
    ],
    'status',
    $statusfilter,
    false,
    [
        'id' => 'status',
        'style' => 'margin-left: 0.5rem;'
    ]
);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Filtrar', 'class' => 'btn btn-primary', 'style' => 'margin-left: 0.75rem;', 'margin-bottom: 0.75rem;']);
echo html_writer::end_tag('form');

// Tabela
$table = new html_table();
$table->head = ['Nome Completo', 'Grupo(s)', 'Data Início Turma Disciplina', 'Data Fim Turma Disciplina', 'Tempo de Acesso', 'Atividades Pendentes', 'Status'];

if (empty($data)) {
    $table->data[] = [
        html_writer::tag('em', 'Sem registros encontrados.'),
        '',
        '',
        '',
        '',
        '',
        ''
    ];
} else {
    foreach ($data as $row) {
        // Define a classe da linha com base no status
        $statusclass = '';
        switch (strtolower($row->status_final)) {
            case 'concluído':
                $statusclass = 'status-concluido';
                break;
            case 'a iniciar':
                $statusclass = 'status-ainiciar';
                break;
            case 'em andamento':
                $statusclass = 'status-emandamento';
                break;
            case 'possível evasão':
                $statusclass = 'status-possivel-evasao';
                break;
        }

        // Dados da linha
        $rowdata = [
            $row->fullname,
            $row->groupname,
            $row->data_inicio_turma_disciplina,
            $row->data_fim_turma_disciplina,
            $row->tempo_formatado,
            $row->porcentagem_pendente,
            $row->status_final
        ];

        $rowobj = new html_table_row();
        $rowobj->cells = $rowdata;
        $rowobj->attributes['class'] = $statusclass;
        $table->data[] = $rowobj;

    }
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
    'status' => $statusfilter,
    'download' => 'xlsx'
]);

$buttonattributes = [
    'class' => 'btn btn-primary',
    'style' => 'margin-top:0.75rem; margin-bottom: 0.75rem;'
];

if (empty($data)) {
    $buttonattributes['class'] .= ' disabled';
    $buttonattributes['aria-disabled'] = 'true';
    $buttonattributes['onclick'] = 'return false;';
    $buttonattributes['style'] = 'background-color: #808080; border-color: #808080; cursor: not-allowed';
}

echo html_writer::link(
    $xlsxurl,
    'Exportar XLSX',
    $buttonattributes
);

$countfilteredsql = "
    SELECT COUNT(*) FROM (
        SELECT u.id
        FROM {user} u
        INNER JOIN {user_enrolments} ue ON ue.userid = u.id
        INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
        INNER JOIN {role_assignments} ra ON ra.userid = u.id AND ra.roleid = 5
        INNER JOIN {context} ct ON ct.contextlevel = 50 AND ct.instanceid = e.courseid AND ct.id = ra.contextid
        LEFT JOIN {course_completion_crit_compl} ccc ON ccc.userid = u.id AND ccc.course = e.courseid
        $groupfilter
        GROUP BY u.id, ue.timestart, ue.timeend
        HAVING 1=1" .
    (!empty($statusfilter) ? " AND (
            CASE
                WHEN TO_TIMESTAMP (ue.timestart) > NOW () THEN 'A iniciar'
                WHEN COUNT(DISTINCT ccc.criteriaid) < (
                    SELECT COUNT(*) FROM mdl_course_completion_criteria WHERE course = :courseid2
                ) AND ue.timeend > 0 AND TO_TIMESTAMP (ue.timeend) > NOW () THEN 'Em andamento'
                WHEN COUNT(DISTINCT ccc.criteriaid) < (
                    SELECT COUNT(*) FROM mdl_course_completion_criteria WHERE course = :courseid7
                ) THEN 'Possível evasão'
                ELSE 'Concluído'
            END
        ) = :statusfilter" : "") . "
    ) AS subquery
";

$totalfiltered = $DB->count_records_sql($countfilteredsql, $params);

// Paginação
$baseurl = new moodle_url('/local/coursecompletions/index.php', [
    'id' => $courseid,
    'groupid' => $groupid,
    'status' => $statusfilter
]);

if ($totalfiltered > $perpage) {
    echo $OUTPUT->paging_bar($totalfiltered, $page, $perpage, $baseurl);
}

echo $OUTPUT->footer();
