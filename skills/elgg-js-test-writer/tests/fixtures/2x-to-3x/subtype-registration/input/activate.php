<?php

if (!update_subtype('object', 'myplugin_post', MyPost::class)) {
    add_subtype('object', 'myplugin_post', MyPost::class);
}

update_subtype('object', 'myplugin_post');
