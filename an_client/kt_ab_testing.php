<?php

include_once 'kt_comm_layer.php';

class AB_Testing_Manager
{
    private $m_backend_api_key;
    private $m_backend_secret_key;
    private $m_ab_backend_host;
    private $m_ab_backend_port;
    private $m_ab_backend;
    private $m_memcached_server;
    private $m_selected_msg_page_pair_dict;
    private static $url_prefix = "/abtest/campaign_info";
    const VO_CUSTOM_VARIABLE_REGEX_STR = '/\{\{(.*?)\}\}/';
    
    public function __construct($kt_api_key,$kt_secret_key,
                                $kt_ab_backend_host,$kt_ab_backend_port=80)
    {
        $this->m_backend_api_key = $kt_api_key;
        $this->m_backend_secret_key = $kt_secret_key;
        
        $this->m_ab_backend_host = $kt_ab_backend_host;
        $this->m_ab_backend_port = $kt_ab_backend_port;

        if( $this->m_ab_backend_port != 80)
            $this->m_ab_backend = $this->m_ab_backend_host.":".$this->m_ab_backend_port;
        else
            $this->m_ab_backend = $this->m_ab_backend_host;
        

        $this->m_memcached_server = new Memcache;
        $this->m_memcached_server->connect('127.0.0.1', 11211); # TODO: don't hardcode
        $this->m_selected_msg_page_pair_dict = array();
    }
                                
    // append a sig to outbound http request to kontagent's AB server
    private function append_sig($url_str, $force)
    {
        $arg_array = array();
        $time_stamp = gmdate("M-d-YTH:i:s");
        $sig = md5("AB_TEST".$time_stamp.$this->m_backend_secret_key);

        if($force)
        {
            $arg_array['f'] = 1;
        }
        $arg_array['ts'] = $time_stamp;
        $arg_array['kt_sig'] = $sig;

        $url_str = $url_str."?".http_build_query($arg_array,'','&');
        return $url_str;
    }

    // validate inbound messages from kontagent.
    private function validate_checksum($json_obj)
    {
        // construct an array.
        $assoc_array = array();
        foreach($json_obj as $k=>$v)
        {
            if($k!="sig")
                $assoc_array[$k] = $v;
        } // foreach
        ksort($assoc_array);
        
        $sig = '';
        foreach( $assoc_array as $k=>$v)
        {
            $sig = $sig.$k.'='.str_replace(" ", "", json_encode($v));
        }
        $sig.=$this->m_backend_secret_key;
        
        if( md5($sig) != $json_obj->sig )
        {
            throw new Exception("Your inbound ab test message from kontagent fails checksum validation");
        }
    }
        
