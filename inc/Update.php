<?php

include 'inc/Solver.php';

class Update
{

    /**
     * Shaarlis feed's URL.
     */
    public $feeds = array();


    public function __construct()
    {
        Fct::create_dir(Config::$img_dir, 0755);
        Fct::create_dir(Config::$thumb_dir, 0755);
        Fct::create_dir(Config::$data_dir);
        Fct::create_dir(Config::$cache_dir);
        self::get_opml(is_file(Config::$ompl_file));
    }

    /**
     * Retrieve the OPML file containing all shaarlis feed's.
     */
    private function get_opml($check_for_update = false)
    {
        $need_update = true;
        if ( $check_for_update )
        {
            $this->feeds = Fct::unserialise(Config::$ompl_file);
            $diff = date('U') - $this->feeds['update'];
            if ( $diff < Config::$ttl_opml ) {
                $need_update = false;
            }
        }
        if ( $need_update )
        {
            $data = json_decode(Fct::load_url(Config::$ompl_url), true);
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
                Fct::secure_save(Config::$ompl_file, Fct::serialise($this->feeds));
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
        $output = Config::$cache_dir.Fct::small_hash($domain).'.php';

        if ( is_file($output) ){
            $images = Fct::unserialise($output);
        }
        if ( $now - $images['date'] < Config::$ttl_shaarli ) {
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
                            if ( is_file(Config::$img_dir.$filename) ) {
                                $filename = $key.'_'.$filename;
                            }
                            if ( Fct::secure_save(Config::$img_dir.$filename, $data) !== false ) {
                                ++$ret;
                                if ( !is_file(Config::$thumb_dir.$filename) ) {
                                    Fct::create_thumb($filename, $width, $height, $type);
                                }
                                $images[$key] = array();
                                $images[$key]['date'] = $pubDate;
                                $images[$key]['link'] = $filename;
                                $images[$key]['guid'] = (string)$item->guid;
                                $images[$key]['docolav'] = Fct::docolav($filename, $width, $height, $type);
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
        if ( $host == Config::$current_host ) { return false; }
        if (
            in_array(strtolower(pathinfo($link, 4)), Config::$ext_ok) ||
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
