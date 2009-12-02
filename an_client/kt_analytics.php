<?php

// Kontagent an_client lib version KONTAGENT_VERSION_NUMBER
include_once 'kt_comm_layer.php';

class Analytics_Utils
{
    private static $s_undirected_types = array('ad'=>'ad', 'ap'=>'ap');
    private static $s_profile_types = array('profilebox'=>'profilebox',
                                            'profileinfo'=>'profileinfo');
    private static $s_directed_types = array('in'=>'in', 'nt'=>'nt', 'nte'=>'nte', 'feedpub'=>'feedpub',
                                             'feedstory'=>'feedstory', 'multifeedstory'=>'multifeedstory');

    private static $s_kt_args = array('kt_uid'=>1,
                                      'kt_d'=>1,
                                      'kt_type'=>1,
                                      'kt_ut'=>1,
                                      'kt_t'=>1,
                                      'kt_st1'=>1,
                                      'kt_st2'=>1,
                                      'kt_st3'=>1,
                                      'kt_owner_uid'=>1);
    
    private static $s_install_args = array('d'=>1,
                                           'ut'=>1,
                                           'installed'=>1,
                                           'sut'=>1);
       

    const directed_val = 'd';
    const undirected_val = 'u';    
    const profile_val = 'p';    
    const URL_REGEX_STR_NO_HREF = '/https?:\/\/[^\s>\'"]+/';
    const URL_REGEX_STR = '/(href\s*=.*?)(https?:\/\/[^\s>\'"]+)/';
    const VO_PARAM_REGEX_STR = '/\{\*KT_AB_MSG\*\}/';
    const ESC_URL_UT_REGEX_STR = '/(ut%.*?)%/';
    const ESC_URL_SUT_REGEX_STR = '/(sut%.*?)%/';

    public $m_backend_api_key;
    private $m_backend_secret_key;
    private $m_backend_url;
    private $m_backend_host;
    private $m_local_req_uri;
    private $m_canvas_url;
    private $m_aggregator;
    private $m_invite_uuid;
    private $m_invite_message_info;
    
    // temporary variables for feed_publishUserAction to pass values to replace_kt_comm_link_helper
    private $m_template_bundle_id_tmp;
    private $m_st1_tmp;
    private $m_st2_tmp;
    private $m_st3_tmp;
    private $m_query_str_tmp;
    private $m_msg_text_tmp;

    public $m_ab_testing_mgr;
    
    
    private function __construct($kt_api_key,$kt_secret_key,
                                 $kt_backend_host,$kt_backend_port,$kt_backend_url, 
                                 $canvas_url,
                                 $local_req_uri){
        $this->m_backend_api_key = $kt_api_key;
        $this->m_backend_secret_key = $kt_secret_key;
        $this->m_backend_url = $kt_backend_url;
        $this->m_local_req_uri = $local_req_uri;
        $this->m_canvas_url = $canvas_url;
        $this->m_aggregator = new Kt_Comm($kt_backend_host, $kt_backend_port);        
        $this->m_invite_uuid = 0;
        $this->m_invite_message_info = null;
        $this->m_backend_host = $kt_backend_host;
        $this->m_backend_port = $kt_backend_port;
    }

    public function set_ab_testing_mgr($ab_testing_mgr)
    {
        $this->m_ab_testing_mgr = $ab_testing_mgr;
    }
    
    public function override_backend_host($kt_backend_host, $kt_backend_port)
    {
        $this->m_backend_host = $kt_backend_host;
        $this->m_aggregator = new Kt_Comm($kt_backend_host, $kt_backend_port);
    }
    
    public static function &instance($kt_api_key,$kt_secret_key,
                                     $kt_backend_host,$kt_backend_port,$kt_backend_url,
                                     $canvas_url,
                                     $local_req_uri){
        static $instance;
        
        if(!isset($instance))
        {
            $instance = new Analytics_Utils($kt_api_key,$kt_secret_key,
                                            $kt_backend_host,$kt_backend_port,$kt_backend_url,
                                            $canvas_url,
                                            $local_req_uri);
        }
        return $instance;
    }    
    
    private function is_directed_type($type){
        if (isset(self::$s_directed_types[$type]))
            return true;
        return false;
    }

    public function is_undirected_type($type){
        if (isset(self::$s_undirected_types[$type]))
            return true;
        return false;
    }    

    private function is_profile_type($type){
        if (isset(self::$s_profile_types[$type]))
            return true;
        return false;
    }
    
    // Invoke this function with the fb_sig_* but excludes the fb_sig_ prefix.
    // For example, for fb_sig_user, pass in "user" as the $param_name argument.
    // If it's an iframe application, it will switch to use FACEBOOK_API_KEY . "_user"
    public function get_fb_param($param_name){
        $r = 0;
        global $kt_facebook;

        if( isset($_REQUEST['fb_sig_'.$param_name]) )
        {
            $r = $_REQUEST['fb_sig_'.$param_name];
        }
        else if( isset($_REQUEST[$kt_facebook->api_key."_".$param_name]) )
        {
            $r = $_REQUEST[$kt_facebook->api_key."_".$param_name];
        }
        else
        {
            if($param_name == 'user')
            {
                if ( isset($_REQUEST['fb_sig_canvas_user']) )
                {
                    $r = $_REQUEST['fb_sig_canvas_user'];
                }
                else
                {
                    // No way of getting to the uid with an unauthorized iframe app.
                    // So, check to make sure that KT_USER is set.
                    if(isset($_COOKIE["KT_USER"]))
                        $r = $_COOKIE["KT_USER"];
                }
            }
        }
        
        return $r;
    }

    private function gen_ut_cookie_key(){
        return $this->m_backend_api_key."_ut";
    }

    public function gen_sut_cookie_key(){
        return $this->m_backend_api_key."_sut";
    }

    public function gen_ru_cookie_key(){
        return $this->m_backend_api_key."_ru";
    }
    
    // if $uuid is provided, then it doesn't generate a new one (directed comm)
    private function gen_kt_comm_query_str($comm_type, $template_id, $subtype1, $subtype2, $subtype3, &$ret_str,
                                           $uuid_arg=null, $uid=null){
        $param_array = array();
        $dir_val;       
        $uuid = 0;

        if($comm_type != null){
            if ($this->is_directed_type($comm_type)){
                $dir_val = Analytics_Utils::directed_val;
            }
            else if($this->is_undirected_type($comm_type)){
                $dir_val = Analytics_Utils::undirected_val;
            }
            else if($this->is_profile_type($comm_type)){
                $dir_val = Analytics_Utils::profile_val;
            }
        }       
        
        //$param_array['kt_d'] = $dir_val; // deprecated
        $param_array['kt_type'] = $comm_type;

        if(isset($dir_val))
        {
            if($dir_val == Analytics_Utils::directed_val){
                if(!isset($uuid_arg))
                {
                    $uuid = $this->gen_long_uuid();
                    $param_array['kt_ut'] = $uuid;
                }
                else
                {
                    $param_array['kt_ut'] = $uuid_arg;
                }
            }
            else if($dir_val == Analytics_Utils::profile_val){
                if(!isset($uid))
                {
                    $uid = $this->get_fb_param('user');
                }
                $param_array['kt_owner_uid'] = $uid;
            }
        }
            
        if($template_id != null){
            $param_array['kt_t'] = $template_id;
        }
      
        if($subtype1 != null){
            $param_array['kt_st1'] = $subtype1;
        }
        if($subtype2 != null){
            $param_array['kt_st2'] = $subtype2;
        }
        if($subtype3 != null){
            $param_array['kt_st3'] = $subtype3;
        }
        
        $ret_str = http_build_query($param_array, '', '&');
        return $uuid;
    }
    
