<?php
add_entity_relationship($user_guid, 'notify:email', $target_guid);
add_entity_relationship($user_guid, 'notify:site', $target_guid);
check_entity_relationship($user_guid, 'notify:web', $target_guid);
