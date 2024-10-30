<?php

namespace IfSo\Services\GeoLicenseService;

use IfSo\Services\LicenseService\LicenseServiceBase;
use IfSo\Services\LicenseService\LicenseData;

require_once __DIR__ . '/license-service-base.class.php';

class GeoLicenseService extends LicenseServiceBase {
    protected function init_license_data() : LicenseData\LicenseData {
        $ld = new LicenseData\LicenseData();
        $ld->set_field_by_option_name('license_key','edd_ifso_geo_license_key')->
        set_field_by_option_name('license_status','edd_ifso_geo_license_status')->
        set_field_by_option_name('item_id','edd_ifso_geo_license_item_id')->
        set_field_by_option_name('is_lifetime','edd_ifso_has_lifetime_geo_license')->
        set_field_by_option_name('expires','edd_ifso_geo_license_expires')->
        set_field_by_option_name('num_of_checks','edd_ifso_geo_license_num_of_checks')->
        set_field_by_option_name('deactivation_reason','edd_ifso_geo_license_deactivation_reason')->
        set_field('validation_transient',new LicenseData\LicenseFieldTransient('validation_transient','ifso_transient_geo_license_validation'));
        return $ld;
    }

    protected function init_license_type() : string {
        return 'geo';
    }

    protected function init_plans() : array {
        return array(9132, 9129, 8261, 6530, 4872638);
    }

