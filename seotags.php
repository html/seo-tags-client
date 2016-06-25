<?php
/** 
 * Version: 0.0.5
 * Processes html and possibly replaces <title/> tag, <meta name="keywords"/> and <meta name="description"/> tags
 */

class SeoTagsInternalTagsDb {
    protected $_data = array();

    function loadTagsForCurrentPage()
    {
        $page = $_SERVER['REQUEST_URI'];
        $filename = dirname(__FILE__) . '/tagscache/' . md5($_SERVER['REQUEST_URI']);

        if(file_exists($filename)){
            $this->_data = unserialize(file_get_contents($filename));
        }
    }

    function getFieldData($key)
    {
        return isset($this->_data[$key]) ? $this->_data[$key] : null;
    }
}

class SeoTagsProcessor {
    /* True after first processing, false before */
    public static $processedAlready = false;
    public static $serviceUrlEndpoint = 'http://me:5555/process-notification';
    public static $serviceIncludedHtml = '<script type="text/javascript" src="http://me/service-javascript.js"></script>';
    public $tagsDb;
    public $requestId;

    function __construct()
    {
        $this->tagsDb = new SeoTagsInternalTagsDb;
        $this->requestId = md5(time() . $_SERVER['REQUEST_URI'] . rand());

        try{
            $this->tagsDb->loadTagsForCurrentPage();

            if(!$this->tagsDb->getFieldData('title')){
                $this->sendNotification('no-data-for-page');
            }

        }catch(Exception $e){
            $this->sendNotification('error-loading-tags-database');
        }
    }

    /*
     * Returns array with 2 elements - position of $needle in $html and the rest substring after string searched
     */
    function ownStrpos($html, $needle){
        $strpos = function_exists('stripos') ? 'stripos' : 'strpos';
        $pos = $strpos($html, $needle);

        if($pos){
            return array($pos, substr($html, $pos + strlen($needle)));
        }

        return array(false, false);
    }

    /*
     * Returns <title> tag html
     */
    function getTitleTagHtml($html){
        /*
         * Note: use strpos instead of preg_match and str_replace instead of preg_replace
         */
        list($startPos, $rest) = $this->ownStrpos($html,  '<title');

        if(!$startPos){
            return false;
        }

        list($endPos, $rest) = $this->ownStrpos($rest, '</title>');

        if(!$endPos){
            return false;
        }

        return substr($html, $startPos, $endPos + strlen('<title') + strlen('</title>'));
    }

    /*
     * Returns empty array or array with "keywords" and "description" keys
     * Array values are corresponding tag values
     */
    function getDescriptionAndKeywordsHtml($html){
        $matches = array();
        if(preg_match_all('/<meta[^>]+(description|keywords)[^>]*?>/i', $html, $matches)){
            $results = array();

            foreach ($matches[0] as $key => $item) {
                $results[$matches[1][$key]] = $item;
            }

            return $results;
        }
    }

