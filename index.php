<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/csvlib.class.php');

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
$params = [
    'courseid' => $courseid,
    'groupidcourse' => $courseid,
    'groupid' => $groupid,
    'dedicationcourseid' => $courseid, // novo alias
];

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

// SQL principal
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
            WHEN cc.timecompleted IS NOT NULL THEN 'Concluído'
            ELSE 'Possível evasão'
        END AS status,
        
        CASE 
            WHEN bd.total IS NULL OR bd.total = 0 THEN 'Nunca acessou'
            ELSE
            TRIM(BOTH FROM
            CONCAT(
                CASE 
                    WHEN EXTRACT(hour FROM make_interval(secs => bd.total)) > 0 
                        THEN EXTRACT(hour FROM make_interval(secs => bd.total)) || 'h '
                    ELSE ''
                END,
                EXTRACT(minute FROM make_interval(secs => bd.total)) || 'min'
            )
        )
        END AS tempo_formatado
    FROM {user} u
    INNER JOIN {user_enrolments} ue ON ue.userid = u.id
    INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
    INNER JOIN {role_assignments} ra ON ra.userid = u.id AND ra.roleid = 5
    INNER JOIN {context} ct ON ct.contextlevel = 50 AND ct.instanceid = e.courseid AND ct.id = ra.contextid
    LEFT JOIN {course_completions} cc ON cc.course = e.courseid AND cc.userid = u.id
    LEFT JOIN (
        SELECT userid, courseid, SUM(timespent) AS total
        FROM {block_dedication}
        WHERE courseid = :dedicationcourseid
        GROUP BY userid, courseid
    ) bd ON bd.userid = u.id AND bd.courseid = e.courseid
    $groupfilter
    GROUP BY u.id, u.firstname, u.lastname, cc.timecompleted, bd.total
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
    header('Content-Disposition: attachment; filename="relatorio_conclusao_' . $courseid . '.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['Nome Completo', 'Grupo(s)', 'Status', 'Tempo de Acesso'], ';');

    // Dados
    foreach ($data as $row) {
        fputcsv($out, [
            $row->fullname,
            $row->groupname,
            $row->status,
            $row->tempo_formatado
        ], ';');
    }


    fclose($out);
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

// Botão CSV
$csvurl = new moodle_url('/local/coursecompletions/index.php', [
    'id' => $courseid,
    'groupid' => $groupid,
    'download' => 'csv'
]);

// Tabela
$table = new html_table();
$table->head = ['Nome Completo', 'Grupo(s)', 'Status', 'Tempo de Acesso'];
foreach ($data as $row) {
    $table->data[] = [$row->fullname, $row->groupname, $row->status, $row->tempo_formatado];
}
echo html_writer::table($table);

echo html_writer::link(
    $csvurl,
    'Exportar CSV',
    [
        'class' => 'btn btn-primary',  // btn-primary, btn-secondary, btn-success etc.
        'style' => 'margin-top:0.75rem; margin-bottom: 0.75rem'
    ]
);

// Paginação
$baseurl = new moodle_url('/local/coursecompletions/index.php', ['id' => $courseid, 'groupid' => $groupid]);
echo $OUTPUT->paging_bar($totalusers, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
