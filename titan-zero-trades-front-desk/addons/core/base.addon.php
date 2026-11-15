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
			return $this->tr_known( titan_zero_front_desk_translate_legacy_value( $this->description ) );
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
			global $tz_addons_active_list;
            if (!is_array($tz_addons_active_list)) $tz_addons_active_list = TZFD_Scoped_Store::get_for_boot( 'tz_fd_addons_active_list', array() );
			return in_array( $this->get_addon_id(), $tz_addons_active_list );
		}
        
        // Add-on descriptions are set during plugins_loaded, before translations can load.
        // Keep the English text here; tr_known() translates it when the description is shown.
        public function tr_apply($text, $domain)
		{
			return $text;
		}

        public function tr_known($text)
		{
			$known = array(
                'Chat widget that books field-service jobs from plain-language descriptions. Multi-provider AI, safe fallback, human escalation, usage tracking.' => __( 'Chat widget that books field-service jobs from plain-language descriptions. Multi-provider AI, safe fallback, human escalation, usage tracking.', 'titan-zero-trades-front-desk' ),
                'The add-on limits how many appointments a customer can select in a single booking.' => __( 'The add-on limits how many appointments a customer can select in a single booking.', 'titan-zero-trades-front-desk' ),
                'The add-on cancels pending bookings that are not approved within a set number of hours.' => __( 'The add-on cancels pending bookings that are not approved within a set number of hours.', 'titan-zero-trades-front-desk' ),
                'Alternative time slot selection interface that does not display a calendar, but only available dates.' => __( 'Alternative time slot selection interface that does not display a calendar, but only available dates.', 'titan-zero-trades-front-desk' ),
                'The add-on adds a widget to display a list of upcoming appointments in the dashboard welcome page.' => __( 'The add-on adds a widget to display a list of upcoming appointments in the dashboard welcome page.', 'titan-zero-trades-front-desk' ),
                'The add-on adds support for exporting iCal files.' => __( 'The add-on adds support for exporting iCal files.', 'titan-zero-trades-front-desk' ),
                'The add-on keeps a cache of the available times for the booking form display.' => __( 'The add-on keeps a cache of the available times for the booking form display.', 'titan-zero-trades-front-desk' ),
                'Guided setup for the AI suite. Walks you from install to first answered call with a live test at every step. Uses the WordPress AI Client and provider connectors configured by the site administrator.' => __( 'Guided setup for the AI suite. Walks you from install to first answered call with a live test at every step. Uses the WordPress AI Client and provider connectors configured by the site administrator.', 'titan-zero-trades-front-desk' ),
                'The add-on logs booking additions, status changes and deletions, and shows the latest entries.' => __( 'The add-on logs booking additions, status changes and deletions, and shows the latest entries.', 'titan-zero-trades-front-desk' ),
                'Guided setup: choose your vertical, pick services from your vertical pack, your calendars, other booking plugins, a CSV file or a web page, then enter your details.' => __( 'Guided setup: choose your vertical, pick services from your vertical pack, your calendars, other booking plugins, a CSV file or a web page, then enter your details.', 'titan-zero-trades-front-desk' ),
                'The add-on requires customers to enter a password before a booking is accepted.' => __( 'The add-on requires customers to enter a password before a booking is accepted.', 'titan-zero-trades-front-desk' ),
                'Front Desk presets for 65 verticals and services. Install one to set your service types and reference answers. Review every answer and price before installing it.' => __( 'Front Desk presets for 65 verticals and services. Install one to set your service types and reference answers. Review every answer and price before installing it.', 'titan-zero-trades-front-desk' ),
                'The add-on refuses public bookings made with blacklisted email addresses or domains.' => __( 'The add-on refuses public bookings made with blacklisted email addresses or domains.', 'titan-zero-trades-front-desk' ),
                'The add-on emails customers a follow-up a set number of hours after each appointment ends.' => __( 'The add-on emails customers a follow-up a set number of hours after each appointment ends.', 'titan-zero-trades-front-desk' ),
                'When a call is missed, text the caller a booking link. Works with any phone system that can send a webhook and any SMS gateway with an HTTPS endpoint.' => __( 'When a call is missed, text the caller a booking link. Works with any phone system that can send a webhook and any SMS gateway with an HTTPS endpoint.', 'titan-zero-trades-front-desk' ),
                'The add-on emails customers a reminder a set number of hours before each approved appointment.' => __( 'The add-on emails customers a reminder a set number of hours before each approved appointment.', 'titan-zero-trades-front-desk' ),
                'The add-on adds an SMS button below the booking form that opens a text message to the configured number.' => __( 'The add-on adds an SMS button below the booking form that opens a text message to the configured number.', 'titan-zero-trades-front-desk' ),
                'The add-on sends signed booking events to an HTTPS endpoint you control.' => __( 'The add-on sends signed booking events to an HTTPS endpoint you control.', 'titan-zero-trades-front-desk' ),
                'The add-on adds a WhatsApp chat button below the booking form.' => __( 'The add-on adds a WhatsApp chat button below the booking form.', 'titan-zero-trades-front-desk' ),
            );
            if ( is_string( $text ) && isset( $known[ $text ] ) ) { return $known[ $text ]; }
            return $text;
		}


	} // End Class
}
