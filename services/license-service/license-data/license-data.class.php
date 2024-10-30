<?php

namespace IfSo\Services\LicenseService\LicenseData;

require_once __DIR__ . "/license-field-option.class.php";
require_once __DIR__ . "/license-field-transient.class.php";

/**
 * A collection of data fields needed for License services
 *
 * @since      1.9
 * @package    IfSo
 * @subpackage IfSo/Services/LicenseService
 * @author     Nick Martianov
 */
class LicenseData{
    protected LicenseFieldBase $license_key;
    protected LicenseFieldBase $license_status;
    protected LicenseFieldBase $item_id;
    protected LicenseFieldBase $is_lifetime;
    protected LicenseFieldBase $expires;
    protected LicenseFieldBase $num_of_checks;
    protected LicenseFieldBase $deactivation_reason;
    protected LicenseFieldTransient $validation_transient;

    public static function createFromArray($fieldsArr) {
        $lic = new self();
        foreach($fieldsArr as $field){
            $fname = $field->get_name();
            $lic->set_field($fname,$field);
        }
        return $lic;
    }

    public function set_field($fname,$val){
        if(property_exists($this,$fname))
            $this->$fname = $val;
        return $this;
    }

    public function set_field_by_option_name($fname,$optname){
        return $this->set_field($fname,new LicenseFieldOption($fname,$optname));
    }

    public function get_field_value($fname) {
        if(isset($this->$fname))
            return $this->$fname->get_value();
    }

    public function update_field_value($fname,$newval):void {
        if(property_exists($this,$fname) && !empty($this->$fname))
            $this->$fname->set_value($newval);
    }

    public function delete_field_value($fname):void {
        if(!empty($this->$fname)){
            $this->$fname->delete();
        }
    }

    public function delete_all_fields_values($skip_transients=true) {
        foreach ($this as $fname=>$field){
            if($field instanceof LicenseFieldBase){
                if(!$skip_transients || $field instanceof LicenseFieldTransient)
                    $this->delete_field_value($fname);
            }
        }
    }

    public function is_license_valid() {
        $status  = $this->license_status->get_value();
        return ( $status === 'valid' );
    }

    public function get_license_key() {
        return $this->license_key->get_value();
    }
}