<?php
$body = elgg_view('forum/content');
echo elgg_view_page($title, $body);
echo elgg_view_module('info', null, 'content');
