<?php

include 'inc/Functions.php';
include 'inc/Solver.php';

class Update
{

    /**
     * Host name running the galery -- to prevent re-downloading our images.
     */
    private $current_host = 'shaarlimages.net';

    /**
     * Folder containing images.
     */
    private $img_dir = 'images/';

    /**
     * Folder containing thumbnails.
     */
    private $thumb_dir = 'images/thumbs/';

    /**
     * Folder containing data.
     */
    private $data_dir = 'data/';

    /**
     * Cache folder.
     */
    private $cache_dir = 'data/cache/';

    /**
     * File where to save shaarlis feed's.
     */
    private $ompl_file = 'data/shaarlis.php';

    /**
     * Images database.
     */
    private $img_file = 'data/images.php';

    /**
     * Image database file.
     */
    private $database = 'data/images.php';

    /**
     * JSON file, generated from the images database.
     */
    private $json_file = 'images.json';

    /**
     * URL to the up to date shaarlis feed's.
     */
    private $ompl_url = 'https://nexen.mkdir.fr/shaarli-api/feeds?pretty=1';

    /**
     * Time to live for the OPML file.
     */
    private $ttl_opml = 21600;  // 60 * 60 * 6

    /**
     * Time to live for each shaarli.
     */
    private $ttl_shaarli = 3600;  // 60 * 60

    /**
     * Authorized extensions to download.
     */
    private $ext_ok = array('jpg', 'jpeg', 'png');

    /**
     * Extension to append if not present.
     * Key is the $type number of getimagesize().
     */
    private $ext = array(2 => '.jpg', 3 => '.png');

    /**
     * Shaarlis feed's URL.
     */
    public $feeds = array();


    public function __construct()
    {
        Fct::create_dir($this->img_dir, 0755);
        Fct::create_dir($this->thumb_dir, 0755);
        Fct::create_dir($this->data_dir);
        Fct::create_dir($this->cache_dir);
        self::get_opml(is_file($this->ompl_file));
    }

    /**
     * Retrieve the OPML file containing all shaarlis feed's.
     */
    private function get_opml($check_for_update = false)
    {
        $need_update = true;
        if ( $check_for_update )
        {
            $this->feeds = Fct::unserialise($this->ompl_file);
            $diff = date('U') - $this->feeds['update'];
            if ( $diff < $this->ttl_opml ) {
                $need_update = false;
            }
        }
        if ( $need_update )
        {
            $data = json_decode(Fct::load_url($this->ompl_url), true);
            if ( !empty($data) )
            {
                foreach ( $data as $shaarli )
                {
                    $host = parse_url($shaarli['link'], 1);
                    $this->feeds['domains'][$host] = array(
                        'url' => stripslashes($shaarli['url'])
                    );
                }
                $this->feeds['update'] = date('U');
                Fct::secure_save($this->ompl_file, Fct::serialise($this->feeds));
            }
        }
    }

