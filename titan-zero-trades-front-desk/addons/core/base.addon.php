<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if( !class_exists( 'TZFD_BaseAddon' ) )
{
    class TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID;
		public $badges = array();
		protected $name;
		protected $description;
        public $category = ' Other add-ons';
        public $help = '';

		public function get_addon_id()
		{
			return $this->addonID;
		}

		public function get_addon_name()
		{
			return $this->name;
		}

		public function get_addon_description()
		{
			return titan_zero_front_desk_translate_legacy_value( $this->description );
		}

		public function get_addon_form_settings( $form_id )
		{
			return '';
		}

		public function get_addon_settings()
		{
			return '';
		}

		public function addon_is_active()
		{
			global $tzfd_addons_active_list;
            if (!is_array($tzfd_addons_active_list)) $tzfd_addons_active_list = get_option( 'tzfd_addons_active_list', array() );
			return in_array( $this->get_addon_id(), $tzfd_addons_active_list );
		}
        
        public function tr_apply($text, $domain)
		{
			return $text;
		}


	} // End Class
}
