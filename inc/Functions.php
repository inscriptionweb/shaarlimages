<?php

/**
 * Useful functions (GD, sort, serialize, ...)
 */
class Fct
{

    /**
     * load_url() flags.
     */
    const NONE    = 0;  // Nothing special
    const PARTIAL = 1;  // Retrieve only the firsts $bytes bytes
    const IMAGE   = 2;  // We are retrieving an image

    /**
     * Prefix and suffix for data storage.
     */
    private static $prefix = '<?php /* ';
    private static $suffix = ' */ ?>';

    /**
     * Bytes to download when using partial resource retrieval.
     */
    private static $bytes = 8;


    /**
     * Little debug function.
     */
    public static function __($value)
    {
        if ( Config::$debug ) {
            echo '<pre>'."\n";
            var_dump($value);
            echo '</pre>'."\n\n";
        }
    }

    /**
     * Retrieve one resource entierely or partially.
     * http://stackoverflow.com/questions/2032924/how-to-partially-download-a-remote-file-with-curl
     */
    public static function load_url($url = null, $flag = self::NONE, $headers = array())
    {
        if ( $url === null ) {
            return false;
        }
        $ch = curl_init();
        switch ( $flag )
        {
            case self::PARTIAL:
                curl_setopt($ch, CURLOPT_RANGE, '0-'.self::$bytes);
                curl_setopt($ch, CURLOPT_BUFFERSIZE, self::$bytes);
                break;
            case self::IMAGE:
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
                break;
            default:
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                break;
        }
        if ( !empty($headers) ) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, Config::$ua);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSLVERSION, 3);
        $data = curl_exec($ch);
        if ( curl_errno($ch) == 35 ) {
            curl_setopt($ch, CURLOPT_SSLVERSION, 1);
            $data = curl_exec($ch);
        }
        curl_close($ch);
        return $data;
    }

    /**
     * Secure way to write a file.
     */
    public static function secure_save($file, $data)
    {
        $ret = file_put_contents($file, $data, LOCK_EX);
        if ( $ret ) {
            @chgrp($file, 'www-data');
            @chmod($file, 0775);
        } else {
            self::__(error_get_last());
        }
        return $ret;
    }

    /**
     * Custom serialize.
     */
    public static function serialise($value)
    {
        return self::$prefix
            .base64_encode(gzdeflate(serialize($value)))
            .self::$suffix;
    }

    /**
     * Custom unserialize.
     */
    public static function unserialise($file)
    {
        $ret = @unserialize(gzinflate(base64_decode(
            substr(
                file_get_contents($file, LOCK_EX),
                strlen(self::$prefix),
                -strlen(self::$suffix)
            )
        )));
        if ( $ret === false ) {
            self::__(error_get_last());
        }
        return $ret;
    }

    /**
     * Sanitize an URL.
     */
    public static function friendly_url($url)
    {
        $url = preg_replace('#\&([A-za-z])(?:acute|cedil|circ|grave|ring|tilde|uml)\;#', '\1', $url);
        $url = preg_replace('#\&([A-za-z]{2})(?:lig)\;#', '\1', $url);
        $url = stripslashes(strtok(urldecode(strtolower(trim($url))), '?'));
        filter_var(htmlentities($url, ENT_QUOTES, 'UTF-8'), FILTER_SANITIZE_STRING);
        return str_replace(array(' ', '#', "'"), '-', $url);
    }

    /**
     * Create a directory, if not present.
     */
    public static function create_dir($dir, $mode = 0777)
    {
        if ( !is_dir($dir) ) {
            mkdir($dir, $mode);
            @chgrp($file, 'www-data');
            @chmod($dir, $mode);
        }
    }

    /**
     * Returns the small hash of a string
     * http://sebsauvage.net/wiki/doku.php?id=php:shaarli
     * eg. smallHash('20111006_131924') --> yZH23w
     * Small hashes:
     * - are unique (well, as unique as crc32, at last)
     * - are always 6 characters long.
     * - only use the following characters: a-z A-Z 0-9 - _ @
     * - are NOT cryptographically secure (they CAN be forged)
     *
     * @param string $text Text to convert into small hash
     *
     * @return string      Small hash corresponding to the given text
     */
    public static function small_hash($text)
    {
        $hash = rtrim(base64_encode(hash('crc32', $text, true)), '=');
        // Get rid of characters which need encoding in URLs.
        $hash = str_replace('+', '-', $hash);
        $hash = str_replace('/', '_', $hash);
        $hash = str_replace('=', '@', $hash);
        return $hash;
    }

    /**
     * Generate the JSON file.
     * Added a RATIO limit for images with width/height too small or too big,
     * it prevents to have a gallery as beautiful as Internet Explorer ...
     */
    public static function generate_json() {
        $images = array();
        $tmp = array();
        foreach ( glob(Config::$cache_dir.'*.php') as $db )
        {
            $tmp = self::unserialise($db);
            unset($tmp['date']);
            foreach ( array_keys($tmp) as $key )
            {
                /* RATIO limit */list($width, $height) = getimagesize(Config::$img_dir.$tmp[$key]['link']);
                /* RATIO limit */$ratio = $width / $height;
                /* RATIO limit */if ( $ratio >= 0.3 && $ratio <= 3.0 )
                /* RATIO limit */{
                    if ( empty($images[$key]) ) {
                        $images[$key] = $tmp[$key];
                    }
                    elseif ( $tmp[$key]['date'] < $images[$key]['date'] ) {
                        // Older is better (could be the first to share)
                        $images[$key] = $tmp[$key];
                    }
                /* RATIO limit */}
            }
        }
        uasort($images, 'self::compare_date');
        self::secure_save(Config::$database, self::serialise($images));

        $lines = "var gallery = [\n";
        $line = "{'key':'%s','src':'%s','w':%d,'h':%d,'guid':'%s','date':%d,'nsfw':%d},\n";
        foreach ( $images as $key => $data )
        {
            if ( is_file(Config::$img_dir.$data['link']) ) {
                list($width, $height, $type) = getimagesize(Config::$img_dir.$data['link']);
                if ( !is_file(Config::$thumb_dir.$data['link']) ) {
                    list($width, $height) = self::create_thumb($data['link'], $width, $height, $type);
                }
                $lines .= sprintf($line,
                    $key, $data['link'],
                    $width, $height,
                    str_replace("'", "\\'", $data['guid']),
                    $data['date'],
                    $data['nsfw']
                );
            }
        }
        $lines .= "];\n";
        self::secure_save(Config::$json_file, $lines);
        self::invalidate_caches();
    }

    /**
     * Callback for uasort().
     * If will do a rsort() with a multi-dimensional array.
     */
    public static function compare_date($a, $b)
    {
        if ( $a['date'] == $b['date'] ) {
            return 0;
        }
        return ( $a['date'] < $b['date'] ) ? 1 : -1;  // invert '1 : -1' for sort()
    }

    /**
     * Create an optimized and progressive JPEG small-sized file
     * from original (big) image.
     */
    public static function create_thumb($file, $width, $height, $type)
    {
        $quality = 95;
        $progressive = true;

        // We want image 800x600 pixels max
        $coef = $width / $height;
        if ( $width >= $height ) {
            $new_width = ($width > 800) ? 800 : $width;
            $new_height = ceil($new_width / $coef);
        } else {
            $new_height = ($height > 600) ? 600 : $height;
            $new_width = ceil($new_height * $coef);
        }

        if ( $type == 2 ) {  // jpeg
            $source = imagecreatefromjpeg(Config::$img_dir.$file);
        } else {  // png
            $source = imagecreatefrompng(Config::$img_dir.$file);
        }

        $thumb = imagecreatetruecolor($new_width, $new_height);
        imageinterlace($thumb, $progressive);
        imagecopyresized($thumb, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagejpeg($thumb, Config::$thumb_dir.$file, $quality);
        imagedestroy($source);
        imagedestroy($thumb);
        return array($new_width, $new_height);
    }

    /**
     * Invalidate all RSS caches.
     */
    public static function invalidate_caches()
    {
        array_map('unlink', glob(Config::$rss_dir.'*.xml'));
    }

}

?>
