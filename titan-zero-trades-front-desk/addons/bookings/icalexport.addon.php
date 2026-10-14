<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-stored-data.php';

require_once dirname( __DIR__ ) . '/core/base.addon.php';

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
                'base_summary'         => 'Booking for %email%',
                'base_description'     => 'Booking for %email%',
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
				isset( $_POST[ 'TZFD_icalexport_id' ] ) && isset( $_POST["anonce"] ) && current_user_can( 'edit_pages' ) && wp_verify_nonce( sanitize_text_field(wp_unslash( $_POST["anonce"] ?? '' )), 'tzfd_actions_admin')
			)
			{
			    $wpdb->delete( $wpdb->prefix.$this->form_table, array( 'formid' => $form_id ), array( '%d' ) );
				$wpdb->insert(
								$wpdb->prefix.$this->form_table,
								array(
									'formid' => $form_id,

									'observe_day_light'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["observe_day_light"] ?? '' ) ), array( 'true', 'false' ) ),
									'ical_daylight_zone'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["ical_daylight_zone"] ?? '' ) ), array( 'EUROPE', 'USA', 'AU', 'NZ' ) ),
									'cal_time_zone_modify'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["cal_time_zone_modify"] ?? '' ) ), self::time_zone_modify_values() ),
                                    'attachical'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["attachical"] ?? '' ) ), array( '0', '1' ) ),
                                    'base_summary'	 => stripslashes_deep($tzfd_plugin->sanitize(sanitize_text_field( wp_unslash( $_POST["base_summary"] ?? '' ) ))),
                                    'base_description'	 => stripslashes_deep($tzfd_plugin->sanitize(sanitize_text_field( wp_unslash( $_POST["base_description"] ?? '' ) ))),
                                    'cal_tzid'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["cal_tzid"] ?? '' ) ), array_merge( array( '' ), $this->timezones ) ),
                                    'ical_uselocal'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["ical_uselocal"] ?? '' ) ), array( '0', '1' ) ),
                                    'ical_organizer'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["ical_organizer"] ?? '' ) ), array( '0', '1' ) ),
                                    'ical_organizername'	 => sanitize_text_field( wp_unslash( $_POST["ical_organizername"] ?? '' ) ),
                                    'ical_organizeremail'	 => sanitize_email( sanitize_text_field( wp_unslash( $_POST["ical_organizeremail"] ?? '' ) ) )

								),
								array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
							);
			}

			$tzfd_rows = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM %i WHERE formid=%d", $wpdb->prefix.'tzfd_icalexport', $form_id )
					);
			if (!count($tzfd_rows))
			{
			    $row["observe_day_light"] = "true";
			    $row["ical_daylight_zone"] = "EUROPE";
                $row["cal_time_zone_modify"] = '';
                $row["attachical"] = '';
                $row["cal_tzid"] = '';
                $row["base_summary"] = 'Booking for %email%';
                $row["base_description"] = 'Booking for %email%';
                $row["ical_uselocal"] = '0';
                $row["ical_organizer"] = '0';
                $row["ical_organizername"] = sanitize_text_field( wp_unslash( $_SERVER["HTTP_HOST"] ?? '' ) );
                $row["ical_organizeremail"] = sanitize_email($tzfd_plugin->get_option('fp_from_email'));
			} else {
			    $row["observe_day_light"] = $tzfd_rows[0]->observe_day_light;
			    $row["ical_daylight_zone"] = $tzfd_rows[0]->ical_daylight_zone;
                $row["cal_time_zone_modify"] = $tzfd_rows[0]->cal_time_zone_modify;
                $row["attachical"] = $tzfd_rows[0]->attachical;
                $row["cal_tzid"] = $tzfd_rows[0]->cal_tzid;
                $row["base_summary"] = $tzfd_rows[0]->base_summary;
                $row["base_description"] = $tzfd_rows[0]->base_description;
                $row["ical_uselocal"] = $tzfd_rows[0]->ical_uselocal;
                $row["ical_organizer"] = $tzfd_rows[0]->ical_organizer;
                $row["ical_organizername"] = $tzfd_rows[0]->ical_organizername;
                $row["ical_organizeremail"] = $tzfd_rows[0]->ical_organizeremail;
			}
			?>
			<div id="metabox_basic_settings" class="postbox" >
				<h3 class='hndle' style="padding:5px;"><span><?php print esc_html($this->name); ?></span></h3>
				<div class="inside">
				   <input type="hidden" name="TZFD_icalexport_id" value="1" />
                   iCal link:
                   <div style="border:1px dotted black;padding:5px;">
                    <a href="<?php echo esc_url($this->getiCalLink($form_id)); ?>"><?php echo esc_html($this->getiCalLink($form_id)); ?></a>
                   </div>
                   <p>To export the iCal link with Google Calendar on a regular basis, please read the instructions on this Google page:</p>
                   <p><a href="https://support.google.com/calendar/answer/37100?hl=en">https://support.google.com/calendar/answer/37100?hl=en</a></p>
                   <p>This will automatically export the bookings stored in the plugin to the Google Calendar (one way sync).</p>
                   <hr />
                   <table class="form-table">
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('iCal timezone difference', 'titan-zero-trades-front-desk');  ?></th>
                    <td><select name="cal_time_zone_modify">
                          <option value="">- none -</option>
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
                         <br /><em>Note: Based in your computer timezone the recommended setting for this field is <strong><span id="tzfdrectime"></span> hours</strong></em>
                    </td>
                    </tr>


                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Include timezone ID in iCal file?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="cal_tzid">
                        <?php $tzone =  wp_timezone_string(); if (substr($tzone,0,1) == "+" || substr($tzone,0,1) == "-") $tzone = "GMT".$tzone; ?>
                          <option value="" <?php if ($row["cal_tzid"] == '') echo ' selected'; ?>>- No, don't include it - (select this if you are not sure)</option>
                          <?php foreach ($this->timezones as $tzfd_value) { ?>
                          <option value="<?php echo esc_attr($tzfd_value); ?>"  <?php if ($row["cal_tzid"] == $tzfd_value) echo ' selected'; ?>><?php echo esc_html($tzfd_value); ?></option>
                          <?php } ?>
                         </select>
                         <br /><em>Note: Use this adjusting also the above field for the iCal timezone difference.</em>
                    </td>
                    </tr>


                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Observe daylight saving time?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="observe_day_light">
                          <option value="true" <?php if ($row["observe_day_light"] == '' || $row["observe_day_light"] == 'true') echo ' selected'; ?>>Yes</option>
                          <option value="false" <?php if ($row["observe_day_light"] == 'false') echo ' selected'; ?>>No</option>
                         </select>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Daylight saving time zone', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="ical_daylight_zone">
                          <option value="EUROPE" <?php if ($row["ical_daylight_zone"] == '' || $row["ical_daylight_zone"] == 'EUROPE') echo ' selected'; ?>>Europe</option>
                          <option value="USA" <?php if ($row["ical_daylight_zone"] == 'USA') echo ' selected'; ?>>USA</option>
                          <option value="AU" <?php if ($row["ical_daylight_zone"] == 'AU') echo ' selected'; ?>>AUSTRALIA</option>
                          <option value="NZ" <?php if ($row["ical_daylight_zone"] == 'NZ') echo ' selected'; ?>>NEW ZEALAND</option>
                         </select>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Time conversion type', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="ical_uselocal">
                          <option value="0" <?php if ($row["ical_uselocal"] == '' || $row["ical_uselocal"] == '0') echo ' selected'; ?>>Automatic timezone conversion</option>
                          <option value="1" <?php if ($row["ical_uselocal"] == '1') echo ' selected'; ?>>No timezone conversion (fixed to local time)</option>
                         </select>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Attach iCal file to notification emails?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="attachical">
                          <option value="0" <?php if ($row["attachical"] == '' || $row["attachical"] == '0') echo ' selected'; ?>>No</option>
                          <option value="1" <?php if ($row["attachical"] == '1') echo ' selected'; ?>>Yes - for all emails (excluding cancelled and rejected items)</option>
                          <option value="2" <?php if ($row["attachical"] == '2') echo ' selected'; ?>>Yes - for approved items only</option>
                         </select>
                         <br />
                         <em>* Important note: The WordPress uploads folder must exist and be writable to generate the iCal file to be attached.</em>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('iCal entry summary', 'titan-zero-trades-front-desk'); ?></th>
                    <td><textarea name="base_summary"><?php echo esc_textarea($row["base_summary"]); ?></textarea>
                         <br />
                         <em>* Note: You can get the field IDs/tags from the form builder.</em>
                    </td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('iCal entry description', 'titan-zero-trades-front-desk'); ?></th>
                    <td><textarea name="base_description"><?php echo esc_textarea($row["base_description"]); ?></textarea>
                         <br />
                         <em>* Note: You can get the field IDs/tags from the form builder.</em>
                    </td>
                    </tr>

                    <tr valign="top">
                    <th scope="row"><?php esc_html_e('Include Organizer info?', 'titan-zero-trades-front-desk'); ?></th>
                    <td><select name="ical_organizer" id="ical_organizer">
                          <option value="0" <?php if ($row["ical_organizer"] == '0' || $row["attachical"] == '0') echo ' selected'; ?>>No</option>
                          <option value="1" <?php if ($row["ical_organizer"] == '1') echo ' selected'; ?>>Yes</option>
                         </select>
                         <div id="tzfd_ical_organizerdata" style="margin-top:10px;">
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

        private $form_table = 'tzfd_icalexport';
        private $_inserted = false;

        // Calendar files made in this request (path => file contents). A file is written again before each email
        // that carries it, and deleted once that email has been sent.
        private static $ical_files = array();
        // Single-event fallback that removes a calendar file if no mail hook ran for it.
        const ICAL_CLEANUP_HOOK = 'tzfd_ical_remove_file';

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
            $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            if ( 0 !== strpos( $page, 'tzfd' ) ) { return; }
            wp_enqueue_script( 'tzfd-book-icalexport', plugins_url( 'js/inline/book-icalexport.js', dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk.php' ), array(), '2.0.1', true );
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
                cal_time_zone_modify varchar(255) DEFAULT '' NOT NULL,
                observe_day_light varchar(255) DEFAULT '' NOT NULL,
                ical_daylight_zone varchar(255) DEFAULT '' NOT NULL,
                attachical varchar(10) DEFAULT '' NOT NULL,
                base_summary text,
                base_description text,
                cal_tzid text,
                ical_uselocal text,
                UNIQUE KEY id (id)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        } // end update_database

        /************************ PRIVATE METHODS *****************************/

        private function getiCalLink($form_id)
        {
            global $tzfd_plugin;
            return $tzfd_plugin->get_site_url()."?tzfd_app=calfeed&id=".$form_id."&verify=".substr(md5($form_id.get_option('TZFD_PASSCODE',"")),0,10);
        }

		public function pp_iCalExport_update_status( )
		{
            global $wpdb, $tzfd_plugin;

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (!isset($_GET["tzfd_app"]) || sanitize_text_field( wp_unslash( $_GET["tzfd_app"] ?? '' ) ) != 'calfeed')
                return;

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only feed request. There is no nonce for a calendar subscription URL; access is granted only when the verify token passes the hash_equals() check on this same line. The request changes no state.
            if (isset($_GET["id"]) && isset($_GET["verify"]) && hash_equals( substr(md5(intval(sanitize_text_field( wp_unslash( $_GET["id"] ?? '' ) )).get_option('TZFD_PASSCODE',"")),0,10), sanitize_text_field( wp_unslash( $_GET["verify"] ?? '' ) ) ))
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only feed request. The id was checked by the verify token (hash_equals) in the condition above; the export changes no state.
                $this->export_iCal(intval(sanitize_text_field( wp_unslash( $_GET["id"] ?? '' ) )));
            else
                echo 'Access denied - verify value is not correct.';
            exit ();
		}

       	private function export_iCal( $form_id, $date_from = 'today -1 month', $date_to = 'today +10 years')
		{
            global $wpdb, $tzfd_plugin;

            header("Content-Type: text/calendar; charset=utf-8");
            header("Content-Disposition: attachment; filename=\"events_".gmdate("Y-m-d_H-i-s").".ics\""); 

            $updatefeaturetime = strtotime('2019-03-05');

            echo "BEGIN:VCALENDAR\n";
            echo "PRODID:-//Titan Zero Trades: Front Desk for WordPress//EN\n";
            echo "VERSION:2.0\n";

            $icalSettings = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM %i WHERE formid=%d", $wpdb->prefix.'tzfd_icalexport', $form_id )   
					);

            $icalSettings = $icalSettings ?: array( self::default_settings() );
            $icalSettings[0] = self::complete_settings( $icalSettings[0] );

            $from = gmdate("Y-m-d",strtotime($date_from));
            $to = gmdate("Y-m-d",strtotime($date_to));

            $tzfd_table_name = $wpdb->prefix . 'tzfd_messages';
            if ( $form_id ) {
                $tzfd_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, time, notifyto, posted_data, data, ipaddr FROM %i WHERE notifyto <> %s AND formid = %d AND time <= %s ORDER BY time DESC LIMIT 0, 1000",
                    $tzfd_table_name, $tzfd_plugin->blocked_by_admin_indicator, $form_id, $to
                ) );
            } else {
                $tzfd_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, time, notifyto, posted_data, data, ipaddr FROM %i WHERE notifyto <> %s AND time <= %s ORDER BY time DESC LIMIT 0, 1000",
                    $tzfd_table_name, $tzfd_plugin->blocked_by_admin_indicator, $to
                ) );
            }

            $tzfd_blockedstatuses = explode(",", get_option('tzfd_statuses_block',''));
            $tzfd_blockedstatuses[] = '';

            foreach($tzfd_rows as $tzfd_item)
                if ($tzfd_item->ipaddr != 'remote-ical-file')
                {
                    $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
					if (!is_array($tzfd_data)) $tzfd_data = array();  
					if (!isset($tzfd_data["apps"]) || !is_array($tzfd_data["apps"])) $tzfd_data["apps"] = array();                      
                    $ct = 0;
                    foreach($tzfd_data["apps"] as $tzfd_app)
                        if (
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
                            echo "DTSTART".esc_html(($icalSettings[0]->cal_tzid != '' ? ';TZID='.$icalSettings[0]->cal_tzid : '').":".gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify))."T".gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify))).($icalSettings[0]->cal_tzid != '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                            echo "DTEND".esc_html(($icalSettings[0]->cal_tzid != '' ? ';TZID='.$icalSettings[0]->cal_tzid : '').":".gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration))."T".gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration))).($icalSettings[0]->cal_tzid != '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                            echo "DTSTAMP:".esc_html(gmdate("Ymd",$submissiontime))."T".esc_html(gmdate("His",$submissiontime))."Z\n";
                            echo "UID:uid".esc_html($tzfd_item->id.'_'.$ct."@".sanitize_text_field( wp_unslash( $_SERVER["SERVER_NAME"] ?? '' ) ).($submissiontime > $updatefeaturetime?$form_id:''))."\n";
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


        function attach_ical_file( $attachments, $tzfd_params, $form_id)
        {
            global $wpdb, $tzfd_plugin, $wp_filesystem;

            $updatefeaturetime = strtotime('2019-03-05');

            $icalSettings = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM %i WHERE formid=%d", $wpdb->prefix.'tzfd_icalexport', $form_id ) 
					);

            $icalSettings = $icalSettings ?: array( self::default_settings() );
            $icalSettings[0] = self::complete_settings( $icalSettings[0] );

            if ($icalSettings[0]->attachical != '1' && $icalSettings[0]->attachical != '2')
                return $attachments;

            $tzfd_data = $tzfd_params;
            $ct = 0;
            foreach ( (array) ( $tzfd_data["apps"] ?? array() ) as $tzfd_app )
            {
                $tzfd_cancelled = strtolower( (string) ( $tzfd_app["cancelled"] ?? '' ) );
                if
                  (
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
                    $buffer .= "PRODID:-//Titan Zero Trades: Front Desk for WordPress//EN\n";
                    $buffer .= "VERSION:2.0\n";
                    $buffer .= "X-MS-OLK-FORCEINSPECTOROPEN:TRUE\n";

                    $buffer .= "BEGIN:VEVENT\n";
                    $buffer .= "DTSTART".esc_html(($icalSettings[0]->cal_tzid != '' ? ';TZID='.$icalSettings[0]->cal_tzid : '').":".gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify))."T".gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify))).($icalSettings[0]->cal_tzid != '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                    $buffer .= "DTEND".esc_html(($icalSettings[0]->cal_tzid != '' ? ';TZID='.$icalSettings[0]->cal_tzid : '').":".gmdate("Ymd",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration))."T".gmdate("His",strtotime($datetime.$icalSettings[0]->cal_time_zone_modify.$duration))).($icalSettings[0]->cal_tzid != '' || $icalSettings[0]->ical_uselocal == '1'?'':'Z')."\n";
                    $buffer .= "DTSTAMP:".gmdate("Ymd",$submissiontime)."T".gmdate("His",$submissiontime)."Z\n";
                    $buffer .= "UID:uid".esc_html(($tzfd_params["itemnumber"] ?? '').'_'.$ct."@".sanitize_text_field( wp_unslash( $_SERVER["SERVER_NAME"] ?? '' ) ).($submissiontime > $updatefeaturetime?$form_id:''))."\n";
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
                    $filename1 = sanitize_file_name( 'tzfd-booking' . '_' . ( $tzfd_params["itemnumber"] ?? '' ) . '_' . $ct . '.ics' );
                    if ( ! is_object( $wp_filesystem ) ) { return $attachments; }
                    $upload_dir_info = wp_upload_dir();
                    $file_dir = $upload_dir_info['basedir'] . '/titan-zero-trades-front-desk';
                    wp_mkdir_p( $file_dir );
                    $file_path = $file_dir . '/' . $filename1;
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
                    if ( ! is_file( $path ) )
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
                if ( is_file( $path ) )
                    wp_delete_file( $path );
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
            $file_path = (string) $file_path;
            unset( self::$ical_files[ $file_path ] );
            if ( is_file( $file_path ) )
                wp_delete_file( $file_path );
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