    /**
     * Retrieve images from one feed.
     */
    public function read_feed($domain)
    {
        if ( !isset($this->feeds['domains'][$domain]) ) {
            return false;
        }

        $ret = 0;
        $now = date('U');
        $images = array('date' => 0);
        $output = $this->cache_dir.Fct::small_hash($domain).'.php';

        if ( is_file($output) ){
            $images = Fct::unserialise($output);
        }
        if ( $now - $images['date'] < $this->ttl_shaarli ) {
            return $ret;
        }

        $feed = utf8_encode(Fct::load_url($this->get_url($domain)));
        if ( empty($feed) ) {
            return $ret - 1;
        }

        try {
            $test = new SimpleXMLElement($feed);
        } catch (Exception $e) {
            Fct::__($this->get_url($domain).' ERROR: '.$e->getMessage());
            return $ret - 2;
        }

        foreach ( $test->channel->item as $item )
        {
            $pubDate = date('U', strtotime($item->pubDate));
            if ( $pubDate < $images['date'] ){
                break;
            }

            // Strip URL parameters
            // http://stackoverflow.com/questions/1251582/beautiful-way-to-remove-get-variables-with-php/1251650#1251650
            $link = strtok((string)$item->link, '?');

            $host = parse_url($link, 1);
            if ( $this->link_seems_ok($host, $link) ) {
                $data = false;
                $req = array();
                if ( array_key_exists($host, Solver::$domains) ) {
                    $func = Solver::$domains[$host];
                    $req = Solver::$func($link);
                    $data = Fct::load_url($req['link']);
                }
                elseif ( $this->test_link($link) ) {
                    $data = Fct::load_url($link);
                }
                if ( $data !== false )
                {
                    list($width, $height, $type, $nsfw) = array(0, 0, 0, false);
                    if ( count($req) > 1 ) {
                        $link = $req['link'];
                        $width = $req['width'];
                        $height = $req['height'];
                        $type = $req['type'];
                        $nsfw = $req['nsfw'];
                    }
                    if ( $width == 0 || $height == 0 || $type == 0 ) {
                        list($width, $height, $type) = getimagesizefromstring($data);
                    }
                    if ( $type == 2 || $type == 3 )  // jpeg, png
                    {
                        $key = Fct::small_hash($data);
                        if ( empty($images[$key]) )
                        {
                            $img = basename($link);
                            if ( $host == 'twitter.com' ) {
                                $img = substr($img, 0, -6);  // delete ':large'
                            }
                            if ( !pathinfo($img, 4) ) {
                                $img .= $this->ext[$type];
                            }
                            $filename = Fct::friendly_url($img);
                            if ( is_file($this->img_dir.$filename) ) {
                                $filename = $key.'_'.$filename;
                            }
                            if ( Fct::secure_save($this->img_dir.$filename, $data) !== false ) {
                                ++$ret;
                                if ( !is_file($this->thumb_dir.$filename) ) {
                                    $this->create_thumb($filename, $width, $height, $type);
                                }
                                $images[$key] = array();
                                $images[$key]['date'] = $pubDate;
                                $images[$key]['link'] = $filename;
                                $images[$key]['guid'] = (string)$item->guid;
                                $images[$key]['docolav'] = $this->docolav($filename, $width, $height, $type);
                                $images[$key]['nsfw'] = $nsfw;
                                // NSFW check, for sensible persons ... =]
                                if ( !$nsfw && !empty($item->category) )
                                {
                                    foreach ( $item->category as $category ) {
                                        if ( strtolower($category) == 'nsfw' )
                                        {
                                            $images[$key]['nsfw'] = true;
                                            break;
                                        }
                                    }
                                }
                                if ( !$images[$key]['nsfw'] )
                                {
                                    $sensible = preg_match('/nsfw/', strtolower((string)$item->title.(string)$item->description));
                                    $images[$key]['nsfw'] = $sensible;
                                }
                            }
                        }
                    }
                }
            }
        }
        $images['date'] = $now;
        Fct::secure_save($output, Fct::serialise($images));
        return $ret;
    }

