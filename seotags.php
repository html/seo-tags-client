<?php
/** 
 * Version: 0.0.8
 * Processes html and possibly replaces <title/> tag, <meta name="keywords"/> and <meta name="description"/> tags
 */

define('SEO_EDITOR_CODE_LOADED', true);

class SeoTagsInternalTagsDb {
    protected $_data = array();

    function loadTagsForCurrentPage()
    {
        $page = $_SERVER['REQUEST_URI'];
        $filename = dirname(__FILE__) . '/tagscache/' . md5(SeoTagsProcessor::getRequestUri());

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
    public static $serviceIncludedHtml = '<script type="text/javascript" src="http://me:5555/pub/scripts/seo-editor-panel.js"></script>';
    public static $socketTimeout = '0.05'; // Need small value to not protract client site requests
    public $tagsDb;
    public $requestId;
    public static $tags = array();
    public static $suggestions = array();
    public static $instance;

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

        if(!preg_match('/<(html|head)/i', substr(trim($html), 0, 200))){
            // This is not html

            return false;
        }

        list($headEndPos, $htmlRest) = $this->ownStrpos($html, '</head>');
        $headHtml = substr($html, 0, $headEndPos + strlen('</head>'));

        $titleHtml = $this->getTitleTagHtml($headHtml);
        $metaTagsHtml = $this->getDescriptionAndKeywordsHtml($headHtml);
        $htmlToBeInjected = '';

        if(isset($_COOKIE['show-seo-editor-panel']) && $_COOKIE['show-seo-editor-panel']){
            $htmlToBeInjected .= self::$serviceIncludedHtml;
        }

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
                    list($pos, $dummy) = $this->ownStrpos($titleHtml, $this->tagsDb->getFieldData('titleBefore'));
                    if(!$pos){
                        $this->sendNotification('server-title-changed');
                    }
                }
            }

        // There is no title on page but there is title from seo-editor 
        }else{

            // Page already processed by seo-editor
            if($this->tagsDb->getFieldData('title')){
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
                    list($pos, $dummy) = $this->ownStrpos($metaTagsHtml['description'], $this->tagsDb->getFieldData('descriptionBefore'));
                    if(!$pos){
                        $this->sendNotification('server-description-changed');
                    }
                }
            }
        // There is no description on page but there is description from seo-editor 
        }else{

            // Page already processed by seo-editor
            if($this->tagsDb->getFieldData('description')){
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
                    list($pos, $dummy) = $this->ownStrpos($metaTagsHtml['keywords'], $this->tagsDb->getFieldData('keywordsBefore'));
                    if(!$pos){
                        $this->sendNotification('server-keywords-changed');
                    }
                }
            }
        // There is no keywords on page but there are keywords from seo-editor 
        }else{

            // Page already processed by seo-editor
            if($this->tagsDb->getFieldData('keywords')){
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
        $post_params = array();
        foreach ($params as $key => &$val) {
            if (is_array($val)) $val = implode(',', $val);
            $post_params[] = $key.'='.urlencode($val);
        }
        $post_string = implode('&', $post_params);

        $parts=parse_url($url);

        $fp = fsockopen($parts['host'],
            isset($parts['port'])?$parts['port']:80,
            $errno, $errstr, self::$socketTimeout);

        if(!$fp){
            return;
        }

        stream_set_timeout($fp, self::$socketTimeout);

        // Data goes in the path for a GET request
        if('GET' == $type) $parts['path'] .= '?'.$post_string;

        $out = "$type ".$parts['path']." HTTP/1.1\r\n";

        $out.= "Host: ".$parts['host']."\r\n";

        if('POST' == $type){
            $out.= "Content-Type: application/x-www-form-urlencoded\r\n";
            $out.= "Content-Length: ".strlen($post_string)."\r\n";
        }

        $out.= "Connection: Close\r\n\r\n";
        // Data goes in the request body for a POST request
        if ('POST' == $type && isset($post_string)) $out.= $post_string;

        fwrite($fp, $out);
        fclose($fp);
    }

    public function sendNotification($errorType, $params = array())
    {
        try{

            $this->curl_request_async(self::$serviceUrlEndpoint, array_merge($params, array(
                'error-code' => $errorType,
                'scheme' => isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http',
                'host' => $_SERVER['HTTP_HOST'],
                'port' => $_SERVER['SERVER_PORT'],
                'page-url' => self::getRequestUri(),
                'page-tags' => join(',', self::$tags),
                'page-suggestions' => function_exists('json_encode') && self::$suggestions ? json_encode(self::$suggestions) : '',
                'uri-hash' => md5(self::getRequestUri()),
                'request-id' => $this->requestId
            )));

        }catch(Exception $e){
            // Just ignore it
        }
    }

    public static function getRequestUri()
    {
        return htmlspecialchars_decode($_SERVER['REQUEST_URI']);
    }

    public static function getInstance()
    {
        if(!self::$instance){
            self::$instance = new self;
        }

        return self::$instance;
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
    $processor = SeoTagsProcessor::getInstance();
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

/* 
 * Takes many string arguments each is a string, a tag name for seo-editor page instance
 * Don't use values containing ',' for this 
 */
function tagThisPageForSeoEditor(){
    $args = func_get_args();
    SeoTagsProcessor::$tags = array_merge(SeoTagsProcessor::$tags, $args);
    if(SeoTagsProcessor::$processedAlready){
        SeoTagsProcessor::getInstance()->sendNotification('updating-page-info-after-page-processing');
    }
}

/* 
 * Suggests value $value for tag $metaTag for current page
 * You should also provide $generationTag for us to identify your suggestion for page (needed for some server tasks), and for you to see page tagged
 * It is recommended for generationTag to be unique value for each new suggestion
 * If you have new algorythm of generation title|description|keywords, it is better to place new suggestThisPageMetaTagValueForSeoEditor call with specified algorythm
 * generationDescription is not necessary. It is necessary if you want to provide description for algorythm used.
 * Please make sure generationDescription is unique for each generationTag 
 * For example generationTag is "generating-title-1" and generationDescription is "This is simple title, a product name and product description joined with '-'" 
 * or generationTag is "generating-title-2" and generationDescription is "This is more complex title, it shortes the generating-title-1 description"
 */
function suggestThisPageMetaTagValueForSeoEditor($metaTag, $value, $generationTag, $generationDescription = ''){
    SeoTagsProcessor::$suggestions[] = array(
        'metaTag' => $metaTag, 
        'value' => $value,
        'generationTag' => $generationTag, 
        'generationDescription' => $generationDescription
    );

    if(SeoTagsProcessor::$processedAlready){
        SeoTagsProcessor::getInstance()->sendNotification('updating-page-info-after-page-processing');
    }
}

if(!defined('DISABLE_SEOTAGS_OUTPUT_BUFFERING')){
    ob_start('replaceSeoTagsOb');

    if(function_exists('register_shutdown_function')){
        register_shutdown_function('ob_end_flush');
    }
}
