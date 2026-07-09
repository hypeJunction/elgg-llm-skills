<?php

// Simple action — trivial, < 15 LOC, 1 branch
$guid = (int) get_input('guid');
if (!$guid) {
    return elgg_error_response(elgg_echo('error:missing_data'));
}
return elgg_ok_response();