    /**
     * Check a link syntax.
     * If it seems to be an image or does not finish by a slash, then it
     * seems okay.
     */
    private function link_seems_ok($host, $link)
    {
        if ( $host == $this->current_host ) { return false; }
        if (
            in_array(strtolower(pathinfo($link, 4)), $this->ext_ok) ||
            array_key_exists($host, Solver::$domains)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve the firsts bytes of a resource to check if it is an image.
     */
    private function test_link($link)
    {
        $ret = false;
        $bytes = Fct::load_url($link, Fct::PARTIAL);
        if ( $bytes !== false )
        {
            $sig = substr(bin2hex($bytes), 0, 4);
            if ( $sig == 'ffd8' ) {  // jpeg
                $ret = true;
            }
            elseif ( $sig == '8950' ) {  // png
                $ret = true;
            }
        }
        return $ret;
    }

    /**
     * Compute the dominant color average.
     * http://stackoverflow.com/questions/6962814/average-of-rgb-color-of-image
     */
    private function docolav($file, $width, $height, $type)
    {
        if ( $type == 2 ) {  // jpeg
            $img = imagecreatefromjpeg($this->img_dir.$file);
        } elseif ( $type == 3 ) {  // png
            $img = imagecreatefrompng($this->img_dir.$file);
        } else {
            return '222';
        }

        $tmp_img = ImageCreateTrueColor(1, 1);
        ImageCopyResampled($tmp_img, $img, 0, 0, 0, 0, 1, 1, $width, $height);
        $rgb = ImageColorAt($tmp_img, 0, 0);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b =  $rgb & 0xFF;
        unset($rgb);
        imagedestroy($tmp_img);
        imagedestroy($img);
        return sprintf('%02X%02X%02X', $r, $g, $b);
    }

    /**
     * Callback for uasort().
     * If will do a rsort() with a multi-dimensional array.
     */
    private static function compare_date($a, $b)
    {
        if ( $a['date'] == $b['date'] ) {
            return 0;
        }
        return ( $a['date'] < $b['date'] ) ? 1 : -1;  // invert '1 : -1' for sort()
    }

    /**
     * Generate the JSON file.
     */
    public function generate_json($force = true) {
        if ( !$force ) {
            if ( is_file($this->json_file) ) {
                $diff = date('U') - filemtime($this->json_file);
                if ( $diff < $this->ttl_shaarli ) {
                    return;
                }
            }
        } else {
            usleep(1000000);  // 1 sec
        }

        $images = array();
        $tmp = array();
        foreach ( glob($this->cache_dir.'*.php') as $db )
        {
            $tmp = Fct::unserialise($db);
            $tmp = array_splice($tmp, 1);  // Remove the 'date' key
            foreach ( array_keys($tmp) as $key )
            {
                if ( empty($images[$key]) ) {
                    $images[$key] = $tmp[$key];
                }
                elseif ( $tmp[$key]['date'] < $images[$key]['date'] ) {
                    // Older is better (could be the first to share)
                    $images[$key] = $tmp[$key];
                }
            }
        }
        uasort($images, 'self::compare_date');
        Fct::secure_save($this->database, Fct::serialise($images));

        $lines = "var gallery = [\n";
        $line = "{'key':'%s','src':'%s','w':%d,'h':%d,docolav:'%s','guid':'%s','date':%s,'nsfw':%d},\n";
        foreach ( $images as $key => $data )
        {
            list($width, $height, $type) = getimagesize($this->thumb_dir.$data['link']);
            $lines .= sprintf($line,
                $key, $data['link'],
                $width, $height,
                $data['docolav'],
                $data['guid'],
                $data['date'],
                $data['nsfw']
            );
        }
        $lines .= "];\n";
        Fct::secure_save($this->json_file, $lines);
    }

    /**
     * Create an optimized and progressive JPEG small-szized file
     * from original (big) image.
     */
    public function create_thumb($file, $width, $height, $type)
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
            $source = imagecreatefromjpeg($this->img_dir.$file);
        } else {  // png
            $source = imagecreatefrompng($this->img_dir.$file);
        }
        
        $thumb = imagecreatetruecolor($new_width, $new_height);
        imageinterlace($thumb, $progressive);
        imagecopyresized($thumb, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagejpeg($thumb, $this->thumb_dir.$file, $quality);
        
        imagedestroy($source);
        imagedestroy($thumb);
        
        //~ Fct::__(
            //~ $width.'x'.$height.' = '.filesize($this->img_dir.$file).' | '.
            //~ $new_width.'x'.$new_height.' = '.filesize($this->thumb_dir.$file)
        //~ );
        return array($new_width, $new_height);
    }

    /**
     * Getters.
     */
    public function get_feeds() {
        return $this->feeds['domains'];
    }

    public function get_url($domain) {
        return $this->feeds['domains'][$domain]['url'];
    }

}

?>
