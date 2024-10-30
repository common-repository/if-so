<?php

namespace IfSo\Services\LicenseService\LicenseData;

/**
 * Base class for persistent license data fields
 *
 * @since      1.9
 * @author     Nick Martianov
 */

abstract class LicenseFieldBase {
    protected $option_name;
    protected $name;
    protected $value;

    /**
     * @param $name - "Readable" key used as a property name in LicenseData
     * @param $option_name - Globally unique key, used for wp option name, transient name, etc
     * @param $value - Field value
     */
    public function __construct($name, $option_name, $value=null) {
        $this->option_name = $option_name;
        $this->name = $name;
        $this->value = $value;
    }

    public function get_name() {
        return $this->name;
    }

    public function get_option_name() {
        return $this->option_name;
    }

    public function get_value() {
        if($this->value===null)
            $this->value = $this->get_stored_value($this->option_name);
        return $this->value;
    }

    public function set_value($value) {
        $this->value = $value;
        $this->set_stored_value($this->option_name,$value);
        $this->value_changed();
    }

    public function delete(){
        $this->delete_stored_value();
        unset($this->value);
        $this->value_changed();
    }

    protected function value_changed() {
        do_action('ifso_license_data_value_changed',$this);
    }

    abstract function get_stored_value($opt);
    abstract function set_stored_value($opt,$val);
    abstract function delete_stored_value();
}