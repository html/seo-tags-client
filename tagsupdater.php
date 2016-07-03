<?php 
/*
 * Version: 0.0.3
 */

function saveTags($url, $data){
    $filename = dirname(__FILE__) . '/tagscache/' . md5($url);
    file_put_contents($filename, serialize($data)) or die("Error saving file");
}

if(isset($_POST['tags']) && isset($_POST['url'])){
    saveTags($_POST['url'], $_POST['tags']);
    echo "OK";
}

if(isset($_GET['ping'])){
    define('DISABLE_SEOTAGS_OUTPUT_BUFFERING', true);
    require_once dirname(__FILE__) . '/seotags.php';
    $processor = new SeoTagsProcessor();
    $processor->sendNotification('ping', array('pingParam' => true));

    die('pinged');
}
