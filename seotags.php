<?php
/* Version: 0.0.1
 * Processes html and possibly replaces <title/> tag, <meta name="keywords"/> and <meta name="description"/> tags
 */

class SeoTagsProcessor {
    /* True after first processing, false before */
    public static $processedAlready = false;
    public static $serviceUrlEndpoint = 'http://me/request-receiver.php';

    /*
     * Returns array with 2 elements - position of $needle in $html and the rest substring after string searched
     */
    function ownStrpos($html, $needle){
        $strpos = function_exists('stripos') ? 'stripos' : 'strpos';
        $pos = $strpos($html, $needle);

        if($pos){
            return array($pos, substr($html, $pos + strlen($needle)));
        }

        return false;
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

        // Replacing title
        if($titleHtml){
            $headHtml = str_replace($titleHtml, '<title>!!!</title>', $headHtml);
        }else{
            $this->sendError('title-not-found');
        }

        // Replacing description
        if(isset($metaTagsHtml['description'])){
            $headHtml = str_replace($metaTagsHtml['description'], '<meta name="description" content="!!!"/>', $headHtml);
        }else{
            $this->sendError('keywords-not-found');
        }

        // Replacing keywords
        if(isset($metaTagsHtml['keywords'])){
            $headHtml = str_replace($metaTagsHtml['keywords'], '<meta name="keywords" content="!!!"/>', $headHtml);
        }else{
            $this->sendError('description-not-found');
        }
        $this->sendError('test-error');

        return $headHtml . $htmlRest;
    }

    function process($html){
        if(self::$processedAlready){
            return $html;
        }

        self::$processedAlready = true;
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

    function sendError($errorType)
    {
        try{
            $this->curl_request_async(self::$serviceUrlEndpoint, array(
                'error-code' => $errorType,
                'host' => $_SERVER['HTTP_HOST'],
                'port' => $_SERVER['SERVER_PORT'],
                'page-url' => $_SERVER['REQUEST_URI']
            ));
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

    $processor = new SeoTagsProcessor();
    return $processor->process($html);
}

/*
 * This is function version for using without ob_start
 */
function replaceSeoTags($html){
    $start = microtime(true);
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