	// Helper function that returns the proper messages to the client
	// according to the result received from the API (resides in $license_data)
	protected function edd_api_get_error_message($license_data) {
		$message = false;
        if(!is_object($license_data)) return false;
		if ( false === $license_data->success ) {
			if ( isset($license_data->error_message) &&
				 !empty($license_data->error_message) ) {
				return $license_data->error_message;
			}
			switch( $license_data->error ) {
			    //Replaced HTML links with !!!LINKSTART!!!//!!!LINKEND!!! formatting, since we're filtering the payload to prevent XSS
				case 'expired' :
					$message = sprintf(
						__( 'Your license key has expired on %s. !!!LINKSTART!!!https://www.if-so.com/plans/geolocation-plans?utm_source=Plugin&utm_medium=direct&utm_campaign=licenseExpired&utm_term=LicensePage&utm_content=a!!!LINKEND!!!!!!LINKTEXT!!!Click here to get a new license!!!LINKTEXTEND!!!' ),
                        date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires, current_time( 'timestamp' ) ) )
					);
					break;
				case 'revoked' :
				case 'disabled' :
					$message = __( 'The license key has been disabled.' );
					break;
				case 'missing' :
					$message = __( 'Invalid license key.' );
					break;
				case 'invalid' :
				case 'site_inactive' :
					$message = __( 'Your license is not active for this URL.' );
					break;
				case 'item_name_mismatch' :
					$message = __( 'This appears to be an invalid license key for the selected item.' );
					break;
				case 'invalid_item_id':
					// $message = __( 'This appears to be an invalid license key for `Free Tiral` product.' );
					$message = __( 'The license key is invalid for this version of the plugin. Make sure you have updated the plugin. If the problem persists, please contact us at support@if-so.com.' );
					break;
				case 'no_activations_left':
					$message = __( 'This license key is currently active in another domain. !!!LINKSTART!!!https://www.if-so.com/plans/geolocation-plans?ifso=pro&utm_source=Plugin&utm_medium=LicenseErrors&utm_campaign=LicenseAlreadyActive!!!LINKEND!!!!!!LINKTEXT!!!Click here to get a new license!!!LINKTEXTEND!!!' );

					break;
				case 'domain_already_has_key':
					$message = __( 'A free trial license has already been used for this domain. !!!LINKSTART!!!https://www.if-so.com/plans/geolocation-plans?ifso=pro&utm_source=Plugin&utm_medium=TrialEnded&utm_campaign=LicensePage!!!LINKEND!!!!!!LINKTEXT!!!Click here to get a pro license.!!!LINKTEXTEND!!!' );
					break;
				default :
					break;
			}
		}
		return $message;
	}

	/*
	 *	Runs when the user clicks on "Activate License" button
	 *	registered via 'admin_init'
	 */
	public function edd_ifso_activate_license() {
		// listen for our activate button to be clicked
		if( isset( $_POST['edd_ifso_geo_license_activate'] ) ) {
			// run a quick security check
		 	if( ! check_admin_referer( 'edd_ifso_nonce', 'edd_ifso_nonce' ) )
				return; // get out if we didn't click the Activate button
			// retrieve the license from the database
			$db_license = trim( $this->license_data->get_field_value('license_key') );
            $license = !empty($db_license) && substr(trim( $_POST["edd_ifso_license_key"] ), -5) === substr( $db_license , -5) ? $db_license : trim( $_POST["edd_ifso_license_key"] );
			if ($db_license != $license)
                $this->license_data->delete_field_value('license_status');
			// save the license in the database
            $this->license_data->update_field_value('license_key', sanitize_text_field($license));
			$license_data = $this->try_to_activate_license($license, NULL);
			$message = '';
			if ($license_data instanceof \stdClass)
				$message = $this->edd_api_get_error_message($license_data);

			//check if user entered a license of a wrong type(pro)
			$passed_license_id = $this->license_data->get_field_value('item_id');
            $wrongLicenseGoto = 'false';
			if(empty($message) && in_array($passed_license_id, \IfSo\Services\LicenseService\LicenseService::get_instance()->get_plans())){
                $message .= __("The license key you tried to activate is not a geolocation license key.",'if-so') . " Activate the license in in the designated “pro” license field or click !!!LINKSTART!!!https://www.if-so.com/plans/geolocation-plans/?utm_source=Plugin&utm_medium=message&utm_campaign=geolocation&utm_term=Prolicense&utm_content=a!!!LINKEND!!!!!!LINKTEXT!!!here!!!LINKTEXTEND!!! to purchase a geolocation license.";
                $wrongLicenseGoto = 'pro';
            }

			$base_url = admin_url('admin.php?page=' . EDD_IFSO_PLUGIN_LICENSE_PAGE);

			if ( ! empty( $message ) ) {
                $this->license_data->update_field_value('license_key',$db_license);
				$redirect = add_query_arg( array( 'sl_activation' => 'false', 'message' => urlencode( $message ),'license_type'=>'geo',
					'method' => 'license','wrongLicenseGoto'=>$wrongLicenseGoto ), $base_url . '#ifso-info-tab-geo' );//redirect back to the geo page's geolocation license tab
				wp_redirect( $redirect );
				exit();
			}

            $this->license_data->update_field_value( 'expires', $license_data->expires );
            $this->license_data->update_field_value( 'license_status', $license_data->license );
            update_option( 'edd_ifso_geo_license_item_name', $license_data->item_name );
            update_option( 'edd_ifso_had_geo_license', true );
            $this->license_data->update_field_value( 'is_lifetime', ($license_data->expires == 'lifetime') );
			delete_option( 'edd_ifso_user_deactivated_geo_license' );
			$redirect = add_query_arg( array( 'method' => 'license' ), $base_url );
			wp_redirect( $redirect );
			exit();
		}
	}

	public function edd_ifso_deactivate_license() {
		// listen for our activate button to be clicked
		if( isset( $_POST['edd_ifso_geo_license_deactivate'] ) ) {
			// run a quick security check
		 	if( ! check_admin_referer( 'edd_ifso_nonce', 'edd_ifso_nonce' ) )
				return; // get out if we didn't click the Activate button
			$license = $this->license_data->get_field_value( 'license_key' );
			$item_id = $this->license_data->get_field_value( 'item_id' );
			$license_data = $this->edd_api_deactivate_item($license, $item_id);
			$base_url = admin_url( 'admin.php?page=' . EDD_IFSO_PLUGIN_LICENSE_PAGE );

			if ($license_data->success) {
				if( $license_data->license == 'deactivated' ) {
                    $this->license_data->delete_field_value( 'license_status' );
                    $this->license_data->delete_field_value( 'item_id' );
                    $this->license_data->delete_field_value( 'is_lifetime' );
                    $this->license_data->delete_field_value( 'validation_transient' );

                    update_option( 'edd_ifso_user_deactivated_geo_license', true );
				}
				$redirect = add_query_arg( array( 'method' => 'license' ), $base_url );
				wp_redirect( $redirect );
				exit();
			}
            $message = 'Something went wrong. Please try again or contact support at support@if-so.com';
			if (!($license_data instanceof \stdClass))
				$message = $license_data;
            if(isset($license_data->expires) && $license_data->expires < time()){  //Allow license deactivation if the key has expired
                $this->license_data->delete_field_value( 'license_status' );    //(other cases where deactivation has to happen despite an error?)
                $this->license_data->delete_field_value( 'item_id' );
                $this->license_data->delete_field_value( 'is_lifetime' );
                $this->license_data->delete_field_value( 'validation_transient' );

                update_option( 'edd_ifso_user_deactivated_geo_license', true );
                $message = 'Expired Geo license has been deactivated';
            }
			$redirect = add_query_arg( array( 'sl_activation' => 'false', 'message' => urlencode( $message ), 'license_type'=>'geo',
				'method' => 'license' ), $base_url );
			wp_redirect( $redirect );
			exit();
		}
	}
}