    private function gen_kt_comm_link(&$input_txt, $comm_type, $template_id, $subtype1, $subtype2)
    {
        // This is here so it knows the fb namespace. Plus, it turns into a well formed XML.
        //$input_txt = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:fb="http://apps.facebook.com/ns/1.0" targetNamespace="http://apps.facebook.com/ns/1.0" elementFormDefault="qualified" attributeFormDefault="unqualified">'.$this->xmlentities($input_txt).'</xs:schema>';

        $query_str;
        $uuid = $this->gen_kt_comm_query_str($comm_type, $template_id, $subtype1, $subtype2, null, $query_str);
        $this->m_query_str_tmp = $query_str;

        $input_txt = preg_replace_callback(self::URL_REGEX_STR,
                                           array($this, 'replace_kt_comm_link_helper_directed'),
                                           $input_txt);
        
        return $uuid;
    }

    private function gen_kt_comm_link_vo(&$input_txt, $comm_type, $subtype1, $subtype2, $subtype3)
    {
        $query_str;
        $uuid = $this->gen_kt_comm_query_str($comm_type, null, $subtype1, $subtype2, $subtype3, $query_str);
        $this->m_query_str_tmp = $query_str;
        
        $input_txt = preg_replace_callback(self::URL_REGEX_STR,
                                           array($this, 'replace_kt_comm_link_helper_directed'),
                                           $input_txt);
        
        return $uuid;
        
    }
        
    /*
    private function xmlentities ( $string )
    {
        $arry =  split('&', $string);
        $len = sizeof($arry);
        $r_str = null;

        if($len == 0)
        {
            // no & characters.
            $r_str = $string;
        }
        else
        {
            $r_str = $arry[0];
            for($i = 1; $i < $len; $i++)
            {
                $curr_str = $arry[$i];
               
                $str_len = strlen($curr_str);
                if($str_len > 0)
                {
                    if($str_len < 3)
                    {
                        $r_str.='&amp;';
                    }
                    else
                    {
                                              
                        $comp_str0 = substr($curr_str,0,3);
                        $comp_str1 = null;
                        $comp_str2 = null;
                       
                        if($str_len >= 4)
                        {
                            $comp_str1 = substr($curr_str,0,4);
                        }
                        if($str_len >= 5)
                        {
                            $comp_str2 = substr($curr_str,0,5);
                        }
                       
                        if($comp_str0 == 'lt;' || $comp_str0 == 'gt;')
                        {
                            $r_str.='&'.$curr_str;
                        }
                        else
                        {
                            if($comp_str1 != null)
                            {
                                if($comp_str1 == 'amp;')
                                {
                                    $r_str.='&'.$curr_str;
                                }
                                else
                                {
                                    if($comp_str2 != null)
                                    {
                                        if($comp_str2 == 'apos;' || $comp_str2 == 'quot;')
                                        {
                                            $r_str.='&'.$curr_str;
                                        }
                                        else
                                        {
                                            $r_str.='&amp;'.$curr_str;
                                        }
                                    }
                                    else
                                    {
                                        $r_str.='&amp;'.$curr_str;
                                    }
                                }
                            }
                            else
                            {
                                $r_str.='&amp;'.$curr_str;
                            }
                        }
                    }
                }
                else
                {
                    $r_str.='&amp;';
                }
            }
        }
       
        return $r_str;
        }*/

    private function replace_kt_comm_link_helper_directed($matches)
    {
        return $matches[1].$this->append_kt_query_str($matches[2], $this->m_query_str_tmp);
    }
    
    // Deprecated
    /*
    private function replace_kt_comm_link_helper_undirected($matches)
    {
        return $this->replace_kt_comm_link_helper_undirected_impl('fdp', $matches[0]);
    }
    */
    
    // Deprecated
    /*
    private function replace_kt_comm_link_helper_undirected_impl($kt_type, $input_str)
    {
        $query_str;
        $this->gen_kt_comm_query_str($kt_type,
                                     $this->m_template_bundle_id_tmp,
                                     $this->m_st1_tmp,
                                     $this->m_st2_tmp,
                                     $this->m_st3_tmp,
                                     $query_str);

        return $this->append_kt_query_str($input_str, $query_str);
    }
    */

    // TODO: can I consolidate replace_kt_comm_link_helper_feedpub and replace_kt_comm_link_helper_feedstory?
    private function replace_kt_comm_link_helper_feedpub($matches)
    {
        if(is_array($matches))
            return $this->append_kt_query_str($matches[0], $this->m_query_str_tmp);
        else if(is_string($matches))
            return $this->append_kt_query_str($matches, $this->m_query_str_tmp);
    }
    private function replace_kt_comm_link_helper_feedstory($matches)
    {
        if(is_array($matches))
            return $this->append_kt_query_str($matches[0], $this->m_query_str_tmp);
        else if(is_string($matches))
            return $this->append_kt_query_str($matches, $this->m_query_str_tmp);
    }
    private function replace_kt_comm_link_helper_multifeedstory($matches)
    {
        if(is_array($matches))
            return $this->append_kt_query_str($matches[0], $this->m_query_str_tmp);
        else if(is_string($matches))
            return $this->append_kt_query_str($matches, $this->m_query_str_tmp);
    }

    
    private function fill_message_with_ab_message($matches)
    {
        return $this->m_msg_text_tmp;
    }
        
    private function gen_kt_comm_link_templatized_data(&$input_txt, $comm_type, $template_id, $subtype1, $subtype2)
    {
        $data_arry = json_decode($input_txt, true);
       
        if($data_arry != null)
        {
            foreach( $data_arry as $key => $value)
            {
                $new_value = preg_replace_callback(self::URL_REGEX_STR_NO_HREF,
                                                   array($this, 'replace_kt_comm_link_helper_undirected'),
                                                   $value);
                $data_arry[$key] = $new_value;
            }
            $input_txt = json_encode($data_arry);
        }
    }
    
    private function append_kt_query_str($original_url, $query_str)
    {
        $position = strpos($original_url, '?');
        
        /* There are no query params, just append the new one */
        if ($position === false) {
            return $original_url.'?'.$query_str;
        }
        
        /* Prefix the params with the reference parameter */
        $noParams                   = substr($original_url, 0, $position + 1);
        $params                     = substr($original_url, $position + 1);
        return $noParams.$query_str.'&'.$params;
    }
        
