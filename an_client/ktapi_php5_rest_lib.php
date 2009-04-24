<?php
$dir_name = dirname(__FILE__);
include_once 'kt_analytics.php';
include_once $dir_name . '/../facebookapi_php5_restlib.php';


class Kt_FacebookRestClient extends FacebookRestClient
{
    //const URL_REGEX_STR = '/https?:\/\/[^\s>\'"]+/';    
    //private $m_backend_api_key;
    //private $m_backend_secret_key;
    //private $m_backend_url;
    //private $m_backend_host;
    
    public function __construct($api_key,
                                $secret,
                                $backend_api_key,
                                $backend_secret_key,
                                $backend_host,
                                $backend_port,
                                $backend_url,
                                $canvas_url,
                                $local_req_uri,
                                $session_key=null){
        parent::__construct($api_key, $secret, $session_key);

        $this->m_an = Analytics_Utils::instance($backend_api_key, $backend_secret_key,
                                                $backend_host, $backend_port, $backend_url,
                                                $canvas_url, $local_req_uri);
    }

    
    // type: is either 'user_to_user' for normal notifications, or
    // 'app_to_user' for annoucement.
    public function &notifications_send($to_ids, $notification, $type, $template_id=null, $st1=null, $st2=null, $from_id=null)
    {
        $uuid = $this->m_an->gen_notifications_link($notification, $template_id, $st1, $st2);
        
        $r = parent::notifications_send($to_ids, $notification, $type);
        
        if($r != null)
        {
            if($type == 'app_to_user')
                $this->m_an->kt_annoucements_send($to_ids, $uuid, $template_id, $st1, $st2);
            else if($type == 'user_to_user')
            {
                global $logger;
                if ($from_id)
                    $from = $from_id;
                else
                    $from = $this->m_an->get_fb_param('user');
                
                $this->m_an->kt_notifications_send($from, $to_ids, $uuid, $template_id, $st1, $st2);
            }
        }
        return $r;
    }
    
    public function &notifications_sendEmail($recipients, $subject, $text, $fbml, $template_id=null, $st1=null, $st2=null)
    {
        $uuid = $this->m_an->gen_email_link($fbml, $template_id, $st1, $st2);
        
        $r = parent::notifications_sendEmail($recipients, $subject, $text, $fbml);
        
        if($r != null)
            $this->m_an->kt_email_send($this->m_an->get_fb_param('user'), $recipients, $uuid, $template_id, $st1, $st2);
        return $r;
    }

    public function &feed_publishTemplatizedAction($title_template, $title_data,
                                                   $body_template, $body_data, $body_general,
                                                   $image_1=null, $image_1_link=null,
                                                   $image_2=null, $image_2_link=null,
                                                   $image_3=null, $image_3_link=null,
                                                   $image_4=null, $image_4_link=null,
                                                   $target_ids='', $page_actor_id=null,
                                                   $template_id=null, $st1=null, $st2=null){    
        $this->m_an->gen_feed_link($title_template);
        $this->m_an->gen_feed_link($body_template);
        $this->m_an->gen_feed_link($body_general);
        $this->m_an->gen_feed_link_templatized_data($title_data);
        $this->m_an->gen_feed_link_templatized_data($body_data);

        $r = parent::feed_publishTemplatizedAction($title_template,
                                                   $title_data,
                                                   $body_template,
                                                   $body_data,
                                                   $body_general);
        if($r != null)
            $this->m_an->kt_templatized_feed_send($this->m_an->get_fb_param('user'), $template_id, $st1, $st2);
        
        return $r;
    }

    public function &feed_publishUserAction($template_bundle_id, $template_data,
                                            $target_ids=array(), $body_general='',
                                            $story_size = FacebookRestClient::STORY_SIZE_ONE_LINE,
                                            $st1=null, $st2=null)
    {
        $this->m_an->gen_feed_publishUserAction($template_data,
                                                $template_bundle_id,
                                                $st1, $st2);
        
        $r = parent::feed_publishUserAction($template_bundle_id, $template_data, $target_ids, $body_general, $story_size);

        if($r != null)
            $this->m_an->kt_user_action_feed_send($this->m_an->get_fb_param('user'), $template_bundle_id, $st1, $st2);
        
        return $r;
    }
    
}


?>