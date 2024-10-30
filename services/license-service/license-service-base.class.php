<?php

namespace IfSo\Services\LicenseService;

require_once __DIR__ . '/license-data/license-data.class.php';

use IfSo\Services\LicenseService\LicenseData\LicenseData;

/**
 * Base class to be extended into license services for various license types.
 * Provides methods for communicating with the if-so license API as well as managing license records locally.
 *
 * @since      1.9
 * @package    IfSo
 * @subpackage IfSo/Services/LicenseService
 * @author     Nick Martianov
 */
abstract class LicenseServiceBase {
    private static array $subclasses = [];
	protected array $plans;
    protected int $num_of_retries_to_check_license = 8;
    protected int $interval_valid_license_check = (60 * 60 * 12);
    protected int $interval_invalid_license_check = (60 * 60 * 6);
    protected string $license_type;
    protected LicenseData $license_data;


	protected function __construct() {
        $this->license_data = $this->init_license_data();
        $this->license_type = $this->init_license_type();
        $this->plans = $this->init_plans();
	}

    abstract protected function init_license_data() : LicenseData;

    abstract protected function init_license_type() : string;

    abstract protected function init_plans() : array;

	public static function get_instance() {
        $class = get_called_class();

        if(!isset(self::$subclasses[$class])){
            self::$subclasses[$class] = new static();
        }

        return self::$subclasses[$class];
	}

	// Helper function that sends request to the license endpoint
	// with the given `action` (e.g `action` might be `check_license`)
	protected function query_ifso_api($edd_action, $license, $item_id) {
			// data to send in our API request
			$api_payload = array(
				'edd_action' => $edd_action, //'activate_license',
				'license'    => $license,
				'item_id'  => $item_id, // the name of our product in EDD
				'url'        => home_url(),
                'license_type' => $this->license_type
			);

			$message = false;
			$license_data = false;
			// Call the custom API.
			$response = wp_remote_post( EDD_IFSO_STORE_URL, array( 'timeout' => 15, 'sslverify' => false, 'body' => $api_payload ) );

			// make sure the response came back okay
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				if ( is_wp_error( $response ) ) {
					$message = $response->get_error_message();
				} else {
					$message = __( 'An error occurred, please try again.' );
				}
			}
			else {
				$license_data = json_decode( wp_remote_retrieve_body( $response ) );
			}

			if (!$license_data)
				return $message;