    // if $force is set to be true, it will grab the campaign contents even if the changed flag is
    // set to be false.
    public function fetch_ab_testing_data($campaign, $force=false)
    {
        $url_str = $this->m_ab_backend.self::$url_prefix."/".$this->m_backend_api_key."/".$campaign. "/";
        $url_str = $this->append_sig($url_str, $force);
        
        $sock = fopen( $url_str , 'r' );
        $r = null;
        
        $url_result = "";
        if ($sock){
            while (!feof($sock)) {
                $url_result .=fgets($sock, 4096);
            }
            fclose($sock);

            $json_obj = json_decode($url_result);
            
            if( $json_obj->changed )
            {
                $this->validate_checksum($json_obj);

                if(isset($json_obj->page_and_messages))
                {
                    // process mesage and page "together" for feed related campaigns
                    $page_msg_lst = $json_obj->page_and_messages;
                    $weight_array = array();
                    $curr_idx = 0;
                    foreach($page_msg_lst as $pm)
                    {
                        $weight = $pm[1];
                        for($i = 0; $i< $weight; $i++)
                        {
                            $weight_array[] = $curr_idx;
                        }
                        $curr_idx++;
                    }
                    $store_dict = array();
                    $store_dict['json'] = $json_obj;
                    $store_dict['weight'] = $weight_array;
                }
                else
                {
                    // process message list
                    $msg_lst = $json_obj->messages;
                    $msg_weight_array = array();
                    $curr_idx = 0;
                    foreach($msg_lst as $msg)
                    {
                        $weight = $msg[1];
                        for($i = 0; $i< $weight; $i++)
                        {
                            $msg_weight_array[] = $curr_idx;
                        }
                        $curr_idx++;
                    }
                    // process page list
                    $page_lst = $json_obj->pages;
            
                    $page_weight_array = array();
                    $curr_idx = 0;
                    foreach($page_lst as $page)
                    {
                        $weight = $page[1];
                        for($i=0; $i<$weight; $i++)
                        {
                            $page_weight_array[] = $curr_idx;
                        }
                        $curr_idx++;
                    }
                    $store_dict = array();
                    $store_dict['json'] = $json_obj;
                    $store_dict['msg_weight'] = $msg_weight_array;
                    $store_dict['page_weight'] = $page_weight_array;
                }

                $r = $store_dict;                
                $this->m_memcached_server->set($this->gen_memcache_key($campaign) , serialize($store_dict), MEMCACHE_COMPRESSED, 0);
                $this->m_memcached_server->set($this->gen_memcache_fake_key($campaign), 1, MEMCACHE_COMPRESSED, 300); // 5 mins
            }
            else
            {
                $this->m_memcached_server->set($this->gen_memcache_fake_key($campaign), 1, MEMCACHE_COMPRESSED, 300); // 5 mins
            }
                
        }//if ($sock)
        return $r;
    }
    
    private function gen_memcache_fake_key($campaign)
    {
        return "kt_".$this->m_backend_api_key."_".$campaign."_fake";
    }

    private function gen_memcache_key($campaign)
    {
        return "kt_".$this->m_backend_api_key."_".$campaign;
    }
        
    private function get_ab_helper($campaign)
    {
        $fake_key_is_valid = $this->m_memcached_server->get($this->gen_memcache_fake_key($campaign));
        
        if($fake_key_is_valid == false)
        {
            // The real key should have a valid json object.
            // If not, invoke fetch_ab_testin_data with force = true
            $serialized_campaign_str = $this->m_memcached_server->get($this->gen_memcache_key($campaign));
            if( $serialized_campaign_str == false )
            {
                $dict = $this->fetch_ab_testing_data($campaign, true); // force it
            }
            else
            {
                $dict = $this->fetch_ab_testing_data($campaign);
            }
        }
        else
        {
            // Likewise, the real key should have a valid json object.
            // If not, invoke fetch_ab_testin_data with force = true
            $serialized_campaign_str = $this->m_memcached_server->get($this->gen_memcache_key($campaign));
            if( $serialized_campaign_str == false)
            {
                $dict = $this->fetch_ab_testing_data($campaign, true); // force it
            }
            else
            {
                // has a valid json object. deserialize it.
                $dict = unserialize($serialized_campaign_str);
            }
        }
        return $dict;
    }
        
    public function get_ab_testing_campaign_handle_index($campaign)
    {
        $dict = $this->get_ab_helper($campaign);
        $json_obj = $dict['json'];
        return $json_obj->handle_index;
    }
    
    // Use case: let message be "Bob scores {{score}} in {{game}}"
    // $data_assoc_array : {"score" => 10, "game" => "chess"}
    // 
    public function get_ab_testing_message($campaign)
    {
        $dict = $this->get_ab_helper($campaign);
        if($dict == null)
        {
            return null;
        }
        else{
            $json_obj = $dict['json'];
            $msg_lst = $json_obj->messages;
            $weight_array = $dict['msg_weight'];
            $index = $weight_array[rand(0, count($weight_array)-1)];
            return $msg_lst[$index];
        }
    }
    
    public function get_ab_testing_page($campaign)
    {
        $dict = $this->get_ab_helper($campaign);
        if($dict == null)
        {
            return null;
        }
        else
        {
            $json_obj = $dict['json'];
            $page_lst = $json_obj->pages;
            $weight_array = $dict['page_weight'];
            $index = $weight_array[rand(0, count($weight_array)-1)];
            return $page_lst[$index];
        }
    }

