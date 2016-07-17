<?php 
/*
 * Version: 0.0.5
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

if(isset($_GET['show-db-size'])){
    ob_start();
    $data = system('du -sh tagscache');
    ob_end_clean();
    list($size, $name) = explode("\t", $data);
    echo $size;
}

if(isset($_GET['install-client-plugin'])){
    setcookie('show-seo-editor-panel', true, time() + 60 * 60 * 24 * 30, '/');
    header('Location: /');
    exit;
}

if(isset($_GET['seo-editor-panel-visible'])){
    echo (isset($_COOKIE['show-seo-editor-panel']) && $_COOKIE['show-seo-editor-panel']);
    exit;
}
