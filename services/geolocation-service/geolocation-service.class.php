<?php

namespace IfSo\Services\GeolocationService;

require_once(IFSO_PLUGIN_BASE_DIR . 'services/plugin-settings-service/plugin-settings-service.class.php');
require_once(__DIR__ .'/geo-data.class.php');

use IfSo\Services\PluginSettingsService;

class GeolocationService {
	private static $instance;
    private $wpdb;
    private static $bad_request = false;
    private $possible_notification_threshholds = [60,75,90,95,100];
    public $web_service_url;
    private $daily_sessions_table_date_format = 'F j, Y';
    private $sent_notification_option_prefix = 'ifso_notified_geo_usage_';

	private function __construct() {
        global $wpdb;
		$web_service_domain = 'http://www.if-so.com/api/';
		$this->web_service_url = $web_service_domain.IFSO_API_VERSION.'/geolocation-service/geolocation-api.php';
        $this->wpdb = $wpdb;
	}
	
	public static function get_instance() {
		if ( NULL == self::$instance )
		    self::$instance = new GeolocationService();
		
		return self::$instance;
	}

    public function get_possible_notification_thresholds() {
        return $this->possible_notification_threshholds;
    }

	private function cache_geo_data($geoData) {
        $encodedGeoData = json_encode($geoData, JSON_UNESCAPED_UNICODE);
        $container_key = 'ifso_geo_data';
        if(PluginSettingsService\PluginSettingsService::get_instance()->disableSessions->get()){
            $obfEncodedGeoData = base64_encode($encodedGeoData);
            \IfSo\PublicFace\Helpers\CookieConsent::get_instance()->set_cookie($container_key, $obfEncodedGeoData, 0, "/");
            $_COOKIE[$container_key] = $obfEncodedGeoData;
            return false;
        }
        else{
            @session_start();
            $_SESSION[$container_key] = $encodedGeoData;
            session_write_close();
        }
	}
	
	private function get_cached_geo_data() {
        $ret = null;
        $session = (isset($_SESSION)) ? $_SESSION : null;
        $cache_source = (PluginSettingsService\PluginSettingsService::get_instance()->disableSessions->get()) ? $_COOKIE : $session;
		if ( isset($cache_source['ifso_geo_data']) ) {
            $src = ($cache_source===$_COOKIE) ? base64_decode($cache_source['ifso_geo_data']) : $cache_source['ifso_geo_data'];
            $ret = json_decode(stripslashes($src), true);
            if($cache_source===$_COOKIE){
                $ret =  array_map('esc_html',$ret);
            }
        }
		return $ret;
	}
	
	private function get_geo_data($license, $user_ip, $action) {
		$url = $this->web_service_url . 
		"?license=" . $license . "&ip=" . $user_ip . "&action=" . $action;
		$response = wp_remote_get( $url ,array('timeout' => 10) );
		
		if( is_array($response) ) {
			return json_decode( $response['body'], true );
		} else {
			return json_encode(array('success' => false));
		}
	}
	