			return $license_data;
	}

	// Helper function that returns the proper messages to the client
	// according to the result received from the API (resides in $license_data)
	 abstract protected function edd_api_get_error_message($license_data);

	protected function edd_api_activate_item($license, $item_id) {
		return $this->query_ifso_api('activate_license', $license, $item_id);
	}

	public function deactivate_license_request($license, $item_id) {
		return $this->query_ifso_api('deactivate_license', $license, $item_id);
	}

	// tries to deactivate $license with every plan,
	// starting from $item_id plan.
	protected function edd_api_deactivate_item($license, $item_id) {
		$license_data = NULL;
		if ( !empty($item_id) ) {
			$license_data = $this->deactivate_license_request($license, $item_id);
		}
		if ( isset($license_data->success) && $license_data->success )
		{
			return $license_data;
		}
		foreach ($this->plans as $key => $plan_id) {
			$license_data = $this->deactivate_license_request($license, $plan_id);
			if ($license_data instanceof \stdClass &&
				$license_data->success)
			{
				return $license_data;
			}
		}

		return $license_data;
	}

	protected function try_to_activate_license($license, $item_id) {
		$license_data = NULL;

		if ( $item_id ) {
			$license_data = $this->edd_api_activate_item( $license, $item_id );
			if ( !$this->is_item_id_invalid_or_mismatch($license_data) ) {
				return $license_data;
			}
		}

		foreach ($this->plans as $key => $plan_id) {
			if ($plan_id != $item_id) {
				$license_data = $this->edd_api_activate_item( $license, $plan_id );
				if ( !$this->is_item_id_invalid_or_mismatch($license_data) ) {
                    $this->license_data->update_field_value('item_id',$plan_id);
					return $license_data;
				}
			}
		}

		return $license_data;
	}

	protected function is_item_id_invalid_or_mismatch($license_data) {
		if (!isset($license_data->license)) return true;
		if (!isset($license_data->error)) return false;
		return ( $license_data->error == 'item_name_mismatch' ||
			     $license_data->error == 'invalid_item_id' );
	}

	protected function check_license_request($license, $item_id) {
		$license_data = NULL;
		if ( $item_id ) {
			$license_data = $this->send_check_license_request($license, $item_id);
		}
		if ( !$this->is_item_id_invalid_or_mismatch($license_data) ) {
			return $license_data;
		}
		foreach ($this->plans as $key => $plan_id) {
			if ($plan_id != $item_id) {
				$license_data = $this->send_check_license_request($license, $plan_id);
				if ( !$this->is_item_id_invalid_or_mismatch($license_data) ) {
                    $this->license_data->update_field_value('item_id',$plan_id);
					return $license_data;
				}
			}
		}
		return $license_data;
	}

	protected function send_check_license_request($license, $item_id) {
		$api_params = array(
			'edd_action' => 'check_license',
			'license' => $license,
			'item_id' => $item_id,
			'url'       => home_url()
		);
		// Call the custom API.
		$response = wp_remote_post( EDD_IFSO_STORE_URL, array( 'timeout' => 15, 'sslverify' => false, 'body' => $api_params ) );
		if ( is_wp_error( $response ) )
			return false;
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		return $license_data;
	}

	/*
	 *	Runs when the user clicks on "Activate License" button
	 *	registered via 'admin_init'
	 */
	abstract public function edd_ifso_activate_license();

	abstract public function edd_ifso_deactivate_license();

	public function activate_license($license, $item_id) {
		return $this->query_ifso_api('activate_license', $license, $item_id);
	}

	protected function handle_invalid_license($reason) {
        $this->license_data->update_field_value('num_of_checks',0);
        $this->license_data->update_field_value('deactivation_reason',$reason);

		$this->license_data->delete_field_value('license_status');
		$this->license_data->delete_field_value('item_id');
		// set interval as invalid license
        $this->license_data->update_field_value('validation_transient',$this->interval_invalid_license_check);
	}

	/* Responsible to check if the license is still valid */
    public function edd_ifso_is_license_valid() {

        if ( !$this->license_data->get_field_value( 'validation_transient' ) ) {
            if ( $this->license_data->get_field_value( 'is_lifetime' ) == 1 ) {
                $this->license_data->update_field_value( 'validation_transient',
                    60 * 60 * 24 * 7 );
                return;
            }
            $license = $this->license_data->get_field_value( 'license_key' );
            $item_id = $this->license_data->get_field_value( 'item_id' );
            $is_license_valid = ($this->license_data->get_field_value( 'license_status' ) === 'valid');
            // Validation
            if ( $license == false || $item_id == false ) {
                // the option is not set yet
                // set inetrval as valid license
                $this->license_data->update_field_value( 'validation_transient',
                    $this->interval_valid_license_check );
                return; // exit the function
            }
            // send request to IfSo server to check for license validy
            $license_data = $this->check_license_request($license, $item_id);
            // handle license status
            if ( $license_data &&
                $license_data->license == 'valid' ) {
                // the license is valid
                $this->license_data->update_field_value( 'num_of_checks', 0 );
                if ( !$is_license_valid ) {
                    // it was not activated
                    // thus we active it now
                    $license_data = $this->try_to_activate_license($license, $item_id);
                    if ( $license_data ) {
                        // update everything
                        $this->license_data->update_field_value( 'license_status', $license_data->license );
                        $this->license_data->update_field_value( 'expires', $license_data->expires );
                    }
                } else {
                    $this->license_data->update_field_value( 'expires', $license_data->expires );
                }
                // set inetrval as valid license
                $this->license_data->update_field_value( 'validation_transient',
                    $this->interval_valid_license_check );
            } else if ( $license_data &&
                $license_data->license == 'inactive' ) {
                // the license is inactive. so we try to activate it
                $this->license_data->update_field_value( 'num_of_checks', 0 );

                $license_data = $this->try_to_activate_license( $license, $item_id );
                if ( $license_data &&
                    $license_data == 'valid') {
                    $this->license_data->update_field_value( 'license_status', $license_data->license );
                    $this->license_data->update_field_value( 'expires', $license_data->expires );
                }
                // set inetrval as valid license
                $this->license_data->update_field_value( 'validation_transient',
                    $this->interval_valid_license_check );
            } else if ( $license_data &&
                $license_data->license == 'expired' ) {
                // the license is expired
                $this->handle_invalid_license("License expired with: " . json_encode( $license_data ));
            } else {
                // something else? if it happens X times in Y interval
                // then we deactivate
                // how many times did we check for validy?
                $num_of_checks = $this->license_data->get_field_value( 'num_of_checks' );
                if ( $num_of_checks == false ) { // first time check
                    $this->license_data->update_field_value( 'num_of_checks', 1 );
                    // set inetrval as valid license
                    $this->license_data->update_field_value( 'validation_transient',
                        $this->interval_valid_license_check );
                } else if ( $num_of_checks >= $this->num_of_retries_to_check_license ) {
                    // we tested for validation for enough time
                    // thus deactivating
                    $this->handle_invalid_license("Exceeded num of checks: $num_of_checks = " . $num_of_checks);
                } else {
                    // we didn't pass the num of retries we have
                    // so we add one and proceed as "valid" license
                    $this->license_data->update_field_value( 'num_of_checks', ($num_of_checks+1) );
                    // set inetrval as valid license
                    $this->license_data->update_field_value( 'validation_transient',
                        $this->interval_valid_license_check );
                }
            }
        }
    }
	public function clear_license() {
        $license_key = $this->license_data->get_license_key();
        $item_id = $this->license_data->get_field_value('item_id');
        if(!empty($license_key) && !empty($item_id))
            $this->deactivate_license_request($license_key,$item_id);
        $this->license_data->delete_all_fields_values(false);
    }


    public function is_license_valid() {
        return $this->license_data->is_license_valid();
    }

    public function get_plans() {
        return $this->plans;
    }
}