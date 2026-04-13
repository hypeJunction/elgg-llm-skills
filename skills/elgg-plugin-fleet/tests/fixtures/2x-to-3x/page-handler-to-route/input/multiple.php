<?php

// Plugin registering multiple page handlers with function callbacks

elgg_register_page_handler('forum', 'forum_page_handler');
elgg_register_page_handler('forumtopic', 'forumtopic_page_handler');
