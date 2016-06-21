<?php
/*
 * Used for server to get information
 */

echo (json_encode(array(
    'client_type' => 'php',
    'cache_writable' => is_writable(dirname(__FILE__) . '/tagscache/')
)));