    function replaceSeoTags($html){
        $args = func_get_args();

        if(!preg_match('/<html/i', substr(trim($html), 0, 200))){
            // This is not html

            return false;
        }

        list($headEndPos, $htmlRest) = $this->ownStrpos($html, '</head>');
        $headHtml = substr($html, 0, $headEndPos + strlen('</head>'));

        $titleHtml = $this->getTitleTagHtml($headHtml);
        $metaTagsHtml = $this->getDescriptionAndKeywordsHtml($headHtml);
        $htmlToBeInjected = self::$serviceIncludedHtml;

        // Replacing title
        if($titleHtml){
            if($this->tagsDb->getFieldData('title')){
                $headHtml = str_replace($titleHtml, '<title>' . htmlspecialchars($this->tagsDb->getFieldData('title')) . '</title>', $headHtml);
            }

            // If server gives us title which is different from old version then tell server about it
            // This works for most cases but not all
            if($this->tagsDb->getFieldData('titleBefore')){
                if($this->tagsDb->getFieldData('titleBefore') == '<empty>'){
                    // TODO: Skipping this case for now, useless in most cases
                    // it is actual when server didn' have title tag and than title tag appeared

                // Server had title tag and contents of it changed
                }else{
                    list($pos, $dummy) = $this->ownStrpos($this->tagsDb->getFieldData('titleBefore'), $titleHtml);
                    if(!$pos){
                        $this->sendNotification('server-title-changed');
                    }
                }
            }

        // There is no title on page but there is title from seo-editor 
        }else{

            // Page already processed by seo-editor
            if($this->tagsDb->getFieldData('titleBefore') == '<empty>'){
                $htmlToBeInjected .= '<title>' . htmlspecialchars($this->tagsDb->getFieldData('title')) . '</title>';

            // Need to process page in seo-editor
            }else{
                $this->sendNotification('title-not-found');
            }
        }

        // Replacing description
        if(isset($metaTagsHtml['description'])){
            if($this->tagsDb->getFieldData('description')){
                $headHtml = str_replace($metaTagsHtml['description'], 
                    '<meta name="description" content="' . htmlspecialchars($this->tagsDb->getFieldData('description')) . '"/>', 
                    $headHtml 
                );
            }

            // If server gives us description which is different from old version then tell server about it
            // This works for most cases but not all
            if($this->tagsDb->getFieldData('descriptionBefore')){
                if($this->tagsDb->getFieldData('descriptionBefore') == '<empty>'){
                    // TODO: Skipping this case for now, useless in most cases
                    // it is actual when server didn' have description tag and than description tag appeared

                // Server had description tag and contents of it changed
                }else{
                    list($pos, $dummy) = $this->ownStrpos($this->tagsDb->getFieldData('descriptionBefore'), $metaTagsHtml['description']);
                    if(!$pos){
                        $this->sendNotification('server-description-changed');
                    }
                }
            }
        // There is no description on page but there is description from seo-editor 
        }else{

            // Page already processed by seo-editor
            if($this->tagsDb->getFieldData('descriptionBefore') == '<empty>'){
                $htmlToBeInjected .= '<meta name="description" content="' . htmlspecialchars($this->tagsDb->getFieldData('description')) . '"/>' . "\n";

            // Need to process page in seo-editor
            }else{
                $this->sendNotification('description-not-found');
            }
        }

        // Replacing keywords
        if(isset($metaTagsHtml['keywords'])){
            if($this->tagsDb->getFieldData('keywords')){
                $headHtml = str_replace($metaTagsHtml['keywords'], 
                    '<meta name="keywords" content="' . htmlspecialchars($this->tagsDb->getFieldData('keywords')) . '"/>', 
                    $headHtml 
                );
            }

            // If server gives us title which is different from old version then tell server about it
            // This works for most cases but not all
            if($this->tagsDb->getFieldData('keywordsBefore')){
                if($this->tagsDb->getFieldData('keywordsBefore') == '<empty>'){
                    // TODO: Skipping this case for now, useless in most cases
                    // it is actual when server didn' have keywords tag and than keywords tag appeared

                // Server had keywords tag and contents of it changed
                }else{
                    list($pos, $dummy) = $this->ownStrpos($this->tagsDb->getFieldData('keywordsBefore'), $metaTagsHtml['keywords']);
                    if(!$pos){
                        $this->sendNotification('server-keywords-changed');
                    }
                }
            }
        // There is no keywords on page but there are keywords from seo-editor 
        }else{

            // Page already processed by seo-editor
            if($this->tagsDb->getFieldData('keywordsBefore') == '<empty>'){
                $htmlToBeInjected .= '<meta name="keywords" content="' . htmlspecialchars($this->tagsDb->getFieldData('keywords')) . '"/>' . "\n";

            // Need to process page in seo-editor
            }else{
                $this->sendNotification('keywords-not-found');
            }
        }

        if($htmlToBeInjected){
            // Trying to prepend our html before any of popular head tags
            foreach(array( '<link','<meta', '<base', '<style', '<script') as $tag){
                list($pos, $rest) = $this->ownStrpos($headHtml, $tag);
                if($pos){
                    $headHtml = substr($headHtml, 0, $pos) . $htmlToBeInjected . $tag . $rest;
                    break;
                }
            }
        }

        return $headHtml . $htmlRest;
    }

    function process($html){
        if(SeoTagsProcessor::$processedAlready){
            return $html;
        }

        SeoTagsProcessor::$processedAlready = true;
        return $this->replaceSeoTags($html);
    }

    // $type must equal 'GET' or 'POST'
    /* Taken from here http://stackoverflow.com/questions/962915/how-do-i-make-an-asynchronous-get-request-in-php */
    function curl_request_async($url, $params, $type='GET')
    {
        foreach ($params as $key => &$val) {
            if (is_array($val)) $val = implode(',', $val);
            $post_params[] = $key.'='.urlencode($val);
        }
        $post_string = implode('&', $post_params);

        $parts=parse_url($url);

        $fp = fsockopen($parts['host'],
            isset($parts['port'])?$parts['port']:80,
            $errno, $errstr, 30);

        // Data goes in the path for a GET request
        if('GET' == $type) $parts['path'] .= '?'.$post_string;

        $out = "$type ".$parts['path']." HTTP/1.1\r\n";
        $out.= "Host: ".$parts['host']."\r\n";
        $out.= "Content-Type: application/x-www-form-urlencoded\r\n";
        $out.= "Content-Length: ".strlen($post_string)."\r\n";
        $out.= "Connection: Close\r\n\r\n";
        // Data goes in the request body for a POST request
        if ('POST' == $type && isset($post_string)) $out.= $post_string;

        fwrite($fp, $out);
        fclose($fp);
    }

    function sendNotification($errorType, $params = array())
    {
        try{

            $this->curl_request_async(self::$serviceUrlEndpoint, array_merge($params, array(
                'error-code' => $errorType,
                'scheme' => $_SERVER['REQUEST_SCHEME'],
                'host' => $_SERVER['HTTP_HOST'],
                'port' => $_SERVER['SERVER_PORT'],
                'page-url' => $_SERVER['REQUEST_URI'],
                'request-id' => $this->requestId
            )));

        }catch(Exception $e){
            // Just ignore it
        }
    }
}

/*
 * This is function version for using with ob_start
 */
function replaceSeoTagsOb($html){
    if(isset($_GET['renderWithoutSeotags'])){
        return false;
    }

    if(SeoTagsProcessor::$processedAlready){
        return false;
    }

    $start = microtime(true);
    $processor = new SeoTagsProcessor();
    $return = $processor->process($html);
    $timeWorked = microtime(true) - $start;
    $processor->sendNotification('processing-finished', array('profiling-time' => $timeWorked));

    return $return;
}

/*
 * This is function version for using without ob_start
 */
function replaceSeoTags($html){
    $result = replaceSeoTagsOb((string)$html);

    if(!$result){
        return $html;
    }

    return $result;
}

ob_start('replaceSeoTagsOb');

if(function_exists('register_shutdown_function')){
    register_shutdown_function('ob_end_flush');
}