	public function send_session_to_localdb($license) {
        $daily_sessions_table_name = $this->wpdb->prefix . 'ifso_daily_sessions';
        $local_user_table_name = $this->get_localuser_db_name();
        $sql = "INSERT INTO {$daily_sessions_table_name} (sessions_date, num_of_sessions) VALUES (%s,%d) ON DUPLICATE KEY UPDATE sessions_date = sessions_date, num_of_sessions = num_of_sessions + 1";
        $sql = $this->wpdb->prepare($sql, date($this->daily_sessions_table_date_format) ,1);
        $this->wpdb->query($sql);
        $local_status= $this->get_localdb_session_status();

        if($local_status->user_bank < $local_status->user_sessions || $local_status->user_sessions%50!=0){//Only request geo status from the server every 50 sessions
            $user_bank_status = $local_status->user_bank;
            $user_sessions_status = $local_status->user_sessions+1;
            $used_geo_sessions = $local_status->used_geo_sessions;
            $used_pro_sessions = $local_status->used_pro_sessions;
            if($local_status->geo_bank - $used_geo_sessions>0) ++$used_geo_sessions;
            elseif($local_status->pro_bank - $used_pro_sessions>0) ++$used_pro_sessions;

            $this->wpdb->query("UPDATE {$local_user_table_name} SET 
                user_sessions = '$user_sessions_status',
                user_bank = '$user_bank_status',
                used_pro_sessions = '$used_pro_sessions',
                used_geo_sessions  = '$used_geo_sessions'
            ");
        }
        else{
            $this->get_status($license,true);       //Updates the status from the server into the local db
        }
	}

    public function notifications_email(){
        $local_user_data = $this->get_localdb_session_status();
        $notifications_arr = array_map('intval',array_unique(array_filter(explode(" ",$local_user_data->alert_values))));
        rsort($notifications_arr);
        $user_domain = get_option('home');
        $user_domain_name = parse_url(get_option('home'))['host'];
        $to = !empty($local_user_data->user_email) ? $local_user_data->user_email : get_option('admin_email');
        $headers = 'Content-Type: text/html; charset=ISO-8859-1';
        $total_percent_used = ($local_user_data->user_bank !== 0) ? $local_user_data->user_sessions/($local_user_data->user_bank-1) * 100 : 0;

        foreach ($notifications_arr as $notif_threshold) {
            if($total_percent_used >= $notif_threshold  && $this->can_send_notification($notif_threshold)) {
                $subject = "If-So - license quota at $notif_threshold%";
                $body = "Dear {$user_domain_name } Admin,<br> <br>
                We wanted to inform you that your website has reached {$notif_threshold}% of its monthly geolocation sessions quota ({$local_user_data->user_bank} sessions).<br> <br>
                A report detailing your usage, quota, and renewal date is available on your <a href='$user_domain/wp-admin/admin.php?page=wpcdd_admin_geo_license' target='_blank'>website's admin panel</a>.<br> <br>
                If you need to, you can upgrade your Geolocation Sessions at any time. <a href='https://www.if-so.com/plans/geolocation-plans/' target='_blank'>Upgrade</a>.<br> <br>
                Feel free to contact us with any questions or concerns.<br> <br>
                Sincerely,<br> <br>
                The If-So Team<br> <br>";

                $this->mark_notification_as_sent($notif_threshold);

                foreach ($notifications_arr as $val) {
                    if ($notif_threshold > $val) {
                        $this->mark_notification_as_sent($val);
                    }
                }

                wp_mail( $to, $subject, $body, $headers );
                return;
            }
        }
    }


	public function get_location_by_ip($license, $user_ip) {
        $exclude_from_geo = apply_filters('ifso_exclude_from_geo',['cookie'=>[],'ip'=>[],'blockme'=>false]);
        $geo_whitelist = apply_filters('ifso_geo_whitelist',['cookie'=>[],'ip'=>[],'allowme'=>false]);

        if((isset($geo_whitelist['ip']) && !in_array($user_ip,$geo_whitelist['ip'])) && (isset($geo_whitelist['cookie']) && is_array($geo_whitelist['cookie']) && count(array_intersect($geo_whitelist['cookie'],array_keys($_COOKIE)))===0) && (isset($geo_whitelist['allowme']) && !$geo_whitelist['allowme'])){
            if((isset($exclude_from_geo['ip']) && in_array($user_ip,$exclude_from_geo['ip'])) || (isset($exclude_from_geo['cookie']) && is_array($exclude_from_geo['cookie']) && count(array_intersect($exclude_from_geo['cookie'],array_keys($_COOKIE)))>0) || (isset($exclude_from_geo['blockme']) && $exclude_from_geo['blockme']))
                return;
        }

		$cachedGeoData = $this->get_cached_geo_data();      // try get cached geo data
		if ($cachedGeoData !== NULL && isset($cachedGeoData['ipAddress']) && $cachedGeoData['ipAddress']== $user_ip) {      //Invalidate geo cache if user IP has changed
			return $cachedGeoData;
		}

		if(self::$bad_request){
            //The first api request during this pageload was bad - don't try to do any more until the next page load
            return;
        }
		
		$geoData = $this->get_geo_data($license, $user_ip, 'get_ip_info');
		// cache and send sessions to db if success
		if ( isset($geoData['success']) && $geoData['success'] === true ) {
			$this->cache_geo_data($geoData);
			$this->send_session_to_localdb($license); //Locally tracking geo sessions
			$this->notifications_email();//Check whether a quota notification needs to be sent and send one if yes

		}
		else{
            $this->cache_geo_data(array());
            self::$bad_request = true;
        }

        $this->log_geo_request($user_ip,!self::$bad_request);

		return $geoData;
    }

    private function log_geo_request($ip,$success){
        if(defined('IFSO_GEOLOCATION_ON') && IFSO_GEOLOCATION_ON){
            \IfSo\Addons\Geolocation\Services\GeoRequestLogService::get_instance()->log_geo_request($ip,$success);
        }
    }

    public function get_user_ip() {
        $ip = null;
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        elseif (!empty($_SERVER['HTTP_CF_CONNECTING_IP']))      //Cloudflare
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        else
            $ip = $_SERVER['REMOTE_ADDR'];

        if(!empty($ip)){
            $ip = explode(',',$ip)[0];
            $exploded_ip = explode(':',$ip);
            if(count($exploded_ip)<2)      //IPV6 or IPV4+PORT?
                $ip = $exploded_ip[0];
        }

        $ip = apply_filters('ifso_user_ip',$ip);

        return $ip;
    }

    public function get_user_location($allow_override=true){
        $ip = $this->get_user_ip();

        $override_geo_data = apply_filters('ifso_location_data_override',[],$ip);
        if(!empty($override_geo_data) && $allow_override){
            $override_geo_data['override'] = true;
            return GeoDataOverride::make_from_data_array($override_geo_data);
        }

        $geo_data = $this->get_location_by_ip("ifso-lic", $ip);
        $geo_data = !empty($geo_data) || is_array($geo_data) && !$geo_data['success'] ? $geo_data : [];

        return GeoData::make_from_data_array($geo_data);
    }
		
    public function get_status($license,$update_localdb=true) {
        $url = $this->web_service_url . "?action=get_status&license=".$license;
        $response = wp_remote_get($url, array('timeout' => 20) );

        if( is_array($response) ) {
            $data = json_decode( $response['body'], true );
            if($update_localdb && $data['success'])
                $this->update_localdb_status($data);
            return $data;
        } else {
            return json_encode(array('success' => false));
        }
    }

    private function get_localdb_session_status() {
        $user_notification_data = $this->wpdb->get_results( "SELECT * FROM {$this->get_localuser_db_name()}");
        if(!empty($user_notification_data) && is_array($user_notification_data))
            return $user_notification_data[0];
    }

    private function update_localdb_status($status_data){
        $user_bank_status = $status_data["bank"];
        $user_sessions_status = $status_data["realizations"];
        $pro_key_renewal = (isset($status_data['pro_renewal_date'])) ?  date_format(new \DateTime($status_data['pro_renewal_date']),'Y-m-d') : NULL;
        $used_pro_sessions = (isset($status_data['pro_realizations'])) ? $status_data['pro_realizations']  : 0;
        $has_plusgeo_key = (!empty($status_data['has_plusgeo_key']));
        $geo_key_renewal = ($has_plusgeo_key) ? date_format(new \DateTime($status_data['plusgeo_renewal_date']),'Y-m-d') : NULL;
        $used_geo_sessions = (isset($status_data['geo_realizations'])) ? $status_data['geo_realizations']  : 0;
        $pro_bank = (isset($status_data['product_bank'])) ? $status_data['product_bank']  : 0;;
        $geo_bank = (isset($status_data['geo_bank'])) ? $status_data['geo_bank']  : 0;
        $local_status = $this->get_localdb_session_status();
        $local_geo_renewal_date = ((int)$local_status->geo_renewal_date!==0) ? $local_status->geo_renewal_date : null;
        $local_pro_renewal_date = ((int)$local_status->pro_renewal_date!==0) ? $local_status->pro_renewal_date : null;
        $reset_notifications = (($geo_key_renewal !== $local_geo_renewal_date) || ($pro_key_renewal !== $local_pro_renewal_date));  //License has changed

        $sql = "UPDATE {$this->get_localuser_db_name()} SET 
                        user_sessions = '$user_sessions_status',
                        user_bank = '$user_bank_status',
                        pro_renewal_date = '$pro_key_renewal',
                        geo_renewal_date = '$geo_key_renewal',
                        used_pro_sessions = '$used_pro_sessions',
                        used_geo_sessions = '$used_geo_sessions',
                        pro_bank = '$pro_bank',
                        geo_bank = '$geo_bank'";
        $this->wpdb->query($sql);

        if($reset_notifications)
            $this->reset_notifications();
    }

    public function get_daily_sessions_table_date_format(){
        return $this->daily_sessions_table_date_format;
    }

    private function get_localuser_db_name(){
        return $this->wpdb->prefix . 'ifso_local_user';
    }

    private function can_send_notification($option){
        $option = $this->get_sess_notification_option_name($option);
        $dat = get_option($option);
        if($dat){
            return !((string) $dat === '1');
        }
        return true;
    }

    private function mark_notification_as_sent($option){
        $option = $this->get_sess_notification_option_name($option);
        update_option($option,'1');
    }

    public function reset_notifications($skip_exceeded_thresholds=true) {
        if($skip_exceeded_thresholds){
            $local_user_data = $this->get_localdb_session_status();
            $sessions_percent_used = ($local_user_data->user_bank !== 0) ? $local_user_data->user_sessions/($local_user_data->user_bank-1) * 100 : 0;
        }
        foreach($this->possible_notification_threshholds as $percentage){
            if(!$skip_exceeded_thresholds || (int) $sessions_percent_used < $percentage)
                update_option($this->get_sess_notification_option_name($percentage),'');
        }
    }

    private function get_sess_notification_option_name($threshold) {
        return $this->sent_notification_option_prefix . $threshold;
    }

    public function license_data_value_changed($field){
        if($field->get_name()==='license_status')
            $this->get_status('*');
    }
}