<?php
/*
 * Used for SeoEditor server to get information about client
 */

echo (json_encode(array(
    'client_type' => 'php',
    'cache_writable' => is_writable(dirname(__FILE__) . '/tagscache/'),
    // Json serialization is required to save page suggestions
    'json_serialization_works' => function_exists('json_encode')
)));
