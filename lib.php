<?php
function local_coursecompletions_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('local/coursecompletions:view', $context)) {
        $url = new moodle_url('/local/coursecompletions/index.php', ['id' => $course->id]);
        $navigation->add(
            get_string('menu', 'local_coursecompletions'),
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'coursecompletions',
            new pix_icon('i/report', '')
        );
    }
}