    private function an_send_user_data($user_id, $birthday=null, $gender=null, $cur_city = null,
                                       $cur_state = null, $cur_country = null, $cur_zip = null,
                                       $home_city = null, $home_state = null, $home_country = null, $home_zip = null,
                                       $num_of_friends = null){

        $user_data = array();
        $user_data['s'] = $user_id;
      
        if (isset($birthday) && $birthday != ''){
            $tmp_array = explode(',',$birthday);
            if(count($tmp_array) == 2)
                $user_data['b'] = urlencode(trim($tmp_array[1]));
            else
                $user_data['b'] = urlencode('');
        }
        if (isset($gender)){
            $user_data['g'] = urlencode(strtoupper($gender));
        }

        // Only allow a single entry for city, state, country, and zip in the Capture User Info message,
        // not separate ones for each of them for hometown and current. When fetching data from facebook,
        // get both sets of values and, when available, use the current ones, but use the hometown ones
        // if the current values are blank.
        $use_hometown_info = true;
      
        if (isset($cur_city)){
            $user_data['ly'] = $cur_city;
            $use_hometown_info = false;
        }
        if (isset($cur_state)){
            $user_data['ls'] = $cur_state;
            $use_hometown_info = false;
        }
        if (isset($cur_country)){
            $user_data['lc'] = $cur_country;
            $use_hometown_info = false;
        }
        if (isset($cur_zip)){
            $user_data['lp'] = $cur_zip;
            $use_hometown_info = false;
        }

        if($use_hometown_info == true){
            if (isset($home_city)){
                $user_data['ly'] = $home_city;
            }
            if (isset($home_state)){
                $user_data['ls'] = $home_state;
            }
            if (isset($home_country)){
                $user_data['lc'] = $home_country;
            }
            if (isset($home_zip)){
                $user_data['lp'] = $home_zip;
            }
        }
      
        if (isset($num_of_friends)){
            $user_data['f'] = $num_of_friends;
        }

        $this->m_aggregator->api_call_method($this->m_backend_url, "v1", $this->m_backend_api_key,
                                             $this->m_backend_secret_key,
                                             'cpu',
                                             $user_data); //cpu stands for capture user
    }

