<?php

// error_log() — direct to PHP error log
error_log('Plugin booted');
error_log("user {$user->guid} signed in");

// elgg_log() with level constant (legacy 2.x form)
elgg_log('something happened', 'NOTICE');
elgg_log('a warning', 'WARNING');
elgg_log('an error', 'ERROR');
elgg_log('a debug message', 'DEBUG');
elgg_log('plain message');

// Logger:: constants used as string args to elgg_log()
elgg_log('with constant info', Logger::INFO);
elgg_log('with constant warning', Logger::WARNING);
elgg_log('with constant error', Logger::ERROR);
elgg_log('with constant debug', Logger::DEBUG);

// var_dump / print_r used as debug residue (these get warned, not rewritten)
var_dump($user);
print_r($entity);

// Should NOT be rewritten — these are non-debug uses
$str = print_r($value, true); // captured to string — keep
$x = error_log;               // not a call — keep
