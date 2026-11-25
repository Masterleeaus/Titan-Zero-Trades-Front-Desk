<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/../../includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/../../includes/class-tzfd-admin-request-ids.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-stored-data.php';

require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-scoped-store.php';

if( !class_exists( 'TZFD_iCalExport' ) )
{
    class TZFD_iCalExport extends TZFD_BaseAddon
    {
        public function get_addon_settings()
        {
            return TZFD_Addon_Support::info_block( __( 'This add-on has no site-wide settings.', 'titan-zero-trades-front-desk' ) );
        }

        

        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-iCalExport";
		protected $name = "iCal Export Addon";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        private $timezones = array('Africa/Abidjan','Africa/Accra','Africa/Addis_Ababa','Africa/Algiers','Africa/Asmara','Africa/Bamako','Africa/Bangui','Africa/Banjul','Africa/Bissau','Africa/Blantyre','Africa/Brazzaville','Africa/Bujumbura','Africa/Cairo','Africa/Casablanca','Africa/Ceuta','Africa/Conakry','Africa/Dakar','Africa/Dar_es_Salaam','Africa/Djibouti','Africa/Douala','Africa/El_Aaiun','Africa/Freetown','Africa/Gaborone','Africa/Harare','Africa/Johannesburg','Africa/Juba','Africa/Kampala','Africa/Khartoum','Africa/Kigali','Africa/Kinshasa','Africa/Lagos','Africa/Libreville','Africa/Lome','Africa/Luanda','Africa/Lubumbashi','Africa/Lusaka','Africa/Malabo','Africa/Maputo','Africa/Maseru','Africa/Mbabane','Africa/Mogadishu','Africa/Monrovia','Africa/Nairobi','Africa/Ndjamena','Africa/Niamey','Africa/Nouakchott','Africa/Ouagadougou','Africa/Porto-Novo','Africa/Sao_Tome','Africa/Tripoli','Africa/Tunis','Africa/Windhoek','America/Adak','America/Anchorage','America/Anguilla','America/Antigua','America/Araguaina','America/Argentina/Buenos_Aires','America/Argentina/Catamarca','America/Argentina/Cordoba','America/Argentina/Jujuy','America/Argentina/La_Rioja','America/Argentina/Mendoza','America/Argentina/Rio_Gallegos','America/Argentina/Salta','America/Argentina/San_Juan','America/Argentina/San_Luis','America/Argentina/Tucuman','America/Argentina/Ushuaia','America/Aruba','America/Asuncion','America/Atikokan','America/Bahia','America/Bahia_Banderas','America/Barbados','America/Belem','America/Belize','America/Blanc-Sablon','America/Boa_Vista','America/Bogota','America/Boise','America/Cambridge_Bay','America/Campo_Grande','America/Cancun','America/Caracas','America/Cayenne','America/Cayman','America/Chicago','America/Chihuahua','America/Costa_Rica','America/Creston','America/Cuiaba','America/Curacao','America/Danmarkshavn','America/Dawson','America/Dawson_Creek','America/Denver','America/Detroit','America/Dominica','America/Edmonton','America/Eirunepe','America/El_Salvador','America/Fort_Nelson','America/Fortaleza','America/Glace_Bay','America/Goose_Bay','America/Grand_Turk','America/Grenada','America/Guadeloupe','America/Guatemala','America/Guayaquil','America/Guyana','America/Halifax','America/Havana','America/Hermosillo','America/Indiana/Indianapolis','America/Indiana/Knox','America/Indiana/Marengo','America/Indiana/Petersburg','America/Indiana/Tell_City','America/Indiana/Vevay','America/Indiana/Vincennes','America/Indiana/Winamac','America/Inuvik','America/Iqaluit','America/Jamaica','America/Juneau','America/Kentucky/Louisville','America/Kentucky/Monticello','America/Kralendijk','America/La_Paz','America/Lima','America/Los_Angeles','America/Lower_Princes','America/Maceio','America/Managua','America/Manaus','America/Marigot','America/Martinique','America/Matamoros','America/Mazatlan','America/Menominee','America/Merida','America/Metlakatla','America/Mexico_City','America/Miquelon','America/Moncton','America/Monterrey','America/Montevideo','America/Montserrat','America/Nassau','America/New_York','America/Nipigon','America/Nome','America/Noronha','America/North_Dakota/Beulah','America/North_Dakota/Center','America/North_Dakota/New_Salem','America/Nuuk','America/Ojinaga','America/Panama','America/Pangnirtung','America/Paramaribo','America/Phoenix','America/Port_of_Spain','America/Port-au-Prince','America/Porto_Velho','America/Puerto_Rico','America/Punta_Arenas','America/Rainy_River','America/Rankin_Inlet','America/Recife','America/Regina','America/Resolute','America/Rio_Branco','America/Santarem','America/Santiago','America/Santo_Domingo','America/Sao_Paulo','America/Scoresbysund','America/Sitka','America/St_Barthelemy','America/St_Johns','America/St_Kitts','America/St_Lucia','America/St_Thomas','America/St_Vincent','America/Swift_Current','America/Tegucigalpa','America/Thule','America/Thunder_Bay','America/Tijuana','America/Toronto','America/Tortola','America/Vancouver','America/Whitehorse','America/Winnipeg','America/Yakutat','America/Yellowknife','Antarctica/Casey','Antarctica/Davis','Antarctica/DumontDUrville','Antarctica/Macquarie','Antarctica/Mawson','Antarctica/McMurdo','Antarctica/Palmer','Antarctica/Rothera','Antarctica/Syowa','Antarctica/Troll','Antarctica/Vostok','Arctic/Longyearbyen','Asia/Aden','Asia/Almaty','Asia/Amman','Asia/Anadyr','Asia/Aqtau','Asia/Aqtobe','Asia/Ashgabat','Asia/Atyrau','Asia/Baghdad','Asia/Bahrain','Asia/Baku','Asia/Bangkok','Asia/Barnaul','Asia/Beirut','Asia/Bishkek','Asia/Brunei','Asia/Chita','Asia/Choibalsan','Asia/Colombo','Asia/Damascus','Asia/Dhaka','Asia/Dili','Asia/Dubai','Asia/Dushanbe','Asia/Famagusta','Asia/Gaza','Asia/Hebron','Asia/Ho_Chi_Minh','Asia/Hong_Kong','Asia/Hovd','Asia/Irkutsk','Asia/Jakarta','Asia/Jayapura','Asia/Jerusalem','Asia/Kabul','Asia/Kamchatka','Asia/Karachi','Asia/Kathmandu','Asia/Khandyga','Asia/Kolkata','Asia/Krasnoyarsk','Asia/Kuala_Lumpur','Asia/Kuching','Asia/Kuwait','Asia/Macau','Asia/Magadan','Asia/Makassar','Asia/Manila','Asia/Muscat','Asia/Nicosia','Asia/Novokuznetsk','Asia/Novosibirsk','Asia/Omsk','Asia/Oral','Asia/Phnom_Penh','Asia/Pontianak','Asia/Pyongyang','Asia/Qatar','Asia/Qostanay','Asia/Qyzylorda','Asia/Riyadh','Asia/Sakhalin','Asia/Samarkand','Asia/Seoul','Asia/Shanghai','Asia/Singapore','Asia/Srednekolymsk','Asia/Taipei','Asia/Tashkent','Asia/Tbilisi','Asia/Tehran','Asia/Thimphu','Asia/Tokyo','Asia/Tomsk','Asia/Ulaanbaatar','Asia/Urumqi','Asia/Ust-Nera','Asia/Vientiane','Asia/Vladivostok','Asia/Yakutsk','Asia/Yangon','Asia/Yekaterinburg','Asia/Yerevan','Atlantic/Azores','Atlantic/Bermuda','Atlantic/Canary','Atlantic/Cape_Verde','Atlantic/Faroe','Atlantic/Madeira','Atlantic/Reykjavik','Atlantic/South_Georgia','Atlantic/St_Helena','Atlantic/Stanley','Australia/Adelaide','Australia/Brisbane','Australia/Broken_Hill','Australia/Darwin','Australia/Eucla','Australia/Hobart','Australia/Lindeman','Australia/Lord_Howe','Australia/Melbourne','Australia/Perth','Australia/Sydney','Europe/Amsterdam','Europe/Andorra','Europe/Astrakhan','Europe/Athens','Europe/Belgrade','Europe/Berlin','Europe/Bratislava','Europe/Brussels','Europe/Bucharest','Europe/Budapest','Europe/Busingen','Europe/Chisinau','Europe/Copenhagen','Europe/Dublin','Europe/Gibraltar','Europe/Guernsey','Europe/Helsinki','Europe/Isle_of_Man','Europe/Istanbul','Europe/Jersey','Europe/Kaliningrad','Europe/Kiev','Europe/Kirov','Europe/Lisbon','Europe/Ljubljana','Europe/London','Europe/Luxembourg','Europe/Madrid','Europe/Malta','Europe/Mariehamn','Europe/Minsk','Europe/Monaco','Europe/Moscow','Europe/Oslo','Europe/Paris','Europe/Podgorica','Europe/Prague','Europe/Riga','Europe/Rome','Europe/Samara','Europe/San_Marino','Europe/Sarajevo','Europe/Saratov','Europe/Simferopol','Europe/Skopje','Europe/Sofia','Europe/Stockholm','Europe/Tallinn','Europe/Tirane','Europe/Ulyanovsk','Europe/Uzhgorod','Europe/Vaduz','Europe/Vatican','Europe/Vienna','Europe/Vilnius','Europe/Volgograd','Europe/Warsaw','Europe/Zagreb','Europe/Zaporozhye','Europe/Zurich','Indian/Antananarivo','Indian/Chagos','Indian/Christmas','Indian/Cocos','Indian/Comoro','Indian/Kerguelen','Indian/Mahe','Indian/Maldives','Indian/Mauritius','Indian/Mayotte','Indian/Reunion','Pacific/Apia','Pacific/Auckland','Pacific/Bougainville','Pacific/Chatham','Pacific/Chuuk','Pacific/Easter','Pacific/Efate','Pacific/Fakaofo','Pacific/Fiji','Pacific/Funafuti','Pacific/Galapagos','Pacific/Gambier','Pacific/Guadalcanal','Pacific/Guam','Pacific/Honolulu','Pacific/Kanton','Pacific/Kiritimati','Pacific/Kosrae','Pacific/Kwajalein','Pacific/Majuro','Pacific/Marquesas','Pacific/Midway','Pacific/Nauru','Pacific/Niue','Pacific/Norfolk','Pacific/Noumea','Pacific/Pago_Pago','Pacific/Palau','Pacific/Pitcairn','Pacific/Pohnpei','Pacific/Port_Moresby','Pacific/Rarotonga','Pacific/Saipan','Pacific/Tahiti','Pacific/Tarawa','Pacific/Tongatapu','Pacific/Wake','Pacific/Wallis');

        /** Keeps the posted value only when it is one of the allowed values; otherwise the fallback. */
        private static function allowed_value( $value, array $allowed, $fallback = '' )
        {
            return in_array( $value, $allowed, true ) ? $value : $fallback;
        }

        /** The offsets offered by the iCal timezone difference select, as stored (trimmed). */
        private static function time_zone_modify_values()
        {
            $values = array( '' );
            for ( $i = -23; $i < 24; $i++ ) {
                $sign     = ( $i <= 0 ) ? '' : '+';
                $values[] = $sign . $i . ':30 hours';
                $values[] = $sign . $i . ' hours';
            }
            return $values;
        }

        /**
         * Daylight saving is on unless the stored value is 'false'. A blank value (older rows) shows as "Yes" in the form, so it counts as on here too.
         */
        private static function observes_day_light( $settings )
        {
            return $settings->observe_day_light !== 'false';
        }

        /** The settings the form shows before anything is saved. Used when a form has no saved row. */
        private static function default_settings()
        {
            return (object) array(
                'observe_day_light'    => 'true',
                'ical_daylight_zone'   => 'EUROPE',
                'cal_time_zone_modify' => '',
                'attachical'           => '',
                'cal_tzid'             => '',
                'base_summary'         => __( 'Booking for', 'titan-zero-trades-front-desk' ) . ' %email%',
                'base_description'     => __( 'Booking for', 'titan-zero-trades-front-desk' ) . ' %email%',
                'ical_uselocal'        => '0',
                'ical_organizer'       => '0',
                'ical_organizername'   => '',
                'ical_organizeremail'  => '',
            );
        }

        /**
         * Adds any setting column that an older row does not have yet (the organizer columns are added when the form is opened), so reads never hit an undefined property. Present values are kept as they are.
         */
        private static function complete_settings( $settings )
        {
            foreach ( self::default_settings() as $key => $value ) {
                if ( ! property_exists( $settings, $key ) ) { $settings->$key = $value; }
            }
            return $settings;
        }

        /** Returns the wall-clock time "Y-m-d 02:00" of the nth Sunday of a month, using plain date arithmetic. */
        private static function sunday_at_two( $year, $month, $nth )
        {
            $first_weekday = (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, 1, (int) $year ) ); // 0 = Sunday
            $day           = 1 + ( ( 7 - $first_weekday ) % 7 ) + 7 * ( $nth - 1 );
            return sprintf( '%04d-%02d-%02d 02:00', (int) $year, $month, $day );
        }

        /** Escapes text for an iCal TEXT value (RFC 5545): backslash, semicolon and comma are escaped, and line breaks become \n. */
        /** Reject malformed stored appointment times before building calendar lines. */
        private static function valid_appointment( $appointment )
        {
            if ( ! is_array( $appointment ) || ! is_string( $appointment['date'] ?? null ) ||
                 ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $appointment['date'], $m ) ||
                 ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ||
                 ! is_string( $appointment['slot'] ?? null ) ||
                 ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(?:\s*\/\s*([01]\d|2[0-3]):[0-5]\d)?$/D', $appointment['slot'] ) ||
                 ! is_scalar( $appointment['duration'] ?? null ) ||
                 ! preg_match( '/^\d{1,4}$/D', (string) $appointment['duration'] ) ) {
                return false;
            }
            return (int) $appointment['duration'] > 0 && (int) $appointment['duration'] <= 1440;
        }

        /** iCalendar TZID is a parameter token, not HTML or arbitrary saved text. */
        private static function safe_tzid( $value )
        {
            return is_string( $value ) && in_array( $value, timezone_identifiers_list(), true ) ? $value : '';
        }

        /** UID must not contain carriage returns, newlines or delimiter injection. */
        private static function safe_uid( $id, $index, $host, $suffix = '' )
        {
            $host = is_string( $host ) ? strtolower( $host ) : '';
            if ( ! preg_match( '/^[a-z0-9.-]{1,253}$/D', $host ) ) { $host = 'localhost'; }
            return 'uid' . absint( $id ) . '_' . absint( $index ) . '@' . $host . absint( $suffix );
        }

        private static function ical_escape_text( $value )
        {
            $value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
            return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $value );
        }

        /** Escapes a value for a quoted iCal parameter such as CN (RFC 5545): double quotes are removed and line breaks become spaces. */
        private static function ical_escape_param( $value )
        {
            return str_replace( array( '"', "\r", "\n" ), array( '', ' ', ' ' ), (string) $value );
        }

        /**
         * Moves a booking one hour earlier while daylight saving time applies in the chosen zone.
         * The feed and the notification attachment both use this, so they give the same times.
         * When a time zone name is set (cal_tzid) the time is written as local time and the zone carries the offset (RFC 5545 3.3.5, form 3), so it is not moved again.
         */
        private static function daylight_adjusted( $datetime, $settings )
        {
            if ( (string) ( $settings->cal_tzid ?? '' ) !== '' )
                return $datetime;

            $full_date = gmdate("Ymd",strtotime($datetime.$settings->cal_time_zone_modify));
            $year = substr($full_date,0,4);

            // Rules: IANA tz database, https://www.iana.org/time-zones (files australasia and europe, northamerica).
            $zone = strtoupper( (string) $settings->ical_daylight_zone );
            if ($zone == '') $zone = 'EUROPE'; // A blank value (older rows) is shown as Europe in the form.

            // Queensland, Western Australia and the Northern Territory do not observe daylight saving. The stored time zone name (cal_tzid) tells them apart.
            if ($zone == 'AU' && in_array( (string) ( $settings->cal_tzid ?? '' ), array( 'Australia/Brisbane', 'Australia/Lindeman', 'Australia/Perth', 'Australia/Eucla', 'Australia/Darwin' ), true ))
                return $datetime;

            if ($zone == 'USA' || ! in_array( $zone, array( 'AU', 'NZ', 'EUROPE' ), true ))
            {
                // USA: DST from 2:00 standard time on the second Sunday of March to 2:00 daylight time on the first Sunday of November.
                // Compared as local wall-clock times, so the change happens at 2:00.
                $wall = gmdate("Y-m-d H:i",strtotime($datetime.$settings->cal_time_zone_modify));
                $in_dst = $wall >= self::sunday_at_two( $year, 3, 2 ) && $wall < self::sunday_at_two( $year, 11, 1 );
            } else {
                if ($zone == 'AU')
                {
                    // Victoria, NSW, Tasmania, ACT: DST from first Sunday October to first Sunday April (tzdata AV, AN, AS, AT; current since 2008).
                    $dst_start = strtotime('first Sunday of October '.$year.' GMT'); // First Sunday October
                    $dst_stop = strtotime('first Sunday of April '.$year.' GMT'); // First Sunday April
                } else if ($zone == 'NZ')
                {
                    // New Zealand: DST from last Sunday September to first Sunday April (tzdata NZ; current since 2008).
                    $dst_start = strtotime('last Sunday of September '.$year.' GMT'); // Last Sunday September
                    $dst_stop = strtotime('first Sunday of April '.$year.' GMT'); // First Sunday April
                } else
                {
                    $dst_start = strtotime('last Sunday GMT', strtotime("1 April $year GMT")); // Last Sunday March
                    $dst_stop = strtotime('last Sunday GMT', strtotime("1 November $year GMT")); // Last Sunday October
                }

                // Southern hemisphere windows run across New Year (start in October, end in April), so the start falls after the end.
                if ($dst_start < $dst_stop)
                    $in_dst = $full_date >= gmdate("Ymd",$dst_start) && $full_date < gmdate("Ymd",$dst_stop);
                else
                    $in_dst = $full_date >= gmdate("Ymd",$dst_start) || $full_date < gmdate("Ymd",$dst_stop);
            }

            if ($in_dst)
                return gmdate("Y-m-d H:i",strtotime($datetime." -1 hour"));
            return $datetime;
        }

		public function get_addon_form_settings( $form_id )
		{
			global $wpdb, $tzfd_plugin;
            $form_id = absint( $form_id );
            $company_id = TZFD_Scoped_Store::company_id();
            if ( ! $form_id || ! $company_id || TZFD_Scoped_Store::company_for_form( $form_id ) !== $company_id ) {
                return '';
            }

            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'attachical');
            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'base_summary');
            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'base_description');
            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'cal_tzid');
            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'ical_uselocal');
            
            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'ical_organizer');
            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'ical_organizername');
            $tzfd_plugin->add_field_verify ($wpdb->prefix.$this->form_table, 'ical_organizeremail');

			// Insertion in database
			if(
				isset( $_POST["anonce"] ) && is_string( $_POST['anonce'] ) && current_user_can( 'tz_fd_access' ) && wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_admin') && isset( $_POST[ 'TZFD_icalexport_id' ] ) && TZFD_Admin_Request_Ids::positive( tzfd_request_text( 'post', 'TZFD_icalexport_id' ) ) === $form_id
			)
			{
			    // The saved row is updated in place by save_settings_row(); it is never deleted before the new values are written.
				self::save_settings_row(
								$wpdb->prefix.$this->form_table,
								array(
									'formid' => $form_id,
									'company_id' => $company_id,

									'observe_day_light'	 => self::allowed_value( tzfd_request_text( 'post', "observe_day_light" ), array( 'true', 'false' ) ),
									'ical_daylight_zone'	 => self::allowed_value( tzfd_request_text( 'post', "ical_daylight_zone" ), array( 'EUROPE', 'USA', 'AU', 'NZ' ) ),
									'cal_time_zone_modify'	 => self::allowed_value( tzfd_request_text( 'post', "cal_time_zone_modify" ), self::time_zone_modify_values() ),
                                    'attachical'	 => self::allowed_value( tzfd_request_text( 'post', "attachical" ), array( '0', '1' ) ),
                                    'base_summary'	 => stripslashes_deep($tzfd_plugin->sanitize(tzfd_request_text( 'post', "base_summary" ))),
                                    'base_description'	 => stripslashes_deep($tzfd_plugin->sanitize(tzfd_request_text( 'post', "base_description" ))),
                                    'cal_tzid'	 => self::allowed_value( tzfd_request_text( 'post', "cal_tzid" ), array_merge( array( '' ), $this->timezones ) ),
                                    'ical_uselocal'	 => self::allowed_value( tzfd_request_text( 'post', "ical_uselocal" ), array( '0', '1' ) ),
                                    'ical_organizer'	 => self::allowed_value( tzfd_request_text( 'post', "ical_organizer" ), array( '0', '1' ) ),
                                    'ical_organizername'	 => tzfd_request_text( 'post', "ical_organizername" ),
                                    'ical_organizeremail'	 => sanitize_email( tzfd_request_text( 'post', "ical_organizeremail" ) )

								),
								array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
							);
			}

			$tzfd_local_rows = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM %i WHERE formid=%d AND company_id=%d", $wpdb->prefix.TZFD_Tables::ICALEXPORT, $form_id, $company_id )
					);
			if (!count($tzfd_local_rows))
			{
			    $row["observe_day_light"] = "true";
			    $row["ical_daylight_zone"] = "EUROPE";
                $row["cal_time_zone_modify"] = '';
                $row["attachical"] = '';
                $row["cal_tzid"] = '';
                $row["base_summary"] = __( 'Booking for', 'titan-zero-trades-front-desk' ) . ' %email%';
                $row["base_description"] = __( 'Booking for', 'titan-zero-trades-front-desk' ) . ' %email%';
                $row["ical_uselocal"] = '0';
                $row["ical_organizer"] = '0';
                $row["ical_organizername"] = sanitize_text_field( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
                $row["ical_organizeremail"] = sanitize_email($tzfd_plugin->get_option('fp_from_email'));
			} else {
			    $row["observe_day_light"] = $tzfd_local_rows[0]->observe_day_light;
			    $row["ical_daylight_zone"] = $tzfd_local_rows[0]->ical_daylight_zone;
                $row["cal_time_zone_modify"] = $tzfd_local_rows[0]->cal_time_zone_modify;
                $row["attachical"] = $tzfd_local_rows[0]->attachical;
                $row["cal_tzid"] = $tzfd_local_rows[0]->cal_tzid;
                $row["base_summary"] = $tzfd_local_rows[0]->base_summary;
                $row["base_description"] = $tzfd_local_rows[0]->base_description;
                $row["ical_uselocal"] = $tzfd_local_rows[0]->ical_uselocal;
                $row["ical_organizer"] = $tzfd_local_rows[0]->ical_organizer;
                $row["ical_organizername"] = $tzfd_local_rows[0]->ical_organizername;
                $row["ical_organizeremail"] = $tzfd_local_rows[0]->ical_organizeremail;
			}
			?>
			<div id="metabox_basic_settings" class="postbox" >
				<h3 class='hndle' style="padding:5px;"><span><?php print esc_html($this->name); ?></span></h3>
				<div class="inside">
				   <input type="hidden" name="TZFD_icalexport_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
                   <?php esc_html_e( 'iCal link:', 'titan-zero-trades-front-desk' ); ?>
                   <div style="border:1px dotted black;padding:5px;">
                    <a href="<?php echo esc_url($this->getiCalLink($form_id)); ?>"><?php echo esc_html($this->getiCalLink($form_id)); ?></a>
                   </div>
                   <p><?php esc_html_e( 'To export the iCal link with Google Calendar on a regular basis, please read the instructions on this Google page:', 'titan-zero-trades-front-desk' ); ?></p>
                   <p><a href="https://support.google.com/calendar/answer/37100?hl=en">https://support.google.com/calendar/answer/37100?hl=en</a></p>
                   <p><?php esc_html_e( 'This will automatically export the bookings stored in the plugin to the Google Calendar (one way sync).', 'titan-zero-trades-front-desk' ); ?></p>
                   <hr />
                   <table class="form-table">
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('iCal timezone difference', 'titan-zero-trades-front-desk');  ?></th>
                    <td><select name="cal_time_zone_modify">
                          <option value=""><?php echo esc_html__( '- none -', 'titan-zero-trades-front-desk' ); ?></option>
                          <?php
                            for ($tzfd_i=-23;$tzfd_i<24; $tzfd_i++)
                            {
                                $orgi = $tzfd_i;
                                if ($orgi > 0) $orgi--;
                                $text = " ".($tzfd_i<=0?"":"+").$tzfd_i.":30 hours"; $text2 = " ".($tzfd_i<=0?"+".(-1*$orgi):"-".$orgi).":30 hours";
                                echo '<option value="'.esc_attr($text).'" '.($row["cal_time_zone_modify"] == trim($text)?' selected':'').'>'.esc_html($text2).'</option>';
                                $text_a = " ".($tzfd_i<=0?"":"+").$tzfd_i." hours"; $text_a2 = " ".($tzfd_i<=0?"+".(-1*$tzfd_i):"-".$tzfd_i).":00 hours";
                                echo '<option value="'.esc_attr($text_a).'" '.($row["cal_time_zone_modify"] == trim($text_a)?' selected':'').'>'.esc_html($text_a2).'</option>';
                            }
                          ?>
                         </select>
                         <br /><em><?php esc_html_e( 'Note: Based in your computer timezone the recommended setting for this field is', 'titan-zero-trades-front-desk' ); ?> <strong><span id="tzfdrectime"></span> <?php esc_html_e( 'hours', 'titan-zero-trades-front-desk' ); ?></strong></em>
                    </td>
                    </tr>


                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Include timezone ID in iCal file?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="cal_tzid">
                        <?php $tzone =  wp_timezone_string(); if (substr($tzone,0,1) == "+" || substr($tzone,0,1) == "-") $tzone = "GMT".$tzone; ?>
                          <option value="" <?php if ($row["cal_tzid"] == '') echo ' selected'; ?>><?php echo esc_html__( '- No, don\'t include it - (select this if you are not sure)', 'titan-zero-trades-front-desk' ); ?></option>
                          <?php foreach ($this->timezones as $tzfd_value) { ?>
                          <option value="<?php echo esc_attr($tzfd_value); ?>"  <?php if ($row["cal_tzid"] == $tzfd_value) echo ' selected'; ?>><?php echo esc_html($tzfd_value); ?></option>
                          <?php } ?>
                         </select>
                         <br /><em><?php esc_html_e( 'Note: Use this adjusting also the above field for the iCal timezone difference.', 'titan-zero-trades-front-desk' ); ?></em>
                    </td>
                    </tr>


                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Observe daylight saving time?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="observe_day_light">
                          <option value="true" <?php if ($row["observe_day_light"] == '' || $row["observe_day_light"] == 'true') echo ' selected'; ?>><?php echo esc_html__( 'Yes', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="false" <?php if ($row["observe_day_light"] == 'false') echo ' selected'; ?>><?php echo esc_html__( 'No', 'titan-zero-trades-front-desk' ); ?></option>
                         </select>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Daylight saving time zone', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="ical_daylight_zone">
                          <option value="EUROPE" <?php if ($row["ical_daylight_zone"] == '' || $row["ical_daylight_zone"] == 'EUROPE') echo ' selected'; ?>><?php echo esc_html__( 'Europe', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="USA" <?php if ($row["ical_daylight_zone"] == 'USA') echo ' selected'; ?>><?php echo esc_html__( 'USA', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="AU" <?php if ($row["ical_daylight_zone"] == 'AU') echo ' selected'; ?>><?php echo esc_html__( 'AUSTRALIA', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="NZ" <?php if ($row["ical_daylight_zone"] == 'NZ') echo ' selected'; ?>><?php echo esc_html__( 'NEW ZEALAND', 'titan-zero-trades-front-desk' ); ?></option>
                         </select>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Time conversion type', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="ical_uselocal">
                          <option value="0" <?php if ($row["ical_uselocal"] == '' || $row["ical_uselocal"] == '0') echo ' selected'; ?>><?php echo esc_html__( 'Automatic timezone conversion', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="1" <?php if ($row["ical_uselocal"] == '1') echo ' selected'; ?>><?php echo esc_html__( 'No timezone conversion (fixed to local time)', 'titan-zero-trades-front-desk' ); ?></option>
                         </select>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Attach iCal file to notification emails?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="attachical">
                          <option value="0" <?php if ($row["attachical"] == '' || $row["attachical"] == '0') echo ' selected'; ?>><?php echo esc_html__( 'No', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="1" <?php if ($row["attachical"] == '1') echo ' selected'; ?>><?php echo esc_html__( 'Yes - for all emails (excluding cancelled and rejected items)', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="2" <?php if ($row["attachical"] == '2') echo ' selected'; ?>><?php echo esc_html__( 'Yes - for approved items only', 'titan-zero-trades-front-desk' ); ?></option>
                         </select>
                         <br />
                         <em><?php esc_html_e( '* Important note: The WordPress uploads folder must exist and be writable to generate the iCal file to be attached.', 'titan-zero-trades-front-desk' ); ?></em>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('iCal entry summary', 'titan-zero-trades-front-desk'); ?></th>
                    <td><textarea name="base_summary"><?php echo esc_textarea($row["base_summary"]); ?></textarea>
                         <br />
                         <em><?php esc_html_e( '* Note: You can get the field IDs/tags from the form builder.', 'titan-zero-trades-front-desk' ); ?></em>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('iCal entry description', 'titan-zero-trades-front-desk'); ?></th>
                    <td><textarea name="base_description"><?php echo esc_textarea($row["base_description"]); ?></textarea>
                         <br />
                         <em><?php esc_html_e( '* Note: You can get the field IDs/tags from the form builder.', 'titan-zero-trades-front-desk' ); ?></em>
                    </td>
                    </tr>

                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Include Organizer info?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="ical_organizer" id="ical_organizer">
                          <option value="0" <?php if ($row["ical_organizer"] == '0' || $row["attachical"] == '0') echo ' selected'; ?>><?php echo esc_html__( 'No', 'titan-zero-trades-front-desk' ); ?></option>
                          <option value="1" <?php if ($row["ical_organizer"] == '1') echo ' selected'; ?>><?php echo esc_html__( 'Yes', 'titan-zero-trades-front-desk' ); ?></option>
                         </select>
                         <div id="tz_fd_ical_organizerdata" style="margin-top:10px;">
                           <strong><?php esc_html_e('Organizer name', 'titan-zero-trades-front-desk'); ?>:</strong><br />
                           <input name="ical_organizername" type="text" size="40" value="<?php echo esc_attr($row["ical_organizername"]); ?>" /><br /><br />
                           <strong><?php esc_html_e('Organizer email', 'titan-zero-trades-front-desk'); ?>:</strong><br />
                           <input name="ical_organizeremail" type="text" size="40" value="<?php echo esc_attr($row["ical_organizeremail"]); ?>" />
                           
                           
                         </div>
                    </td>
                    </tr>                    
                    
                  </table>
				</div>
			</div>
			<?php
		} // end get_addon_form_settings



		/************************ ADDON CODE *****************************/

        /************************ ATTRIBUTES *****************************/

        private $form_table = TZFD_Tables::ICALEXPORT;
        private $_inserted = false;

        // Calendar files made in this request (path => file contents). A file is written again before each email
        // that carries it, and deleted once that email has been sent.
        private static $ical_files = array();
        // Single-event fallback that removes a calendar file if no mail hook ran for it.
        const ICAL_CLEANUP_HOOK = 'tz_fd_ical_remove_file';

        /************************ CONSTRUCT *****************************/

        function __construct()
        {
			$this->description = $this->tr_apply("The add-on adds support for exporting iCal files.", 'titan-zero-trades-front-desk' );
            // Check if the plugin is active
			if( !$this->addon_is_active() ) return;

			add_action( 'init', array( &$this, 'pp_iCalExport_update_status' ), 10, 1 );
			add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_admin_assets' ) );

            add_filter( 'tzfd_email_attachments', array( &$this, 'attach_ical_file' ), 10, 3 );

            // Calendar files are deleted once every email that carries them has finished sending.
            add_filter( 'wp_mail', array( &$this, 'track_ical_attachments' ), 999, 1 );
            add_action( 'wp_mail_succeeded', array( &$this, 'release_ical_attachments' ), 10, 1 );
            add_action( 'wp_mail_failed', array( &$this, 'release_ical_attachments_failed' ), 10, 1 );
            add_action( self::ICAL_CLEANUP_HOOK, array( &$this, 'remove_ical_file' ), 10, 1 );
            register_shutdown_function( array( $this, 'sweep_ical_files' ) );

            $this->update_database();

            $passcode = get_option('TZFD_PASSCODE',"");
            if ($passcode == '')
            {
                $passcode = wp_generate_uuid4();
                update_option( 'TZFD_PASSCODE', $passcode);
            }

        } // End __construct

        /**
         * Loads the script for the organizer fields on the plugin's admin screens.
         */
        public function enqueue_admin_assets( $hook_suffix )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value used to choose the admin screen; no state is changed.
            $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
            if ( 0 !== strpos( $page, 'tzfd' ) ) { return; }
            wp_enqueue_script( 'tzfd-book-icalexport', plugins_url( 'js/inline/book-icalexport.js', dirname( __DIR__, 2 ) . '/titan-zero-front-desk.php' ), array(), '2.0.1', true );
        }


        		/**
         * Create the database tables
         */
        protected function update_database() {
            global $wpdb;
            
            $charset_collate = $wpdb->get_charset_collate();
            
            $tzfd_table_name = $wpdb->prefix . sanitize_key( $this->form_table );
            
            $sql = "CREATE TABLE {$tzfd_table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                formid int NOT NULL,
                company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                cal_time_zone_modify varchar(255) DEFAULT '' NOT NULL,
                observe_day_light varchar(255) DEFAULT '' NOT NULL,
                ical_daylight_zone varchar(255) DEFAULT '' NOT NULL,
                attachical varchar(10) DEFAULT '' NOT NULL,
                base_summary text,
                base_description text,
                cal_tzid text,
                ical_uselocal text,
                UNIQUE KEY id (id),
                KEY company_form (company_id, formid)
            ) $charset_collate;";

            // dbDelta() runs only when this schema is not yet recorded. The key is a hash of the SQL, so a schema change runs it again.
            $tzfd_schema_key = 'tzfd_schema_' . md5( $sql );
            if ( ! get_option( $tzfd_schema_key ) ) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta( $sql );
                update_option( $tzfd_schema_key, '1', false );
            }
            // Historical rows predate tenant IDs. Map them to the owner of their
            // globally unique form ID, never to whichever company is logged in.
            // Rows whose form was deleted remain inaccessible (company_id = 0).
            if ( ! get_option( 'tzfd_tenant_migration_icalexport_v1', false ) ) {
                $migrated = $wpdb->query( $wpdb->prepare(
                    'UPDATE %i a INNER JOIN %i f ON f.id=a.formid SET a.company_id=f.company_id WHERE a.company_id=0 AND f.company_id>0',
                    $tzfd_table_name, $wpdb->prefix . TZFD_Tables::FORMS
                ) );
                if ( false !== $migrated ) { update_option( 'tzfd_tenant_migration_icalexport_v1', 1, false ); }
            }
        } // end update_database

        /************************ PRIVATE METHODS *****************************/

        /**
         * Saves one form's iCal settings. An existing row is updated in place, and a new row is inserted only when none
         * exists. Duplicate rows are removed after the kept row is written. A failed write leaves the saved settings as they were.
         *
         * @return int|false Rows written, or false on a database error.
         */
        private static function save_settings_row( $table, array $data, array $formats )
        {
            global $wpdb;
            $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE formid=%d AND company_id=%d ORDER BY id ASC', $table, $data['formid'], $data['company_id'] ) );
            if ( ! empty( $wpdb->last_error ) || ! is_array( $ids ) ) { return false; }
            if ( array() === $ids ) { return $wpdb->insert( $table, $data, $formats ); }
            $keep  = (int) $ids[0];
            $saved = $wpdb->update( $table, $data, array( 'id' => $keep ), $formats, array( '%d' ) );
            if ( false === $saved ) { return false; }
            if ( count( $ids ) > 1 ) {
                $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE formid=%d AND company_id=%d AND id <> %d', $table, $data['formid'], $data['company_id'], $keep ) );
            }
            return $saved;
        }

        /** HMAC authentication for read-only calendar subscription URLs. */
        public static function feed_token( $form_id, $secret )
        {
            $id = filter_var( $form_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
            if ( false === $id || ! is_string( $secret ) || strlen( $secret ) < 32 ) { return ''; }
            return hash_hmac( 'sha256', 'tzfd:ical:v2:' . $id, $secret );
        }

        public static function verify_feed_token( $form_id, $provided, $secret, $legacy_secret = '', $allow_legacy = false )
        {
            if ( ! is_string( $provided ) ) { return false; }
            $token = self::feed_token( $form_id, $secret );
            if ( '' !== $token && strlen( $provided ) === 64 && hash_equals( $token, $provided ) ) { return true; }
            if ( ! $allow_legacy || ! is_string( $legacy_secret ) || '' === $legacy_secret ) { return false; }
            $id = filter_var( $form_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
            $site_secret = self::calendar_feed_secret();
            if ( false === $id || '' === $site_secret || strlen( $provided ) !== 64 ) { return false; }
            // Legacy tokens are HMAC-SHA256 under the WordPress auth salt, not an unkeyed MD5 truncated to 40 bits.
            // Every legacy feed URL changes when this is enabled.
            $legacy = hash_hmac( 'sha256', 'tzfd:ical:legacy:' . $id . ':' . $legacy_secret, $site_secret );
            return hash_equals( $legacy, $provided );
        }

        private static function calendar_feed_secret()
        {
            return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
        }

        private function getiCalLink($form_id)
        {
            global $tzfd_plugin;
            return $tzfd_plugin->get_site_url()."?tz_fd_app=calfeed&id=".$form_id."&verify=".self::feed_token( $form_id, self::calendar_feed_secret() );
        }

		public function pp_iCalExport_update_status( )
		{
            global $wpdb, $tzfd_plugin;

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (!isset($_GET["tz_fd_app"]) || tzfd_request_text( 'get', "tz_fd_app" ) != 'calfeed')
                return;

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only feed request. There is no nonce for a calendar subscription URL; access is granted only when the verify token passes the hash_equals() check on this same line. The request changes no state.
            if (isset($_GET["id"]) && isset($_GET["verify"]) && self::verify_feed_token( TZFD_Admin_Request_Ids::positive( tzfd_request_text( 'get', 'id' ) ), tzfd_request_text( 'get', "verify" ), self::calendar_feed_secret(), get_option( 'TZFD_PASSCODE', '' ), defined( 'TZFD_ALLOW_LEGACY_ICAL_FEEDS' ) && TZFD_ALLOW_LEGACY_ICAL_FEEDS === true ))
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only feed request. The id was checked by the verify token (hash_equals) in the condition above; the export changes no state.
                $this->export_iCal(TZFD_Admin_Request_Ids::positive( tzfd_request_text( 'get', 'id' ) ));
            else
                echo esc_html__( 'Access denied - verify value is not correct.', 'titan-zero-trades-front-desk' );
            exit ();
		}

       	private function export_iCal( $form_id, $date_from = 'today -1 month', $date_to = 'today +10 years')
		{
            global $wpdb, $tzfd_plugin;

            $form_id = TZFD_Admin_Request_Ids::positive( $form_id );
            $company_id = $form_id ? TZFD_Scoped_Store::company_for_form( $form_id ) : 0;
            if ( ! $form_id || ! $company_id ) {
                status_header( 404 );
                return;
            }
            $updatefeaturetime = strtotime('2019-03-05');

            $icalSettings = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM %i WHERE formid=%d AND company_id=%d", $wpdb->prefix.TZFD_Tables::ICALEXPORT, $form_id, TZFD_Scoped_Store::company_for_form( absint( $form_id ) ) )   
					);

            $icalSettings = $icalSettings ?: array( self::default_settings() );
            $icalSettings[0] = self::complete_settings( $icalSettings[0] );

            $from = gmdate("Y-m-d",strtotime($date_from));
            $to = gmdate("Y-m-d",strtotime($date_to));

            // Calendar subscriptions are public bearer-token endpoints: scope every row to
            // the authenticated calendar owner, not merely the incoming calendar number.
            require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-booking-export-reader.php';
            $tzfd_local_rows = TZFD_Booking_Export_Reader::read(
                (int) $company_id, (int) $form_id, $to, (string) $tzfd_plugin->blocked_by_admin_indicator
            );
            if ( null === $tzfd_local_rows ) {
                status_header( 503 );
                echo esc_html__( 'Calendar export could not be completed. Please retry.', 'titan-zero-trades-front-desk' );
                return;
            }
            header("Content-Type: text/calendar; charset=utf-8");
            header("Content-Disposition: attachment; filename=\"events_".gmdate("Y-m-d_H-i-s").".ics\"");
            echo "BEGIN:VCALENDAR\n";
            echo "PRODID:-//Scheduling & Enquiries Manager for WordPress//EN\n";
            echo "VERSION:2.0\n";

            $tzfd_blockedstatuses = explode(",", get_option('tz_fd_statuses_block',''));
            $tzfd_blockedstatuses[] = '';

            foreach($tzfd_local_rows as $tzfd_item)
                if ($tzfd_item->ipaddr != 'remote-ical-file')
                {
                    $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
					if (!is_array($tzfd_data)) $tzfd_data = array();  
					if (!isset($tzfd_data["apps"]) || !is_array($tzfd_data["apps"])) $tzfd_data["apps"] = array();                      
                    $ct = 0;
                    foreach($tzfd_data["apps"] as $tzfd_app)
                        if (
                            is_array( $tzfd_app ) && self::valid_appointment( $tzfd_app ) &&
                            ($tzfd_app["date"] ?? '') >= $from && ($tzfd_app["date"] ?? '') <= $to
                            && (!isset($tzfd_app["cancelled"]) || in_array($tzfd_app["cancelled"],$tzfd_blockedstatuses))
                           )
                        {
                            $ct++;
                            $time = explode("/", $tzfd_app["slot"] ?? '');
                            $datetime = ($tzfd_app["date"] ?? '')." ".trim($time[0]);
                            $duration = "+".($tzfd_app["duration"] ?? '')." minutes";
                            $submissiontime = strtotime($tzfd_item->time);
                            if ( self::observes_day_light( $icalSettings[0] ) )
                                $datetime = self::daylight_adjusted( $datetime, $icalSettings[0] );

                            $base_summary = (string) $icalSettings[0]->base_summary;
                            $base_description = (string) $icalSettings[0]->base_description;

                            $base_summary = str_replace ('<%', '%', $base_summary);
                            $base_description = str_replace ('<%', '%', $base_description);
                            $base_summary = str_replace ('%>', '%', $base_summary);
                            $base_description = str_replace ('%>', '%', $base_description);

                            $base_summary = str_replace('%apps%', $tzfd_plugin->get_appointments_text($tzfd_data["apps"]), $base_summary);
                            $base_description = str_replace('%apps%', $tzfd_plugin->get_appointments_text($tzfd_data["apps"]), $base_description);
                            foreach ($tzfd_data as $itemgt => $tzfd_value)
                            {
                                $base_summary = str_replace('%'.$itemgt.'%',(is_array($tzfd_value)?($tzfd_plugin->recursive_implode(", ",$tzfd_value)):((string) $tzfd_value)),$base_summary);
                                $base_description = str_replace('%'.$itemgt.'%',(is_array($tzfd_value)?($tzfd_plugin->recursive_implode(", ",$tzfd_value)):((string) $tzfd_value)),$base_description);
                            }

                            $base_summary = $tzfd_plugin->replace_tags($base_summary, $tzfd_data, false, $ct-1);
                            $base_description = $tzfd_plugin->replace_tags($base_description, $tzfd_data, false, $ct-1);

                            $base_summary = str_replace(array("<br>", "<br />"), "\n", str_replace("\r", '', $base_summary));
                            $base_description = str_replace(array("<br>", "<br />"), "\n", str_replace("\r", '', $base_description));

                            echo "BEGIN:VEVENT\n"; 
                            echo "DTSTART".(esc_html( self::safe_tzid( $icalSettings[0]->cal_tzid ) ) !== '' ? ';TZID=' . esc_html( self::safe_tzid( $icalSettings[0]->cal_tzid ) ) : '').":".esc_html( gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify)) )."T".esc_html( gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify)) ).(esc_html( self::safe_tzid( $icalSettings[0]->cal_tzid ) ) !== '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                            echo "DTEND".(esc_html( self::safe_tzid( $icalSettings[0]->cal_tzid ) ) !== '' ? ';TZID=' . esc_html( self::safe_tzid( $icalSettings[0]->cal_tzid ) ) : '').":".esc_html( gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration)) )."T".esc_html( gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration)) ).(esc_html( self::safe_tzid( $icalSettings[0]->cal_tzid ) ) !== '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                            echo "DTSTAMP:".esc_html(gmdate("Ymd",$submissiontime))."T".esc_html(gmdate("His",$submissiontime))."Z\n";
                            echo "UID:" . esc_html( self::safe_uid( $tzfd_item->id, $ct, wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $submissiontime > $updatefeaturetime ? $form_id : 0 ) ) . "\n";
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCal (text/calendar) output, not HTML; the value is escaped by ical_escape_text().
                            echo "DESCRIPTION:".self::ical_escape_text(wp_strip_all_tags($base_description))."\n";
                            echo "LAST-MODIFIED:".esc_html(gmdate("Ymd",$submissiontime))."T".esc_html(gmdate("His",$submissiontime))."Z\n";
                            echo "LOCATION:\n";
                            echo "SEQUENCE:0\n";
                            echo "STATUS:CONFIRMED\n";
                            if ($icalSettings[0]->ical_organizer == '1')
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCal (text/calendar) output, not HTML; the name is escaped by ical_escape_param() and the email by sanitize_email().
                                echo "ORGANIZER;CN=\"".self::ical_escape_param($tzfd_plugin->replace_tags($icalSettings[0]->ical_organizername, $tzfd_data, false, $ct-1))."\":MAILTO:".sanitize_email($tzfd_plugin->replace_tags($icalSettings[0]->ical_organizeremail, $tzfd_data, false, $ct-1))."\r\n";                            
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCal (text/calendar) output, not HTML; the value is escaped by ical_escape_text().
                            echo "SUMMARY:".self::ical_escape_text(wp_strip_all_tags($base_summary))."\n";
                            echo "TRANSP:OPAQUE\n";
                            echo "END:VEVENT\n";
                        }
                }

            echo 'END:VCALENDAR';
            exit ();
		}


        function attach_ical_file( $attachments, $tzfd_local_params, $form_id)
        {
            global $wpdb, $tzfd_plugin, $wp_filesystem;

            $updatefeaturetime = strtotime('2019-03-05');

            $icalSettings = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM %i WHERE formid=%d AND company_id=%d", $wpdb->prefix.TZFD_Tables::ICALEXPORT, $form_id, TZFD_Scoped_Store::company_for_form( absint( $form_id ) ) ) 
					);

            $icalSettings = $icalSettings ?: array( self::default_settings() );
            $icalSettings[0] = self::complete_settings( $icalSettings[0] );

            if ($icalSettings[0]->attachical != '1' && $icalSettings[0]->attachical != '2')
                return $attachments;

            $tzfd_data = $tzfd_local_params;
            $ct = 0;
            foreach ( (array) ( $tzfd_data["apps"] ?? array() ) as $tzfd_app )
            {
                if ( ! is_array( $tzfd_app ) || ! self::valid_appointment( $tzfd_app ) ) { continue; }
                $tzfd_cancelled = strtolower( is_string( $tzfd_app['cancelled'] ?? '' ) ? $tzfd_app['cancelled'] : '' );
                if
                  (
                   is_array( $tzfd_app ) && self::valid_appointment( $tzfd_app ) &&
                   ($tzfd_cancelled != 'cancelled') &&
                   ($tzfd_cancelled != 'cancelled by customer') &&
                   ($tzfd_cancelled != 'rejected') &&
                   ($icalSettings[0]->attachical != '2' || $tzfd_cancelled == '' || $tzfd_cancelled == 'approved')
                  )
                {
                    $ct++;
                    $time = explode("/", $tzfd_app["slot"] ?? '');
                    $datetime = ($tzfd_app["date"] ?? '')." ".trim($time[0]);
                    $duration = "+".($tzfd_app["duration"] ?? '')." minutes";
                    $submissiontime = time();
                    if ( self::observes_day_light( $icalSettings[0] ) )
                        $datetime = self::daylight_adjusted( $datetime, $icalSettings[0] );

                    $base_summary = (string) $icalSettings[0]->base_summary;
                    $base_description = (string) $icalSettings[0]->base_description;

                    $base_summary = str_replace ('<%', '%', $base_summary);
                    $base_description = str_replace ('<%', '%', $base_description);
                    $base_summary = str_replace ('%>', '%', $base_summary);
                    $base_description = str_replace ('%>', '%', $base_description);

                    $base_summary = str_replace('%apps%', $tzfd_plugin->get_appointments_text($tzfd_data["apps"]), $base_summary);
                    $base_description = str_replace('%apps%', $tzfd_plugin->get_appointments_text($tzfd_data["apps"]), $base_description);
                    foreach ($tzfd_data as $itemgt => $tzfd_value)
                    {
                        $base_summary = str_replace('%'.$itemgt.'%',(is_array($tzfd_value)?($tzfd_plugin->recursive_implode(", ",$tzfd_value)):((string) $tzfd_value)),$base_summary);
                        $base_description = str_replace('%'.$itemgt.'%',(is_array($tzfd_value)?($tzfd_plugin->recursive_implode(", ",$tzfd_value)):((string) $tzfd_value)),$base_description);
                    }

                    $base_summary = $tzfd_plugin->replace_tags($base_summary, $tzfd_data, false, $ct-1);
                    $base_description = $tzfd_plugin->replace_tags($base_description, $tzfd_data, false, $ct-1);

                    $base_summary = str_replace(array("<br>", "<br />"), "\n", str_replace("\r", '', $base_summary));
                    $base_description = str_replace(array("<br>", "<br />"), "\n", str_replace("\r", '', $base_description));

                    $buffer  = "BEGIN:VCALENDAR\n";
                    $buffer .= "PRODID:-//Scheduling & Enquiries Manager for WordPress//EN\n";
                    $buffer .= "VERSION:2.0\n";
                    $buffer .= "X-MS-OLK-FORCEINSPECTOROPEN:TRUE\n";

                    $buffer .= "BEGIN:VEVENT\n";
                    $buffer .= "DTSTART".(self::safe_tzid( $icalSettings[0]->cal_tzid ) !== '' ? ';TZID=' . self::safe_tzid( $icalSettings[0]->cal_tzid ) : '').":".gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify))."T".gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify)).(self::safe_tzid( $icalSettings[0]->cal_tzid ) !== '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                    $buffer .= "DTEND".(self::safe_tzid( $icalSettings[0]->cal_tzid ) !== '' ? ';TZID=' . self::safe_tzid( $icalSettings[0]->cal_tzid ) : '').":".gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration))."T".gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration)).(self::safe_tzid( $icalSettings[0]->cal_tzid ) !== '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                    $buffer .= "DTSTAMP:".gmdate("Ymd",$submissiontime)."T".gmdate("His",$submissiontime)."Z\n";
                    $buffer .= "UID:" . self::safe_uid( $tzfd_local_params['itemnumber'] ?? 0, $ct, wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $submissiontime > $updatefeaturetime ? $form_id : 0 ) . "\n";
                    $buffer .= "DESCRIPTION:".self::ical_escape_text(wp_strip_all_tags($base_description))."\n";
                    $buffer .= "LAST-MODIFIED:".gmdate("Ymd",$submissiontime)."T".gmdate("His",$submissiontime)."Z\n";
                    $buffer .= "LOCATION:\n";
                    $buffer .= "SEQUENCE:0\n";
                    $buffer .= "STATUS:CONFIRMED\n";
                    
                    if ($icalSettings[0]->ical_organizer == '1')
                        $buffer .= "ORGANIZER;CN=\"".self::ical_escape_param($tzfd_plugin->replace_tags($icalSettings[0]->ical_organizername, $tzfd_data, false, $ct-1))."\":MAILTO:".sanitize_email($tzfd_plugin->replace_tags($icalSettings[0]->ical_organizeremail, $tzfd_data, false, $ct-1))."\r\n";
                    $buffer .= "SUMMARY:".self::ical_escape_text(wp_strip_all_tags($base_summary))."\n";
                    $buffer .= "TRANSP:OPAQUE\n";
                    $buffer .= "END:VEVENT\n";

                    $buffer .= 'END:VCALENDAR';

                    if ( empty( $wp_filesystem ) ) {
                        require_once( ABSPATH . '/wp-admin/includes/file.php' );
                        WP_Filesystem();
                    }
                    $booking_id = $tzfd_local_params['itemnumber'] ?? null;
                    if ( ! is_scalar( $booking_id ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $booking_id ) ||
                        ! is_int( $ct ) || $ct < 1 || ! is_object( $wp_filesystem ) ) { return $attachments; }
                    $upload_dir_info = wp_upload_dir( null, false );
                    if ( ! is_array( $upload_dir_info ) || ! empty( $upload_dir_info['error'] ) ||
                        empty( $upload_dir_info['basedir'] ) || ! is_string( $upload_dir_info['basedir'] ) ) { return $attachments; }
                    $file_dir = trailingslashit( $upload_dir_info['basedir'] ) . 'titan-zero-trades-front-desk';
                    if ( ! wp_mkdir_p( $file_dir ) ) { return $attachments; }
                    $filename1 = 'tzfd-booking_' . (int) $booking_id . '_' . (int) $ct . '.ics';
                    $file_path = trailingslashit( $file_dir ) . $filename1;
                    if ( $wp_filesystem->put_contents( $file_path, $buffer, FS_CHMOD_FILE ) ) {
                        self::$ical_files[ $file_path ] = $buffer;
                        wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::ICAL_CLEANUP_HOOK, array( $file_path ) );
                        $attachments[] = $file_path;
                    }

                }

            }

            return $attachments;
        }

        /**
         * Limit calendar attachment deletion to real regular files in the
         * WordPress-configured uploads directory. A scheduled action argument
         * is never an authority to delete an arbitrary filesystem path.
         */
        private static function safe_ical_file( $path )
        {
            if ( ! is_string( $path ) || '' === $path || strpos( $path, "\0" ) !== false ||
                ! preg_match( '/^tzfd-booking_[0-9]+_[0-9]+\.ics$/D', basename( $path ) ) ) {
                return false;
            }
            $uploads = wp_upload_dir( null, false );
            if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ||
                ! isset( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) || '' === $uploads['basedir'] ) {
                return false;
            }
            $dir = realpath( trailingslashit( $uploads['basedir'] ) . 'titan-zero-trades-front-desk' );
            $real = realpath( $path );
            if ( false === $dir || false === $real ||
                dirname( wp_normalize_path( $real ) ) !== wp_normalize_path( $dir ) ||
                ! is_file( $real ) || is_link( $path ) ) {
                return false;
            }
            return $real;
        }

        /** A writable destination is accepted only inside our actual uploads folder. */
        private static function safe_ical_destination( $path )
        {
            if ( ! is_string( $path ) || '' === $path || strpos( $path, "\0" ) !== false ||
                ! preg_match( '/^tzfd-booking_[0-9]+_[0-9]+\.ics$/D', basename( $path ) ) ||
                is_link( $path ) ) { return false; }
            $uploads = wp_upload_dir( null, false );
            if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ||
                ! isset( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) { return false; }
            $dir = realpath( trailingslashit( $uploads['basedir'] ) . 'titan-zero-trades-front-desk' );
            return false !== $dir &&
                wp_normalize_path( dirname( $path ) ) === wp_normalize_path( $dir );
        }

        /**
         * Returns the calendar file paths from a mail's attachments that this request wrote.
         */
        private static function ical_paths_in( $attachments )
        {
            if ( ! is_array( $attachments ) )
                $attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );

            $paths = array();
            foreach ( $attachments as $attachment )
            {
                if ( is_string( $attachment ) && isset( self::$ical_files[ $attachment ] ) )
                    $paths[] = $attachment;
            }
            return $paths;
        }

        /**
         * wp_mail filter: writes back any calendar file that an earlier email in this request already deleted.
         */
        public function track_ical_attachments( $args )
        {
            global $wp_filesystem;
            if ( is_array( $args ) && isset( $args['attachments'] ) && is_object( $wp_filesystem ) )
            {
                foreach ( self::ical_paths_in( $args['attachments'] ) as $path )
                {
                    if ( false !== self::safe_ical_file( $path ) ) { continue; }
                    // A missing file can be recreated only at the exact folder/filename we own.
                    if ( ! self::safe_ical_destination( $path ) ) { continue; }
                    $wp_filesystem->put_contents( $path, self::$ical_files[ $path ], FS_CHMOD_FILE );
                }
            }
            return $args;
        }

        /**
         * wp_mail_succeeded action: the email has been sent, so delete its calendar files.
         */
        public function release_ical_attachments( $mail_data )
        {
            $attachments = is_array( $mail_data ) && isset( $mail_data['attachments'] ) ? $mail_data['attachments'] : array();
            foreach ( self::ical_paths_in( $attachments ) as $path )
            {
                $safe = self::safe_ical_file( $path );
                if ( false !== $safe ) { wp_delete_file( $safe ); }
            }
        }

        /**
         * wp_mail_failed action: the email was not sent, so delete its calendar files the same way.
         */
        public function release_ical_attachments_failed( $error )
        {
            $this->release_ical_attachments( is_wp_error( $error ) ? $error->get_error_data() : array() );
        }

        /**
         * Forgets one calendar file and deletes it. It is also the handler of the one-hour fallback event.
         */
        public function remove_ical_file( $file_path )
        {
            if ( ! is_string( $file_path ) ) { return; }
            unset( self::$ical_files[ $file_path ] );
            $safe = self::safe_ical_file( $file_path );
            if ( false !== $safe ) { wp_delete_file( $safe ); }
        }

        /**
         * Shutdown: removes any calendar file still left after the request, such as one whose email never reached wp_mail.
         */
        public function sweep_ical_files()
        {
            foreach ( array_keys( self::$ical_files ) as $file_path )
                $this->remove_ical_file( $file_path );
        }

    } // End Class

    // Main add-on code
    $tzfd_iCalExport_obj = new TZFD_iCalExport();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $tzfd_iCalExport_obj->get_addon_id() ] = $tzfd_iCalExport_obj;
}

