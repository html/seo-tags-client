<?php 
/*
 * Version: 0.0.2
 */

function saveTags($url, $data){
    $filename = dirname(__FILE__) . '/tagscache/' . md5($url);
    file_put_contents($filename, serialize($data)) or die("Error saving file");
}

if(isset($_POST['tags']) && isset($_POST['url'])){
    saveTags($_POST['url'], $_POST['tags']);
    echo "OK";
}
