<?php
add_entity_relationship($user_guid, 'notifyemail', $target_guid);
add_entity_relationship($user_guid, 'notifysite', $target_guid);
check_entity_relationship($user_guid, 'notifyweb', $target_guid);
