<?php
// Kontagent an_lib version 0.2.16
include_once 'kt_config.php';

if(! (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ) 
{
    if(isset($_POST['fb_sig_uninstall']))
    {
        $an->save_app_removed();
    }

    if(isset($_GET['installed']) && $_GET['installed'] == true)
        $kt_installed = true;
    else
        $kt_installed = false;

    // grab user data
    if ( ($kt_user = $kt_facebook->get_loggedin_user()) && $an->get_fb_param('user') != 0 && !isset($_POST['fb_sig_uninstall']))
    {
        $kt_key = "KT_".$kt_facebook->api_key."_".$kt_user;
        
        if( empty($_COOKIE[$kt_key]) || (($auto_capture_user_info_at_install == true) && ($kt_installed == true) ) )
        {
            $uid = $an->get_fb_param('user');
            $kt_user_info = $kt_facebook->get_user_info($uid);
            $an->kt_capture_user_data($uid, $kt_user_info);
            setcookie($kt_key, 1, time()+1209600); //two weeks
        }
    }

//if( (isset($_POST['fb_sig_in_new_facebook']) && $_POST['fb_sig_in_new_facebook'] == 1) )
    {
        if(isset($_GET["kt_type"]))
        {
            $kt_is_added = $kt_facebook->fb_params['added'];

            switch($_GET["kt_type"])        
            {
            case "nt":
            {
                $kt_url = $an->save_notification_click($kt_is_added); 
                $kt_facebook->redirect($kt_url);            
                break;
            }
            case "ins":
            {
                $kt_url = $an->save_invite_send();
                break;
            }
            case "in":
            {        
                $kt_url = $an->save_invite_click($kt_is_added);
                $kt_facebook->redirect($kt_url);
                break;
            }
            case "nte":
            {
                $kt_url = $an->save_notification_email_click($kt_is_added);
                $kt_facebook->redirect($kt_url);
                break;
            }
            case "fdp":        
            case "ad":        
            case "prt":        
            case "prf":        
            case "partner":        
            case "profile":        
            {
                $kt_url = $an->save_undirected_comm_click($kt_is_added);
                $kt_facebook->redirect($kt_url);
                break;
            }
            }//switch
        }
    }

// Can comment this out, if you so choose.
//$kt_facebook->require_login(); 

//If the user hasn't authorized the application, fb_sig_user will not be set.

    if($kt_installed == true)
    {
        $an->save_app_added();    
        //$kt_facebook->redirect($an->get_stripped_installed_arg_url());
    }

    if ( $automatic_page_request_capture == 1 )
    {
        include_once 'an_lib/page_request_capture.php';
    }
}

?>