    private function an_app_remove($uid){
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1", $this->m_backend_api_key,
                                             $this->m_backend_secret_key,
                                             "apr",
                                             array('s'=>$uid));
    }
    
    private function an_app_added_directed($uid, $long_uuid){
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1", $this->m_backend_api_key,
                                             $this->m_backend_secret_key,
                                             "apa",
                                             array('s'=>$uid,
                                                   'u'=>$long_uuid));
    }

    private function an_app_added_undirected($uid, $short_uuid){
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1", $this->m_backend_api_key,
                                             $this->m_backend_secret_key,
                                             "apa",
                                             array('s'=>$uid,
                                                   'su'=>$short_uuid));
    }

    private function an_app_added_profile($uid, $owner_uid){
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1", $this->m_backend_api_key,
                                             $this->m_backend_secret_key,
                                             "apa",
                                             array('s'=>$uid,
                                                   'ru'=>$owner_uid));
    }
    

    private function an_app_added_nonviral($uid){
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1", $this->m_backend_api_key,
                                             $this->m_backend_secret_key,
                                             "apa",
                                             array('s'=>$uid));
    }

    private function an_notification_click($has_been_added, $uuid, $template_id, $subtype1, $subtype2, $subtype3, $recipient_uid = null)
    {
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "ntr",
                                             array('r' => $recipient_uid,
                                                   'i' => $has_been_added,
                                                   'u' => $uuid,
                                                   'tu' => 'ntr',
                                                   't' => $template_id,
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3));
    }
    
    private function an_notification_email_click($has_been_added, $uuid, $template_id, $subtype1, $subtype2, $subtype3, $recipient_uid = null){
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "nei",
                                             array('r' => $recipient_uid,
                                                   'i' => $has_been_added,
                                                   'u' => $uuid,
                                                   'tu' => 'nei',
                                                   't' => $template_id,
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3));
    }

    private function an_invite_send($sender_uid, $recipient_uid_arry, $uuid, $invite_template_id = null, $subtype1 = null, $subtype2 = null, $subtype3 = null){
       $param_array = array('s' => $sender_uid,
                            'u' => $uuid);

       if(is_array($recipient_uid_arry))
           $param_array['r'] = join(',',$recipient_uid_arry);

       if(isset($invite_template_id))
           $param_array['t'] = $invite_template_id;
       if(isset($subtype1))
           $param_array['st1'] = $subtype1;
       if(isset($subtype2))
           $param_array['st2'] = $subtype2;
       if(isset($subtype3))
           $param_array['st3'] = $subtype3;
           
       $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                            $this->m_backend_api_key, $this->m_backend_secret_key,
                                            "ins",
                                            $param_array);
   }

    private function an_invite_click($has_been_added, $uuid, $template_id=null, $subtype1=null, $subtype2=null, $subtype3=null, $recipient_uid = null){
       
       $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                            $this->m_backend_api_key, $this->m_backend_secret_key,
                                            "inr",
                                            array('r' => $recipient_uid,
                                                  'i' => $has_been_added,
                                                  'u' => $uuid,
                                                  'tu' => 'inr',
                                                  't' => $template_id,
                                                  'st1' => $subtype1,
                                                  'st2' => $subtype2,
                                                  'st3' => $subtype3));
   }

    private function an_stream_click($has_been_added, $uuid, $template_id=null, $subtype1=null, $subtype2=null, $subtype3=null, $recipient_uid = null)
    {
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "psr",
                                             array('r' => $recipient_uid,
                                                   'i' => $has_been_added,
                                                   'u' => $uuid,
                                                   'tu' => 'stream',
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3));
    }
    
    private function an_feedstory_click($has_been_added, $uuid, $template_id=null, $subtype1=null, $subtype2=null, $subtype3=null, $recipient_uid = null)
    {
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "psr",
                                             array('r' => $recipient_uid,
                                                   'i' => $has_been_added,
                                                   'u' => $uuid,
                                                   'tu' => 'feedstory',
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3));
    }
    
    private function an_multifeedstory_click($has_been_added, $uuid, $template_id=null, $subtype1=null, $subtype2=null, $subtype3=null, $recipient_uid = null)
    {
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "psr",
                                             array('r' => $recipient_uid,
                                                   'i' => $has_been_added,
                                                   'u' => $uuid,
                                                   'tu' => 'multifeedstory',
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3));        
    }

    private function an_profilebox_click($has_been_added, $subtype1, $subtype2, $subtype3, $owner_uid, $clicker_uid)
    {
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "psr",
                                             array('r' => $clicker_uid,
                                                   's'  => $owner_uid,
                                                   'i' => $has_been_added,
                                                   'tu' => 'profilebox',
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3
                                                   ));
    }

    private function an_profileinfo_click($has_been_added, $subtype1, $subtype2, $subtype3, $owner_uid, $clicker_uid)
    {
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "psr",
                                             array('r' => $clicker_uid,
                                                   's'  => $owner_uid,
                                                   'i' => $has_been_added,
                                                   'tu' => 'profileinfo',
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3
                                                   ));
    }
    
    
    private function an_feedpub_click($has_been_added, $uuid, $template_id=null, $subtype1=null, $subtype2=null, $subtype3=null, $recipient_uid = null)
    {
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "psr",
                                             array('r' => $recipient_uid,
                                                   'i' => $has_been_added,
                                                   'u' => $uuid,
                                                   'tu' => 'feedpub',
                                                   't' => $template_id,
                                                   'st1' => $subtype1,
                                                   'st2' => $subtype2,
                                                   'st3' => $subtype3));
    }

    private function an_app_undirected_comm_click($uid, $type, $template_id, $subtype1, $subtype2, $subtype3, $has_added, $short_tag){
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "ucc",
                                             array('s'=>$uid,
                                                   'tu'=>$type,
                                                   't'=>$template_id,
                                                   'st1'=>$subtype1,
                                                   'st2'=>$subtype2,
                                                   'st3'=>$subtype3,
                                                   'i'=>$has_added,
                                                   'su'=>$short_tag));
    }
    
    private function an_monetization_increment($uid, $money_value){
        $param_array = array();
        if(is_array($uid))
            $param_array['s'] = join(',',$uid);
        else
            $param_array['s'] = $uid;
        $param_array['v'] = $money_value;
        
        $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             "mtu",
                                             $param_array);
    }
    
    private function an_goal_count_increment($uid, $goal_counts){
       $param_array = array();
       foreach ($goal_counts as $key => $value)
           $param_array['gc'.$key] = $value;
       if(is_array($uid))
           $param_array['s'] = join(',',$uid);
       else
           $param_array['s'] = $uid;

       $this->m_aggregator->api_call_method($this->m_backend_url, "v1",
                                            $this->m_backend_api_key, $this->m_backend_secret_key,
                                            "gci",
                                            $param_array);
   }
   
   
   public function get_stripped_installed_arg_url()
   {
       $param_array = array();
       foreach($_GET as $arg => $val)
       {
           if(!isset(self::$s_install_args[$arg]))
           {
               $param_array[$arg] = $val;
           }
       }       
       return $this->build_stripped_url($param_array);
   }
   
   // After done processing the kt_params (see konttagent.php), this function will be invoked to stripped all the
   // kt_* parameters. Why bother? First, it prevents erroneous processing after authorization. Second, prettier url.
   // Third, no need to deal with refreshing the url with kt_params in it.
   // Pass ids along. Since we are doing a redirect after done handling kt params,
   // we need to forward the ids array for invite_send event.   
   // 
   // Saves kt_ut or sut as <kt_api_key>_ut and <kt_api_key>_sut, respectively, in the cookie, so that we can
   // handle apps that don't force its users to install the apps immediately.
   private function get_stripped_kt_args_url($short_tag=null, $ids_array=null)
   {
       $param_array = array();

       foreach($_GET as $arg => $val)
       {
           if (!isset(self::$s_kt_args[$arg]))
           {
               $param_array[$arg] = $val;
           }
           else
           {
               if($arg == 'kt_d')
               {
                   $param_array['d'] = $val;
               }
               else if($arg == 'kt_ut')
               {
                   $param_array['ut'] = $val;
                   setcookie($this->gen_ut_cookie_key(), $val, time()+600); // 10 minutes
               }
               else if( $arg == 'kt_owner_uid' )
               {
                   setcookie($this->gen_ru_cookie_key(), $val, time()+600); 
               }
           }
       }

       if($short_tag != null)
       {
           $param_array['sut'] = $short_tag;
           setcookie($this->gen_sut_cookie_key(), $short_tag, time()+600);
       }

       if($ids_array != null)
       {
           $param_array['ids'] = $ids_array;
       }

       return $this->build_stripped_url($param_array);
   }

   private function build_stripped_url($param_array)
   {
       // get the script name only minus the call_back_uri
       $script_uri = null;
       if(isset($_SERVER['SCRIPT_URI']))
           $script_uri = $_SERVER['SCRIPT_URI'];
       else if(isset($_SERVER['PHP_SELF']))
           $script_uri = $_SERVER['PHP_SELF'];
           
       if($script_uri != null)
       {
           if(preg_match("@".$this->m_local_req_uri."(.*)@", $script_uri, $matches))
           {
               $script_name = $matches[1];
           }
           else
           {
               $script_name = $script_uri;
           }

           // if there are slashes around the script_name (/index.php/), strip the one in the front.
           if($script_name[0] == "/")
               $script_name = substr($script_name, 1);
       
       
           $len = strlen($this->m_canvas_url);
                        
           if( $this->m_canvas_url[$len-1] == "/")
           {
               return $this->m_canvas_url.$script_name."?".http_build_query($param_array, '', '&');
           }
           else
           {
               return $this->m_canvas_url."/".$script_name."?".http_build_query($param_array, '', '&');
           }
       }
       else
           return null;
   }
   
   public function get_page_tracking_url() {
       if( $this->m_backend_port != 80 )
           $url = "http://" . $this->m_backend_host.":".$this->m_backend_port;
       else
           $url = "http://" . $this->m_backend_host;

       global $kt_facebook;
       $uid = $this->get_fb_param('user');
       
       $url .= $this->m_aggregator->get_call_url(
           $this->m_backend_url, 
           "v1", 
           $this->m_backend_api_key,
           $this->m_backend_secret_key,
           "pgr",
           array('s' => $uid)
                                                 );
       
       return $url;
   }

   public function gen_long_uuid(){
        return substr(uniqid(rand()),  -16);
    }

    public function gen_short_uuid(){
        $t=explode(" ",microtime());
        $a = $t[1];
        $b = round($t[0]*mt_rand(0,0xfffff));
        
        $c = mt_rand(0,0xfffffff);
        $tmp_binary = base_convert($c, 10, 2);
        $c = $c << (8 - strlen($tmp_binary));
      
        return dechex($a ^ $b ^ $c);
    }

    public function gen_notifications_link_vo(&$notification, $msg_text, $subtype1, $subtype2, $subtype3)
    {
        $this->m_msg_text_tmp = $msg_text;
        $notification = preg_replace_callback(self::VO_PARAM_REGEX_STR,
                                              array($this,  'fill_message_with_ab_message'),
                                              $notification);
        return $this->gen_kt_comm_link_vo($notification, 'nt', $subtype1, $subtype2, $subtype3);
    }
    
    public function gen_notifications_link(&$notification, $template_id = null, $subtype1 = null, $subtype2 = null)
    {
        return $this->gen_kt_comm_link($notification, 'nt', $template_id, $subtype1, $subtype2); 
    }

    public function gen_email_link(&$email_fbml, $template_id = null, $subtype1 = null, $subtype2 = null)
    {
        return $this->gen_kt_comm_link($email_fbml, 'nte', $template_id, $subtype1, $subtype2); 
    }

    public function gen_email_link_vo(&$email_fbml, $msg_text, $subtype1 = null, $subtype2 = null, $subtype3=null)
    {
        $this->m_msg_text_tmp = $msg_text;
        $email_fbml = preg_replace_callback(self::VO_PARAM_REGEX_STR,
                                            array($this,  'fill_message_with_ab_message'),
                                            $email_fbml);
        return $this->gen_kt_comm_link_vo($email_fbml, 'nte', $subtype1, $subtype2, $subtype3);
    }
        
    public function gen_profile_setFBML_link(&$profile_fbml, $subtype1=null, $subtype2=null, $owner_id=null)
    {
        $query_str;
        $uuid = $this->gen_kt_comm_query_str('profilebox', null, $subtype1, $subtype2, null, $query_str, null, $owner_id);
        $this->m_query_str_tmp = $query_str;

        $profile_fbml = preg_replace_callback(self::URL_REGEX_STR,
                                              array($this, 'replace_kt_comm_link_helper_directed'),
                                              $profile_fbml);
        return $uuid;
    }

    public function gen_profile_setInfo(&$info_fields, $owner_id=null, $subtype1=null, $subtype2=null)
    {
        $query_str;
        $uuid = $this->gen_kt_comm_query_str('profileinfo', null, $subtype1, $subtype2, null, $query_str, null, $owner_id);
        //$this->m_query_str_tmp = $query_str;

        for( $i=0 ;  $i < sizeof($info_fields); $i++)
        {
            $info_item = $info_fields[$i];
            $items_arry = $info_item['items'];
            for($j=0; $j < sizeof($items_arry); $j++)
            {
                $item = $items_arry[$j];
                $link = $item['link'];
                $info_fields[$i]['items'][$j]['link'] = $this->append_kt_query_str($link, $query_str);
            }
        }// foreach
        return $uuid;
    }
    
    // This is deprecated
    public function gen_feed_link_templatized_data(&$data, $template_id = null, $subtype1 = null, $subtype2 = null)
    {
        $this->gen_kt_comm_link_templatized_data($data, 'fdp', $template_id, $subtype1, $subtype2);
    }

    // $template_bundle_id: bundle_id from registerTemplateBundle.
    public function gen_feed_publishUserAction(&$data,
                                               $subtype1 = null, $subtype2 = null, $subtype3 = null,
                                               $msg_text = null)
    {
        $this->m_st1_tmp = $subtype1;
        $this->m_st2_tmp = $subtype2;
        $this->m_st3_tmp = $subtype3;
        
        if(is_array($data))
            $data_arry = $data;
        else
            $data_arry = json_decode($data, true);
        
        $query_str;
        $uuid =  $this->gen_kt_comm_query_str('feedpub', null, //template_id
                                              $this->m_st1_tmp,
                                              $this->m_st2_tmp,
                                              $this->m_st3_tmp,
                                              $query_str);
        $this->m_query_str_tmp = $query_str;
        if($data_arry != null)
        {        
            foreach($data_arry as $key => $value)
            {
                // read http://uk.php.net/manual/en/language.pseudo-types.php#language.types.callback:
                // for why I'm doing array($this, 'replace_kt_comm_link_helper')
                // ASSUMPTION: All urls begin with http or https. No relative urls.                
                if($key == "images")
                {
                    $len = sizeof($value);
                    for($i = 0 ; $i < $len ; $i++)
                    {
                        $value[$i]['href'] = $this->replace_kt_comm_link_helper_feedpub($value[$i]['href']);
                    }
                    $data_arry[$key] = $value;
                }
                else if($key == "flash")
                {
                    //TODO: 
                }
                else if($key == "mp3")
                {
                    //TODO: 
                }
                else if($key == "video")
                {
                    //TODO: 
                }
                else
                {
                    $new_value = preg_replace_callback(self::URL_REGEX_STR_NO_HREF,
                                                       array($this, 'replace_kt_comm_link_helper_feedpub'),
                                                       $value);
                    $data_arry[$key] = $new_value;
                }                
            }// foreach

            if( $msg_text != null )
            {
                $data_arry['KT_AB_MSG'] = $msg_text;
            }
            $data = json_encode($data_arry);
        }
        return $uuid;
    }

    public function get_serialized_msg_page_tuple($campaign)
    {
        $info = $this->m_ab_testing_mgr->get_selected_page_msg_info($campaign);
        return $this->m_ab_testing_mgr->serialize_msg_page_tuple_helper($campaign, $info);
    }
        
    // for the new feed form
    // it wraps individual links
    public function gen_feedstory_link($link, $uuid, $subtype1, $subtype2, $subtype3)
    {
        $this->m_st1_tmp = $subtype1;
        $this->m_st2_tmp = $subtype2;
        $this->m_st3_tmp = $subtype3;
        $query_str;
        $uuid = $this->gen_kt_comm_query_str('feedstory', null,
                                             $this->m_st1_tmp,
                                             $this->m_st2_tmp,
                                             $this->m_st3_tmp,
                                             $query_str,
                                             $uuid);
        $this->m_query_str_tmp = $query_str;        
        
        $new_value = preg_replace_callback(self::URL_REGEX_STR_NO_HREF,
                                           array($this, 'replace_kt_comm_link_helper_feedstory'),
                                           $link);
        return $new_value;
    }

    // designed to be used by feed_handler(fbml)
    public function gen_feedstory_link_vo($link, $uuid, $serialized_data)
    {
        $info = json_decode(str_replace('\\', '', $serialized_data), true);
        $st1 = "aB_".$info['campaign']."___".$info['handle_index'];
        $st2 = $this->format_kt_st2($info['data'][0]);
        $st3 = $this->format_kt_st3($info['data'][0]);        
        return $this->gen_feedstory_link($link, $uuid, $st1, $st2, $st3);
    }
    
    
    public function gen_multifeedstory_link($link, $uuid, $subtype1, $subtype2, $subtype3)
    {
        $this->m_st1_tmp = $subtype1;
        $this->m_st2_tmp = $subtype2;
        $this->m_st3_tmp = $subtype3;
        $query_str;
        $uuid = $this->gen_kt_comm_query_str('multifeedstory', null,
                                             $this->m_st1_tmp,
                                             $this->m_st2_tmp,
                                             $this->m_st3_tmp,
                                             $query_str,
                                             $uuid);
        $this->m_query_str_tmp = $query_str;        
        $new_value = preg_replace_callback(self::URL_REGEX_STR_NO_HREF,
                                           array($this, 'replace_kt_comm_link_helper_feedstory'),
                                           $link);
        return $new_value;
    }
    
    public function gen_multifeedstory_link_vo($link, $uuid, $serialized_data)
    {
        $info = json_decode(str_replace('\\', '', $serialized_data), true);
        $st1 = "aB_".$info['campaign']."___".$info['handle_index'];
        $st2 = $this->format_kt_st2($info['data'][0]);
        $st3 = $this->format_kt_st3($info['data'][0]);        
        return $this->gen_multifeedstory_link($link, $uuid, $st1, $st2, $st3);
    }
        
