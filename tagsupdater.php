<?php 
/*
 * Version: 0.0.1
 */

function saveTags($url, $data){
    $filename = dirname(__FILE__) . '/tagscache/' . md5($url);
    file_put_contents($filename, serialize($data));
}

if(isset($_GET['tag']) && isset($_GET['url'])){
    saveTags($_GET['url'], $_GET['tag']);
}
