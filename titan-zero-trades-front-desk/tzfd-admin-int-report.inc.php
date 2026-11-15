<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.NonceVerification.Recommended -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked. Read-only report parameters on a capability-gated admin screen; this screen changes no state.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( defined( 'ABSPATH' ) ) { require_once __DIR__ . '/includes/class-tzfd-request-scalar.php'; }
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';
require_once __DIR__ . '/includes/class-tzfd-admin-filter-input.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';


global $wpdb;

$this->item = TZFD_Admin_Request_Ids::calendar( $_GET );
if ( null === $this->item ) {
    wp_die( esc_html__( 'Invalid calendar identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}

require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
$tzfd_report_company = TZFD_Admin_Report_Scope::enforce( $this->item );

$current_user = wp_get_current_user();
$tz_current_user_access = current_user_can('tz_fd_access');
$tz_calendar_access_ids = TZFD_Access_List::decode( $this->get_option( 'tz_fd_user_access', '' ) );

if ( !is_admin() || (!$tz_current_user_access && !in_array($current_user->ID, $tz_calendar_access_ids, true)))
{
    echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' );
    exit;
}

if ( !is_admin() )
{
    echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' );
    exit;
}

global $tz_numberofdates;
$tz_numberofdates = [];


if ( $this->item != 0 ) {
    $tz_myform = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM %i WHERE id=%d AND company_id=%d',
        $wpdb->prefix . TZFD_Tables::FORMS, $this->item, $tzfd_report_company
    ) );
    if ( ! is_array( $tz_myform ) ) {
        wp_die( esc_html__( 'Unable to read calendar information.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
    }
}  


$tz_current_page = TZFD_Admin_Request_Ids::page( $_GET );
if ( null === $tz_current_page ) {
    wp_die( esc_html__( 'Invalid page number.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
$tz_records_per_page = 100;

$tz_date_start = '';
$tz_date_end = '';

list( $tz_valid_from, $tz_valid_to ) = TZFD_Admin_Filter_Input::enforce( $_GET, $this->get_option( 'date_format', 'mm/dd/yy' ) );
$tz_rawfrom = TZFD_Admin_Filter_Input::text( $_GET, 'dfrom', 80 );
$tz_rawto = TZFD_Admin_Filter_Input::text( $_GET, 'dto', 80 );
$tz_raw_search = TZFD_Admin_Filter_Input::text( $_GET, 'search', 200 );
if ( null === $tz_raw_search ) {
    wp_die( esc_html__( 'Invalid search filter.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
$tz_search_on = ( '' !== $tz_raw_search ) ? 1 : 0;
$tz_like      = '';
if ( $tz_search_on ) {
    $search    = $tz_raw_search;
    $tz_like = '%' . $wpdb->esc_like( $search ) . '%';
}


$tz_from_on = null !== $tz_valid_from ? 1 : 0;
$tz_date_start = $tz_valid_from ?? '';
$tz_to_on = null !== $tz_valid_to ? 1 : 0;
$tz_date_end = $tz_valid_to ?? '';

$tz_item_on = ( $this->item != 0 ) ? 1 : 0;

$tz_events = $wpdb->get_results( $wpdb->prepare(
    "SELECT ipaddr, time, notifyto, posted_data FROM %i
     WHERE company_id=%d
       AND (%d = 0 OR (data LIKE %s OR posted_data LIKE %s))
       AND (%d = 0 OR (`time` >= %s))
       AND (%d = 0 OR (`time` <= %s))
       AND (%d = 0 OR formid = %d)
     ORDER BY `time` DESC",
    $wpdb->prefix . TZFD_Tables::BOOKINGS, $tzfd_report_company,
    $tz_search_on, $tz_like, $tz_like,
    $tz_from_on, $tz_date_start,
    $tz_to_on, $tz_date_end . ' 23:59:59',
    $tz_item_on, $this->item
) );

// A database error must not be interpreted as an empty report. Validate the
// result collection before calculating statistics, totals or graph labels.
if ( ! is_array( $tz_events ) ) {
    wp_die( esc_html__( 'Unable to load booking statistics. Please retry.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
}
// general initialization
$tz_fields = array();
$tz_fields["date"] = array();
$tz_fields["ip"] = array();
$tz_fields["notifyto"] = array();

// --- MODIFICATION START: Init revenue variables ---
$tz_revenue_daily = array();
$tz_total_revenue = 0;
// --- MODIFICATION END ---

foreach ($tz_events as $tz_item)
{
    // Historical rows may be partially populated or damaged. Never use an
    // object as an array key or read undefined properties while reporting.
    if ( ! is_object( $tz_item ) || ! isset( $tz_item->time, $tz_item->notifyto, $tz_item->ipaddr ) ||
        ! is_scalar( $tz_item->time ) || ! is_scalar( $tz_item->notifyto ) ||
        ! is_scalar( $tz_item->ipaddr ) || ! isset( $tz_item->posted_data ) ||
        ! is_string( $tz_item->posted_data ) ) {
        continue;
    }
    $tz_item->time = (string) $tz_item->time;
    $tz_item->notifyto = (string) $tz_item->notifyto;
    $tz_item->ipaddr = (string) $tz_item->ipaddr;
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/D', $tz_item->time ) ||
        strlen( $tz_item->notifyto ) > 254 || strlen( $tz_item->ipaddr ) > 64 ) {
        continue;
    }
    if (empty($tz_fields["date"]["k".substr($tz_item->time,0,10)])) $tz_fields["date"]["k".substr($tz_item->time,0,10)] = 0;
    if (empty($tz_fields["time"]["k".substr($tz_item->time,11,2)])) $tz_fields["time"]["k".substr($tz_item->time,11,2)] = 0;
    if (empty($tz_fields["notifyto"]["k".$tz_item->notifyto])) $tz_fields["notifyto"]["k".$tz_item->notifyto] = 0;
    if (empty($tz_fields["ip"]["k".$tz_item->ipaddr])) $tz_fields["ip"]["k".$tz_item->ipaddr] = 0;    
    
    $tz_fields["date"]["k".substr($tz_item->time,0,10)]++;
    $tz_fields["time"]["k".substr($tz_item->time,11,2)]++;
    $tz_fields["notifyto"]["k".$tz_item->notifyto]++;
    $tz_fields["ip"]["k".$tz_item->ipaddr]++;
    $tz_params = TZFD_Stored_Data::decode_booking($tz_item->posted_data);
    if (null === $tz_params) { continue; }
    
    // --- MODIFICATION START: Calculate Revenue ---
    $tz_price = 0;
    if ( isset( $tz_params['final_price'] ) && is_scalar( $tz_params['final_price'] ) &&
        is_numeric( $tz_params['final_price'] ) ) {
        $tz_candidate_price = (float) $tz_params['final_price'];
        if ( is_finite( $tz_candidate_price ) && $tz_candidate_price >= 0 && $tz_candidate_price <= 1000000000 ) {
            $tz_price = $tz_candidate_price;
        }
    }
    
    $tz_date_key = "k".substr($tz_item->time,0,10);
    if (empty($tz_revenue_daily[$tz_date_key])) $tz_revenue_daily[$tz_date_key] = 0;
    $tz_revenue_daily[$tz_date_key] += $tz_price;
    $tz_total_revenue += $tz_price;
    // --- MODIFICATION END ---

    foreach ($tz_params as $tz_param => $tz_value)
        if ( is_string( $tz_param ) && strlen( $tz_param ) <= 80 && is_scalar( $tz_value ) &&
            strlen( (string) $tz_value ) < 100 )
        {
            if (empty($tz_fields[$tz_param]["k".$tz_value])) $tz_fields[$tz_param]["k".$tz_value] = 0;
            $tz_fields[$tz_param]["k".$tz_value]++;
        }
}


// line graphs
$tz_hourly_messages = '';
$tz_max_hourly_messages = 200;
for ($tz_i=0;$tz_i<=23;$tz_i++)
    if (isset($tz_fields['time']['k'.($tz_i<10?'0':'').$tz_i]))
    {
        $tz_hourly_messages .= $tz_fields['time']['k'.($tz_i<10?'0':'').$tz_i].($tz_i<23?',':'');
        if ($tz_max_hourly_messages < intval($tz_fields['time']['k'.($tz_i<10?'0':'').$tz_i]))
            $tz_max_hourly_messages = intval($tz_fields['time']['k'.($tz_i<10?'0':'').$tz_i]);
    }        
    else
        $tz_hourly_messages .='0'.($tz_i<23?',':'');

if ($tz_date_start == '')
{
    $tz_kkeys = array_keys($tz_fields["date"]);
    if (count($tz_kkeys))
        $tz_date_start = substr(min($tz_kkeys),1);
    else
        $tz_date_start = gmdate("Y-m-d");
}

if ($tz_date_end == '')
{
    $tz_kkeys = array_keys($tz_fields["date"]);    
    if (count($tz_kkeys))
        $tz_date_end = substr(max($tz_kkeys),1);
    else
        $tz_date_end = gmdate("Y-m-d");    
}

$tz_daily_messages = '';
$tz_daily_revenue_str = ''; // --- MODIFICATION: Init revenue string
$tz_max_daily_messages = 200;
$tz_date = $tz_date_start;
while ($tz_date <= $tz_date_end)
{
    // Messages Logic
    if (isset($tz_fields['date']['k'.$tz_date]))
    {
        $tz_daily_messages .= ','.$tz_fields['date']['k'.$tz_date];
        if ($tz_max_daily_messages < intval($tz_fields['date']['k'.$tz_date]))
            $tz_max_daily_messages = intval($tz_fields['date']['k'.$tz_date]);        
    }        
    else
        $tz_daily_messages .=',0';

    // --- MODIFICATION START: Revenue Logic ---
    if (isset($tz_revenue_daily['k'.$tz_date]))
        $tz_daily_revenue_str .= ','.$tz_revenue_daily['k'.$tz_date];
    else
        $tz_daily_revenue_str .= ',0';
    // --- MODIFICATION END ---

    $tz_date = gmdate("Y-m-d",strtotime($tz_date." +1 day"));
}
$tz_daily_messages = substr($tz_daily_messages,1);
$tz_daily_revenue_str = substr($tz_daily_revenue_str,1); // --- MODIFICATION: Trim string

if (!isset($_GET["field"]))
    $tz_field_filter = 'time';
else
    $tz_field_filter = sanitize_key(tzfd_request_text( 'get', "field" ));

$tz_color_array = array('ffb3ba','ffdfba','ffffba', 'baffc9', 'bae1ff', 'a8e6cf', 'dcedc1', 'ffd3b6', 'ffaaa5', 'ff8b94', 'eea990', 'adcbe3', 'e2f4c7');


if ($this->item)
{
    $tz_form = json_decode($this->cleanJSON($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)));
    $tz_form = $tz_form[0];
}
else
    $tz_form = array();

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
$tz_report_deps = array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker' );
if ( wp_script_is( 'tzfdstatschart', 'registered' ) ) {
    $tz_report_deps[] = 'tzfdstatschart';
}
wp_enqueue_script( 'tzfd-report', plugins_url( 'js/inline/admina-report.js', __FILE__ ), $tz_report_deps, '2.0.1', true );

?>

<h1><?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'analytics' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e('Stats','titan-zero-trades-front-desk'); ?> -<?php echo esc_html($this->get_option("form_name")); ?></h1>

<div class="tzfd-buttons-container">
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>

<?php do_action("tz_fd_do_action_reportstats"); ?>

<?php require_once dirname( __FILE__ ).'/tzfd-full-stats.inc.php'; ?>


<div class="tzfd-section-container">
	<div class="tzfd-section">
       <form action="admin.php" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
        <input type="hidden" name="cal" value="<?php echo intval($this->item); ?>" />
        <input type="hidden" name="report" value="1" />
        <input type="hidden" name="field" value="<?php echo esc_attr($tz_field_filter); ?>" />
        <input type="hidden" name="anonce" value="<?php echo esc_attr( wp_create_nonce( 'tz_fd_actions_booking' ) ); ?>" />
		<nobr><label><?php esc_html_e('Search for','titan-zero-trades-front-desk'); ?>:</label> <input type="text" name="search" value="<?php echo esc_attr( $tz_raw_search); ?>">&nbsp;&nbsp;</nobr>
		<nobr><label><?php esc_html_e('From','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dfrom" name="dfrom" value="<?php echo esc_attr($tz_rawfrom); ?>" >&nbsp;&nbsp;</nobr>
		<nobr><label><?php esc_html_e('To','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dto" name="dto" value="<?php echo esc_attr($tz_rawto); ?>" >&nbsp;&nbsp;</nobr>		
		<nobr><label><?php esc_html_e('Item','titan-zero-trades-front-desk'); ?>:</label> <select id="cal" name="cal">
          <?php if ($tz_current_user_access) { ?> <option value="0">[<?php esc_html_e('All Items','titan-zero-trades-front-desk'); ?>]</option><?php } ?>
   <?php
    $tz_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE company_id=%d", $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_report_company ) );
	$tz_saveditem = $this->item;
    foreach ($tz_myrows as $tz_item)
    {
        $this->setId($tz_item->id);
        $tz_options = $tz_calendar_access_ids; 
		$this->setId($tz_saveditem);
        if (!is_array($tz_options))
             $tz_options = array();        
        if ($tz_current_user_access || in_array($current_user->ID, $tz_options, true))
            echo '<option value="'.intval($tz_item->id).'"'.(intval($tz_item->id)==intval($this->item)?" selected":"").'>'.esc_html($tz_item->form_name).'</option>';
    }
   ?>
    </select></nobr>
		<nobr>
			<input type="submit" name="ds" value="<?php esc_html_e('Filter','titan-zero-trades-front-desk'); ?>" class="button-primary button" style="float:right;">
		</nobr>
       </form>
       <div style="clear:both"></div>
	</div>
</div> 
<div class="container">  
  <div class="col6">  
    <div class="tzfd-graphs" >
	  <div class="tzfd-statssection-header">
	  	<h3><?php esc_html_e('Submissions per day in the selected date range.','titan-zero-trades-front-desk'); ?></h3>
	  	<span><?php esc_html_e('Days from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tz_date_start); ?> to <?php echo esc_html($tz_date_end); ?></span>
	  </div>
	  <div class="tzfd-statssection">
          <canvas id="chartperday"  st="<?php echo esc_attr($tz_date_start); ?>" et="<?php echo esc_attr($tz_date_end); ?>" label="<?php esc_html_e('Submissions.','titan-zero-trades-front-desk'); ?>" questions='[{"color":"#008ec2","values":[<?php echo esc_attr($tz_daily_messages); ?>]}]'></canvas>
	  </div>
    </div> 
  </div>  
  <div class="col6">  
    <div class="tzfd-graphs" >
	  <div class="tzfd-statssection-header">
	  	<h3><?php esc_html_e('Total submissions per hour in the selected date range.','titan-zero-trades-front-desk'); ?></h3>
	  	<span><?php esc_html_e('Days from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tz_date_start); ?> to <?php echo esc_html($tz_date_end); ?></span>
	  </div>
	  <div class="tzfd-statssection">
          <canvas id="chartperhour"  st="<?php echo esc_attr($tz_date_start); ?>" et="<?php echo esc_attr($tz_date_end); ?>" label="<?php esc_html_e('Submissions.','titan-zero-trades-front-desk'); ?>" questions='[{"color":"#008ec2","values":[<?php echo esc_attr($tz_hourly_messages); ?>]}]'></canvas>
	  </div>
    </div> 
  </div>
</div>

<br />

<div class="container">
  <div class="col12">
    <div class="tzfd-graphs">
        <div class="tzfd-statssection-header">
            <h3><?php esc_html_e('Revenue per day','titan-zero-trades-front-desk'); ?> (Total: <?php echo esc_html( number_format( $tz_total_revenue, 2 ) ); ?>)</h3>
            <span><?php esc_html_e('Days from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tz_date_start); ?> to <?php echo esc_html($tz_date_end); ?></span>
        </div>
        <div class="tzfd-statssection">
            <canvas id="chartrevenue" st="<?php echo esc_attr($tz_date_start); ?>" et="<?php echo esc_attr($tz_date_end); ?>" label="<?php esc_html_e('Revenue','titan-zero-trades-front-desk'); ?>" questions='[{"color":"#4caf50","values":[<?php echo esc_attr($tz_daily_revenue_str); ?>]}]'></canvas>
        </div>
    </div>
  </div>
</div>
<br />

<div class="tzfd-statssection-container" style="background:#232834;border-color:#333a48;">
	<div class="tzfd-statssection-header">
        <form action="admin.php" name="cfm_formrep" method="get">
         <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
         <input type="hidden" name="cal" value="<?php echo intval($this->item); ?>" />
         <input type="hidden" name="report" value="1" />
         <input type="hidden" name="search" value="<?php echo esc_attr($tz_raw_search); ?>" />
         <input type="hidden" name="dfrom" value="<?php echo esc_attr($tz_rawfrom); ?>" />
         <input type="hidden" name="dto" value="<?php echo esc_attr($tz_rawto); ?>" />
		 <h3><?php esc_html_e('Select field for the report','titan-zero-trades-front-desk'); ?>: <select name="field">
              <?php
                   foreach ($tz_fields as $tz_item => $tz_value)
                       echo '<option value="'.esc_attr($tz_item).'"'.($tz_field_filter==$tz_item?' selected':'').'>'.esc_html($this->get_form_field_label($tz_item,$tz_form)).'</option>';
              ?>
         </select></h3>
        </form>
	</div>
	<div class="tzfd-statssection">
        <div id="dex_printable_contents">

        <div style="width:100%;padding:0;background:#232834;border:1px solid #333a48;color:#f2f4f8;">
         <div style="padding:10px;background:#1f232d;color:#f2f4f8;font-weight: bold;">
           <?php esc_html_e('Report of values for','titan-zero-trades-front-desk'); ?>: <em><?php echo esc_html($this->get_form_field_label(sanitize_text_field($tz_field_filter),$tz_form)); ?></em>
         </div>

        <div style="padding:10px;">
        <?php
          $tz_arr = $tz_fields[$tz_field_filter] ?? null;
          if (!is_array($tz_arr))
              $tz_arr = array();
          arsort($tz_arr, SORT_NUMERIC);
          $tz_total = 0;
          /* $totalsize = 600; */
          foreach ($tz_arr as $tz_item => $tz_value)
              $tz_total += $tz_value;
          /* $max = max($tz_arr);
          $totalsize = round(600 / ($max/$tz_total) ); */
          $tz_count = 0;
          foreach ($tz_arr as $tz_item => $tz_value)
          {
              echo esc_html($tz_value.' times: '.(strlen($tz_item)>50?substr($tz_item,1,50).'...':substr($tz_item,1)));
              echo '<div style="width:'.esc_attr(round($tz_value/$tz_total*100)).'%;border:1px solid white;margin-bottom:3px;font-size:9px;text-align:center;font-weight:bold;background-color:#'.esc_attr($tz_color_array[$tz_count]).'">'.esc_html(round($tz_value/$tz_total*100,2)).'%</div>';
              $tz_count++;
              if ($tz_count >= count($tz_color_array)) $tz_count = count($tz_color_array)-1;
          }
        ?>
        </div>

         <div style="padding-right:5px;padding-left:5px;margin-bottom:20px;color:#a4acba;">&nbsp;&nbsp;* <?php esc_html_e('Number of times that appears each value. Percent in relation to the total of submissions.','titan-zero-trades-front-desk'); ?><br />&nbsp;&nbsp;&nbsp;&nbsp; <?php esc_html_e('Date range from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tz_date_start); ?> <?php esc_html_e('to','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tz_date_end); ?>.</div>
        </div>

        <div style="clear:both"></div>
        </div>
	</div>
</div>



<div class="tzfd-buttons-container">
	<input type="button" value="<?php esc_html_e('Print Stats','titan-zero-trades-front-desk'); ?>" id="tzfd-print-stats" class="button button-primary" />
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>