// assumption : st1_str is set to the campaign_name
    public function format_kt_st1($st1_str)
    {
        $handle_index = $this->m_ab_testing_mgr->get_ab_testing_campaign_handle_index($st1_str);
        return "aB_".$st1_str."___".(string)$handle_index;
    }

    public function format_kt_st2($st2_str)
    {
        return "m".$st2_str;
    }

    public function format_kt_st3($st3_str)
    {
        return "p".$st3_str;
    }
    
    public function kt_get_invite_post_link_vo($invite_post_link, $campaign, $data_assoc_array=null)
    {
        if ($this->m_invite_message_info == null)
        {
            $msg_info_array = $this->m_ab_testing_mgr->get_selected_msg_info($campaign, $data_assoc_array);
            $this->m_invite_message_info = $msg_info_array;
        }
        else
        {
            $msg_info_array = $this->m_invite_message_info;
            //$this->m_invite_message_info = null;
        }
        
        $param_array = array();        

        if ($this->m_invite_uuid == 0)
        {
            $this->m_invite_uuid = $this->gen_long_uuid();
            $uuid = $this->m_invite_uuid;
        }
        else
        {
            $uuid = $this->m_invite_uuid;
            //$this->m_invite_uuid = 0;
        }
            
        $param_array['kt_ut'] = $uuid;
        $param_array['kt_uid'] = $this->get_fb_param('user');
        $param_array['kt_type'] = 'ins'; 

        $param_array['kt_st1'] = $this->format_kt_st1($campaign);
        
        //$message_id = $this->m_ab_testing_mgr->get_message_id();
        $message_id = $msg_info_array[0];
        $param_array['kt_st2'] = $this->format_kt_st2($message_id);
        
        $page_info = $this->m_ab_testing_mgr->get_selected_page_info($campaign);
        $param_array['kt_st3'] = $this->format_kt_st3($page_info[0]);
        
        $r = array();
        $r['url']=$this->append_kt_query_str($invite_post_link, http_build_query($param_array,'', '&'));
        $r['message_id'] = $msg_info_array[0];
        $r['message'] = $msg_info_array[2];
        $r['button'] = $msg_info_array[3];
        $r['title'] = $msg_info_array[4];
        return $r;
    }

    //OG
    public function kt_get_invite_post_link($invite_post_link,
                                            $template_id = null, $subtype1 = null, $subtype2 = null)
    {
        $param_array = array();        

        if ($this->m_invite_uuid == 0)
        {
            $this->m_invite_uuid = $this->gen_long_uuid();
            $uuid = $this->m_invite_uuid;
        }
        else
        {
            $uuid = $this->m_invite_uuid;
            //$this->m_invite_uuid = 0; 
        }
            
        $param_array['kt_ut'] = $uuid;
        $param_array['kt_uid'] = $this->get_fb_param('user');
        $param_array['kt_type'] = 'ins'; 
        if(isset($template_id))        
            $param_array['kt_t'] = $template_id;
        if(isset($subtype1))        
            $param_array['kt_st1'] = $subtype1;
        if(isset($subtype2))        
            $param_array['kt_st2'] = $subtype2;

        return $this->append_kt_query_str($invite_post_link, http_build_query($param_array,'', '&'));
    }
    
    public function kt_get_invite_content_link($invite_content_link,
                                               $template_id = null, $subtype1 = null , $subtype2 = null)
    {
        $param_array['kt_uid'] = $this->get_fb_param('user');
      
        if ($this->m_invite_uuid == 0)
        {
            $this->m_invite_uuid = $this->gen_long_uuid();
            $uuid = $this->m_invite_uuid;
        }
        else
        {
            $uuid = $this->m_invite_uuid;
            //$this->m_invite_uuid = 0;
        }
        
        $param_array['kt_d'] = Analytics_Utils::directed_val;
        $param_array['kt_ut'] = $uuid;
        $param_array['kt_uid'] = $this->get_fb_param('user');
        $param_array['kt_type'] = 'in';
        if(isset($template_id))
            $param_array['kt_t'] = $template_id;
        if(isset($subtype1))        
            $param_array['kt_st1'] = $subtype1;
        if(isset($subtype2))        
            $param_array['kt_st2'] = $subtype2;

        return $this->append_kt_query_str($invite_content_link, http_build_query($param_array,'', '&'));
    }
    
    public function kt_get_invite_content_link_vo($invite_content_link, $campaign)
    {
        if ($this->m_invite_message_info == null)
        {
            $msg_info_array = $this->m_ab_testing_mgr->get_selected_msg_info($campaign);
            $this->m_invite_message_info = $msg_info_array;
        }
        else
        {
            $msg_info_array = $this->m_invite_message_info;
            //$this->m_invite_message_info = null;
        }

        if ($this->m_invite_uuid == 0)
        {
            $this->m_invite_uuid = $this->gen_long_uuid();
            $uuid = $this->m_invite_uuid;
        }
        else
        {
            $uuid = $this->m_invite_uuid;
            //$this->m_invite_uuid = 0;
        }
        
        $param_array['kt_uid'] = $this->get_fb_param('user');

        $param_array['kt_d'] = Analytics_Utils::directed_val;
        $param_array['kt_ut'] = $uuid;
        $param_array['kt_uid'] = $this->get_fb_param('user');
        $param_array['kt_type'] = 'in';

        $param_array['kt_st1'] = $this->format_kt_st1($campaign);
        $param_array['kt_st2'] = $this->format_kt_st2($msg_info_array[0]);        

        $page_info = $this->m_ab_testing_mgr->get_selected_page_info($campaign);
        $param_array['kt_st3'] = $this->format_kt_st3($page_info[0]);

        $r = array();
        $r['url'] = $this->append_kt_query_str($invite_content_link, http_build_query($param_array, '', '&'));
        $r['message_id'] = $msg_info_array[0];
        $r['message'] = $msg_info_array[2];
        
        return $r;
    }

    public function kt_clear_invite_tag()
    {
        $this->m_invite_uuid = 0;
    }

    public function kt_clear_invite_tag_vo()
    {
        $this->m_invite_uuid = 0;
        $this->m_invite_message_info = null;
    }
    
    public function kt_notifications_send($uid, $to_ids, $uuid, $template_id=null, $subtype1=null, $subtype2=null, $subtype3=null)
    {
        if(is_array($to_ids)){
            $to_ids_arg = join(",",$to_ids);
        }
        else
        {
            $to_ids_arg=$to_ids;
        }

        $arg_array = array('s' => $uid,
                           'r' => $to_ids_arg,
                           'u' => $uuid);
        if(isset($template_id))
            $arg_array['t'] = $template_id;

        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;

        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;

        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;
            
        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'nts',
                                             $arg_array);
    }

    public function kt_annoucements_send($to_ids, $uuid, $template_id, $subtype1=null, $subtype2=null, $subtype3=null)
    {
        $this->kt_notifications_send(0, $to_ids, $uuid, $template_id, $subtype1, $subtype2, $subtype3);
    }
    
    public function kt_email_send($uid, $to_ids, $uuid, $template_id, $subtype1=null, $subtype2=null, $subtype3=null)
    {
        if(is_array($to_ids)){
            $to_ids_arg = join(",",$to_ids);
        }
        else
        {
            $to_ids_arg=$to_ids;
        }

        $arg_array = array('s' => $uid,
                           'r' => $to_ids_arg,
                           'u' => $uuid);
        if(isset($template_id))
            $arg_array['t'] = $template_id;

        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;

        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        
        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;

        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'nes',
                                             $arg_array);
    }

    
    public function kt_templatized_feed_send($uid, $template_id, $subtype1=null, $subtype2=null)
    {
        $arg_array = array('pt' => 3,
                           's' => $uid);

        if(isset($template_id))
            $arg_array['t'] = $template_id;

        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;

        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        
        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'fdp',
                                             $arg_array);        
    }

    // $bundle_template_id : from registerTemplateBundle.
    public function kt_user_action_feed_send($uid, $uuid, $target_ids=null, $subtype1=null, $subtype2=null, $subtype3=null)
    {
        $arg_array = array('tu' => 'feedpub',
                           's' => $uid,
                           'u' => $uuid);

        if(isset($target_ids))
            $arg_array['tg'] = join(',', $target_ids);

        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;

        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        
        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;
            
        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'pst',
                                             $arg_array);
    }

    // designed to be used by feed_handler(fbml)
    public function kt_feedstory_send_vo($uuid, $serialized_data)
    {
        $info = json_decode(str_replace('\\', '', $serialized_data), true);
        $st1 = "aB_".$info['campaign']."___".$info['handle_index'];
        $st2 = $this->format_kt_st2($info['data'][0]);
        $st3 = $this->format_kt_st3($info['data'][0]);        
        return $this->kt_feedstory_send($uuid, $st1, $st2, $st3);
    }
    // designed to be used by feed_handler(fbml)
    public function kt_get_ab_feed_msg_text($serialized_data, $custom_data=null)
    {
        $info = json_decode(str_replace('\\', '', $serialized_data), true);
        return $this->m_ab_testing_mgr->replace_vo_custom_variable($info['data'][3], $custom_data);
    }
    // designed to be used by feed_handler(fbml)
    public function kt_get_ab_feed_call_to_action_text($serialized_data, $custom_data=null)
    {
        $info = json_decode(str_replace('\\', '', $serialized_data), true);
        return $this->m_ab_testing_mgr->replace_vo_custom_variable($info['data'][2], $custom_data);
    }

    public function kt_stream_send($uuid, $subtype1=null, $subtype2=null, $subtype3=null)
    {
        $uid = $this->get_fb_param('user');
        $arg_array = array('tu' => 'stream',
                           's' => $uid,
                           'u' => $uuid);
        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;
        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;
        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'pst',
                                             $arg_array);
    }

    public function kt_feedstory_send($uuid, $subtype1=null, $subtype2=null, $subtype3=null)
    {
        $uid = $this->get_fb_param('user');
        $arg_array = array('tu' => 'feedstory',
                           's' => $uid,
                           'u' => $uuid);
        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;
        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;

        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'pst',
                                             $arg_array);
    }

    public function kt_multifeedstory_send_vo($uuid, $post_request, $serialized_data)
    {
        $info = json_decode(str_replace('\\', '', $serialized_data), true);
        $st1 = "aB_".$info['campaign']."___".$info['handle_index'];
        $st2 = $this->format_kt_st2($info['data'][0]);
        $st3 = $this->format_kt_st3($info['data'][0]);        
        return $this->kt_multifeedstory_send($uid, $post_request, $st1, $st2, $st3);
    }

    public function kt_multifeedstory_send($uuid, $post_request, $subtype1=null, $subtype2=null, $subtype3=null)
    {
        $uid = $this->get_fb_param('user');
        // the recipent can be found either as ktuid or as friend_selector_id.
        if(isset($post_request['friend_selector_id']))
            $target_uid = $post_request['friend_selector_id'];
        else if(isset($post_request['ktuid']))
            $target_uid = $post_request['ktuid'];
        
        $arg_array = array( 'tu' => 'multifeedstory',
                            's' => $uid,
                            'u' => $uuid,
                            'tg' =>$target_uid );
        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;
        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;

        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'pst',
                                             $arg_array);
    }

    public function kt_profile_setFBML_send($uid, $subtype1 = null, $subtype2 = null, $subtype3 = null)
    {
        $arg_array = array('tu' => 'profilebox',
                           's' => $uid);
        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;
        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;

        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'pst',
                                             $arg_array);
    }

    public function kt_profile_setInfo_send($uid, $subtype1 = null, $subtype2 = null, $subtype3 = null)
    {
        $arg_array = array('tu' => 'profileinfo',
                           's' => $uid);
        if(isset($subtype1))
            $arg_array['st1'] = $subtype1;
        if(isset($subtype2))
            $arg_array['st2'] = $subtype2;
        if(isset($subtype3))
            $arg_array['st3'] = $subtype3;

        $this->m_aggregator->api_call_method($this->m_backend_url, 'v1',
                                             $this->m_backend_api_key, $this->m_backend_secret_key,
                                             'pst',
                                             $arg_array);
    }
    
    public function save_app_added()
    {
        $has_direction = isset($_GET['d']);
        $uid = $this->get_fb_param('user');

        if(!empty($_COOKIE[$this->gen_ut_cookie_key()]))
        {
            $this->an_app_added_directed($uid, $_COOKIE[$this->gen_ut_cookie_key()]);
            setcookie($this->gen_ut_cookie_key(), "", time()-600); //remove cookie
        }
        else if(!empty($_COOKIE[$this->gen_sut_cookie_key()]))
        {
            $this->an_app_added_undirected($uid, $_COOKIE[$this->gen_sut_cookie_key()]);
            setcookie($this->gen_sut_cookie_key(), "", time()-600); //remove cookie
        }
        else if(!empty($_COOKIE[$this->gen_ru_cookie_key()]))
        {
            $this->an_app_added_profile($uid, $_COOKIE[$this->gen_ru_cookie_key()]);
            setcookie($this->gen_ru_cookie_key(), "", time()-600); //remove cookie
        }
        else
        {
            $this->an_app_added_nonviral($uid);
        }
        /*
        else
        {
            // If the app's settings on facebook has a specifed post-authorized redirect URL,
            // then all kt_* parameters will be escaped out. To work around this problem, it'll
            // grab ut or sut. If there's kt_ut or kt_d, that means that they have require_once before
            // include_once kontagent.php
            
            if(preg_match(Analytics_Utils::ESC_URL_UT_REGEX_STR, $_SERVER['QUERY_STRING'], $matches))
            {
                $tmp_str = urldecode($matches[1]);
                $tmp_arry = split("=", $tmp_str);
                if(sizeof($tmp_arry) != 2)
                {
                    $this->an_app_added_nonviral($uid);
                }
                else
                {
                    $this->an_app_added_directed($uid, $tmp_arry[1]);
                }
            }
            else if(preg_match(Analytics_Utils::ESC_URL_SUT_REGEX_STR, $_SERVER['QUERY_STRING'], $matches))
            {
                $tmp_str = urldecode($matches[1]);
                $tmp_arry = split("=", $tmp_str);
                if(sizeof($tmp_arry) != 2)
                {
                    $this->an_app_added_nonviral($uid);
                }
                else
                {
                    $this->an_app_added_undirected($uid, $tmp_arry[1]);
                }
            }
            else
            {
                $this->an_app_added_nonviral($uid);
            }
            }*/
    }
    
    public function save_notification_click($added)
    {
        $ut = $_GET['kt_ut'];

        $template_id = null;
        if(isset($_GET['kt_t']))
            $template_id = $_GET['kt_t'];
        $subtype1 = null;
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        $subtype2 = null;
        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        $subtype3 = null;
        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        
        $uid = $this->get_fb_param('user');
        
        $this->an_notification_click($added, $ut, $template_id, $subtype1, $subtype2, $subtype3, $uid);
        return $this->get_stripped_kt_args_url();
    }
    
    public function save_notification_email_click($added)
    {
        $ut = $_GET['kt_ut'];
        
        $template_id = null;
        if(isset($_GET['kt_t']))
            $template_id = $_GET['kt_t'];
        $subtype1 = null;
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        $subtype2 = null;
        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];        
        $subtype3 = null;
        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
            
        $uid = $this->get_fb_param('user');
        
        $this->an_notification_email_click($added, $ut, $template_id, $subtype1, $subtype2, $subtype3, $uid);
        return $this->get_stripped_kt_args_url();
    }
    
    public function save_invite_send()
    {
        $template_id = null;
        if(isset($_GET['kt_t']))
            $template_id = $_GET['kt_t'];
        $subtype1 = null;
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        $subtype2 = null;
        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];        
        $subtype3 = null;
        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        
        if(isset($_REQUEST['ids']))
            $recipient_arry = $_REQUEST['ids'];
        else
            $recipient_arry = '';

        $this->an_invite_send($_GET['kt_uid'], $recipient_arry, $_GET['kt_ut'], $template_id, $subtype1, $subtype2, $subtype3);
        return $this->get_stripped_kt_args_url();
    }

    public function save_invite_click($added)
    {
        $ut = $_GET['kt_ut'];

        if(isset($_GET['kt_t']))
            $template_id = $_GET['kt_t'];
        else
            $template_id = null;        
      
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;        

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;
        
        $uid = $this->get_fb_param('user');
        
        $this->an_invite_click($added, $ut, $template_id, $subtype1, $subtype2, $subtype3, $uid); 
        return $this->get_stripped_kt_args_url();
    }

    // returns the short_tag;
    public function save_undirected_comm_click($added)
    {
        $uid = $this->get_fb_param('user');
        
        $type = $_GET['kt_type'];
        
        if(isset($_GET['kt_t']))
            $template_id = $_GET['kt_t'];
        else
            $template_id = null;        
      
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;        

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;
        
        $short_tag = $this->gen_short_uuid();
        $this->an_app_undirected_comm_click($uid, $type, $template_id, $subtype1, $subtype2, $subtype3, $added, $short_tag);

        return $this->get_stripped_kt_args_url($short_tag);
    }

    public function save_feedpub_click($added)
    {
        $uid = $this->get_fb_param('user');
        $ut = $_GET['kt_ut'];
        
        $template_id = null;

        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;
        
        $this->an_feedpub_click($added, $ut, $template_id, $subtype1, $subtype2, $subtype3, $uid);
        return $this->get_stripped_kt_args_url();
    }

    public function save_stream_click($added)
    {
        $uid = $this->get_fb_param('user');
        $ut = $_GET['kt_ut'];

        $template_id = null;
                
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;

        $this->an_stream_click($added, $ut, $template_id, $subtype1, $subtype2, $subtype3, $uid);
        return $this->get_stripped_kt_args_url();
    }
    
    public function save_feedstory_click($added)
    {
        $uid = $this->get_fb_param('user');
        $ut = $_GET['kt_ut'];

        $template_id = null;
                
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;

        $this->an_feedstory_click($added, $ut, $template_id, $subtype1, $subtype2, $subtype3, $uid);
        return $this->get_stripped_kt_args_url();
    }

    public function save_multifeedstory_click($added)
    {
        $uid = $this->get_fb_param('user');
        $ut = $_GET['kt_ut'];

        $template_id = null;

        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;

        $this->an_multifeedstory_click($added, $ut, $template_id, $subtype1, $subtype2, $subtype3, $uid);
        return $this->get_stripped_kt_args_url();
    }

    public function save_profilebox_click($added)
    {
        $uid = $this->get_fb_param('user');
        
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;

        $owner_uid = $_GET['kt_owner_uid'];

        $this->an_profilebox_click($added, $subtype1, $subtype2, $subtype3, $owner_uid, $uid);
        return $this->get_stripped_kt_args_url();
    }


    public function save_profileinfo_click($added)
    {
        $uid = $this->get_fb_param('user');
        
        if(isset($_GET['kt_st1']))
            $subtype1 = $_GET['kt_st1'];
        else
            $subtype1 = null;

        if(isset($_GET['kt_st2']))
            $subtype2 = $_GET['kt_st2'];
        else
            $subtype2 = null;

        if(isset($_GET['kt_st3']))
            $subtype3 = $_GET['kt_st3'];
        else
            $subtype3 = null;

        $owner_uid = $_GET['kt_owner_uid'];
        $this->an_profileinfo_click($added, $subtype1, $subtype2, $subtype3, $owner_uid, $uid);
        return $this->get_stripped_kt_args_url();
    }
        
    public function save_app_removed()
    {
        global $kt_facebook;

        $post_arry = $_POST;
        ksort($post_arry);
        $sig = '';
        
        foreach ($post_arry as $key => $val) {
            if ($key == 'fb_sig') {
                continue;
            }

            $sig .= substr($key, 7) . '=' . $val;
        }

        $sig .= $kt_facebook->secret;
        $verify =  md5($sig);

        if ($verify == $post_arry['fb_sig']) {
            // Update your database to note that fb_sig_user has removed your application
            $this->an_app_remove($post_arry['fb_sig_user']);
        }else{
            // TODO: log this somehow?
        }
    }

    public function increment_goal_count($uid, $goal_id, $inc)
    {
        $this->an_goal_count_increment($uid, array($goal_id => $inc));
    }
    
    // $goal_count : array($goal_id => $inc);
    public function increment_multiple_goal_counts($uid, $goal_counts)
    {
        $this->an_goal_count_increment($uid, $goal_counts);
    }

    public function increment_monetization($uid, $money_value)
    {
        $this->an_monetization_increment($uid, $money_value);
    }
    
    // Should use cookie to avoid sending repeated information to kontagent.
    // Example:
    // $key = $an->$m_backend_api_key."_".$uid;
    // if(!empty($_COOKIE[$key]))
    // {
    //    $an->kt_capture_user_data($uid, $info_array);
    // }
    // setcookie($key, 1, time()+1209600); // two weeks.
    
    public function kt_capture_user_data($uid, $info)
    {
        global $kt_facebook;

        if(is_array($info))
        {
            $birthday = null;
            if( isset($info[0]['birthday']) && $info[0]['birthday'] != '')
                $birthday = $info[0]['birthday'];

            $gender = null;
            if( isset($info[0]['sex']) && $info[0]['sex'] != '')
                $gender = substr($info[0]['sex'], 0, 1);
               
            $cur_city = null;
            if( isset($info[0]['current_location']['city']) &&
                $info[0]['current_location']['city'] != '' )
                $cur_city = $info[0]['current_location']['city'];
            $cur_state = null;
            if( isset($info[0]['current_location']['state']) &&
                $info[0]['current_location']['state'] != '')
                $cur_state = $info[0]['current_location']['state'];
            $cur_country = null;
            if( isset($info[0]['current_location']['country']) &&
                $info[0]['current_location']['country'] != '')
                $cur_country = $info[0]['current_location']['country'];
            $cur_zip = null;
            if( isset($info[0]['current_location']['zip']) &&
                $info[0]['current_location']['zip'] != '')
                $cur_zip = $info[0]['current_location']['zip'];

            $home_city=null;
            if( isset($info[0]['hometown_location']['city']) &&
                $info[0]['hometown_location']['city'] != '')
                $home_city = $info[0]['hometown_location']['city'];
            $home_state=null;
            if( isset($info[0]['hometown_location']['state']) &&
                $info[0]['hometown_location']['state'] != '')
                $home_state = $info[0]['hometown_location']['state'];
            $home_country=null;
            if( isset($info[0]['hometown_location']['country']) &&
                $info[0]['hometown_location']['country'] !='')
                $home_country = $info[0]['hometown_location']['country'];
            $home_zip = null;
            if( isset($info[0]['hometown_location']['zip']) &&
                $info[0]['hometown_location']['zip'] != '')
                $home_zip = $info[0]['hometown_location']['zip'];
               
            //$friends_count = count(split(',',$kt_facebook->fb_params['friends']));
            $friend_count = $info[0]['friend_count'];
            
            $this->an_send_user_data($uid, $birthday, $gender,
                                     $cur_city, $cur_state, $cur_country, $cur_zip,
                                     $home_city, $home_state, $home_country, $home_zip,
                                     $friend_count);
        }
    }
}


?>
