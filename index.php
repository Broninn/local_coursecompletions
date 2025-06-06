<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/csvlib.class.php');

$courseid = required_param('id', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = get_config('moodle', 'userlistperpage') ?: 20;
$download = optional_param('download', 0, PARAM_BOOL);

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
$params = ['courseid' => $courseid];
$params['groupidcourse'] = $courseid;


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
        END AS status
    FROM {user} u
    INNER JOIN {user_enrolments} ue ON ue.userid = u.id
    INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
    INNER JOIN {role_assignments} ra ON ra.userid = u.id AND ra.roleid = 5
    INNER JOIN {context} ct ON ct.contextlevel = 50 AND ct.instanceid = e.courseid AND ct.id = ra.contextid
    LEFT JOIN {course_completions} cc ON cc.course = e.courseid AND cc.userid = u.id
    $groupfilter
    GROUP BY u.id, u.firstname, u.lastname, cc.timecompleted
    ORDER BY fullname
";

if (!$download) {
    $datasql .= " LIMIT :limit OFFSET :offset";
    $params['limit'] = $perpage;
    $params['offset'] = $page * $perpage;
}

$data = $DB->get_records_sql($datasql, $params);

// Exportar XLS -- Falta complementar
if ($download) {
    require_once($CFG->libdir . '/dataformatlib.php');
    $filename = 'relatorio_conclusao_' . $courseid;

    $exportdata = [];
    foreach ($data as $row) {
        $exportdata[] = [
            'Nome Completo' => $row->fullname,
            'Grupo(s)' => $row->groupname,
            'Status' => $row->status,
        ];
    }

    download_as_dataformat($filename, 'xls', ['Nome Completo', 'Grupo(s)', 'Status'], $exportdata);
    exit;
}

// Renderização HTML
echo $OUTPUT->header();
echo $OUTPUT->heading('Relatório de Conclusão de Curso');

// Filtro de Grupo
echo html_writer::start_tag('form', ['method' => 'get']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::label('Grupo: ', 'groupid');
echo html_writer::select($groupoptions, 'groupid', $groupid, false, ['style' => 'margin-left: 0.75rem;']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Filtrar', 'class' => 'btn btn-primary', 'style' => 'margin-left: 0.75rem;']);
echo html_writer::end_tag('form');

// Botão para exportar XLS -- Falta complementar
// $downloadurl = new moodle_url('/local/coursecompletions/index.php', ['id' => $courseid, 'groupid' => $groupid, 'download' => 1]);
// echo html_writer::link($downloadurl, 'Exportar para Excel', ['class' => 'btn btn-primary', 'style' => 'margin-top: 10px; display: inline-block;']);

// Tabela
$table = new html_table();
$table->head = ['Nome Completo', 'Grupo(s)', 'Status'];
foreach ($data as $row) {
    $table->data[] = [$row->fullname, $row->groupname, $row->status];
}
echo html_writer::table($table);

// Paginação
$baseurl = new moodle_url('/local/coursecompletions/index.php', ['id' => $courseid, 'groupid' => $groupid]);
echo $OUTPUT->paging_bar($totalusers, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
