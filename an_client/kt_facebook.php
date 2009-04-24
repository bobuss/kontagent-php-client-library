<?php

// Kontagent an_client lib version 0.2.16

$dir_name = dirname(__FILE__);
include_once 'ktapi_php5_rest_lib.php';
include_once $dir_name . '/../facebook.php';

class Kt_FaceBook extends FaceBook {

    public function __construct($fb_api_key, $fb_secret_key,
                                $backend_api_key, $backend_secret_key,
                                $backend_host, $backend_port, $backend_url,
                                $canvas_url, $call_back_req_uri)
    {
        parent::__construct($fb_api_key, $fb_secret_key);        
        // override the original api_client with our own.
        $this->api_client = new Kt_FacebookRestClient($fb_api_key, $fb_secret_key,
                                                      $backend_api_key, $backend_secret_key,
                                                      $backend_host, $backend_port, $backend_url,
                                                      $canvas_url, $call_back_req_uri);

        // Mirroring what parent has done. Yes, it's a called twice, but this is to ensure
        // that our version of api_client is set up properly.        
        $this->validate_fb_params();
        if (isset($this->fb_params['friends'])) {
            $this->api_client->friends_list = explode(',', $this->fb_params['friends']);
        }
        if (isset($this->fb_params['added'])) {
            $this->api_client->added = $this->fb_params['added'];
        }        
    }

    public function get_user_info($user)
    {
        $friend_cnt = count($this->api_client->friends_get());
        $info = $this->api_client->users_getInfo($user, array('sex', 'birthday', 'current_location', 'hometown_location')); 
        $info[0]['friend_count'] = $friend_cnt;
        return $info;
    }
}


?>