    // used by feeds related calls
    public function get_ab_testing_page_msg_tuple($campaign)
    {
        $dict = $this->get_ab_helper($campaign);
        if($dict == null)
        {
            return null;
        }
        else
        {
            $json_obj = $dict['json'];
            $page_msg_lst = $json_obj->page_and_messages;
            $weight_array = $dict['weight'];
            $index = $weight_array[rand(0, count($weight_array)-1)];
            return $page_msg_lst[$index];
        }
    }
        
    public function are_page_message_coupled($campaign)
    {
        $dict = $this->get_ab_helper($campaign);
        if($dict == null)
        {
            return false;
        }
        else
        {
            $json_obj = $dict['json'];
            if(isset($json_obj->page_and_messages))
                return true;
            else
                return false;
        }
    }
        
    // used by notifications, invites, etc.
    public function cache_ab_testing_msg_and_page($campaign, $msg_info, $page_info)
    {
        $dict = array();
        $dict['page'] = $page_info;
        $dict['msg']  = $msg_info;
        $this->m_selected_msg_page_pair_dict[$campaign] = $dict;
    }

    // used by feeds related calls
    public function cache_ab_testing_msg_page_tuple($campaign, $page_msg_info)
    {
        $dict = array();
        $dict['page_msg'] = $page_msg_info;
        $this->m_selected_msg_page_pair_dict[$campaign] = $dict;
    }
    
    public function get_selected_page_msg_info($campaign)
    {
        return $this->m_selected_msg_page_pair_dict[$campaign]['page_msg'];
    }
    
    public function get_selected_msg_info($campaign, $custom_data=null)
    {
        $r = $this->m_selected_msg_page_pair_dict[$campaign]['msg'];
        $r[2] = $this->replace_vo_custom_variable($r[2], $custom_data);
        return $r;
    }

    public function get_selected_page_info($campaign, $custom_data=null)
    {
        $r = $this->m_selected_msg_page_pair_dict[$campaign]['page'];
        $r[2] = $this->replace_vo_custom_variable($r[2], $custom_data);
        return $r;
    }

    // Use case: let message be "Bob scores {{score}} in {{game}}"
    // $data_assoc_array : {"score" => 10, "game" => "chess"}
    // returns Bob scores 10 in chess.
    private function replace_vo_custom_variable($text, $data_assoc_array)
    {
        preg_match_all(self::VO_CUSTOM_VARIABLE_REGEX_STR, $text, $matches);
        $r = null;
        if(sizeof($matches[0]) == 0 && sizeof($matches[1]) == 0)
        {
            // nothing to replace
            $r =  $text;
        }
        else
        {
            $replaced_val_array = array();
            for($i=0; $i<sizeof($matches[1]); $i++)
            {
                $varname = $matches[1][$i];
                if(isset($data_assoc_array[$varname]))
                {
                    $replaced_val_array[] = $data_assoc_array[$varname];
                }
                else
                {
                    // throw a kontagent exception
                    throw new Exception($varname . " is not defined in data_assoc_array.");
                }
            }// for
            $r = str_replace($matches[0], $replaced_val_array, $text);
        }
        return $r;        
    }
}

//testing code
//$ab_testing_mgr = new AB_Testing_Manager('0acd88e74d8d4f228f3e07a39e7f8b67', 'dadaf96551f1434ab98f0877fba19723',
//                                         'http://kthq.dyndns.org', 9999);

////////// TEST CASE /////////////////
//$page_msg_info =  $ab_testing_mgr->get_ab_testing_page_msg_tuple('feed_story');
//$ab_testing_mgr->cache_ab_testing_msg_page_tuple('feed_story', $page_msg_info);
//var_dump($ab_testing_mgr->get_selected_page_msg_info('feed_story'));
////////// END: TEST CASE /////////////////

//print $ab_testing_mgr->are_page_message_coupled('feed_story');
//print $ab_testing_mgr->are_page_message_coupled('notif');



//echo $ab_testing_mgr->get_message('hello')."\n";

//echo $ab_testing_mgr->get_page($campaign)."\n";

?>