<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.NonceVerification.Recommended -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked. Read-only report parameters on a capability-gated admin screen; this screen changes no state.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';


global $wpdb;

$this->item = intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) ));

$current_user = wp_get_current_user();
$tzfd_current_user_access = current_user_can('manage_options');
$tzfd_calendar_access_ids = TZFD_Access_List::decode( $this->get_option( 'tzfd_user_access', '' ) );

if ( !is_admin() || (!$tzfd_current_user_access && !in_array($current_user->ID, $tzfd_calendar_access_ids, true)))
{
    echo 'Direct access not allowed.';
    exit;
}

if ( !is_admin() )
{
    echo 'Direct access not allowed.';
    exit;
}

global $tzfd_numberofdates;
$tzfd_numberofdates = [];


if ($this->item != 0)
    $tzfd_myform = $wpdb->get_results( $wpdb->prepare('SELECT * FROM %i WHERE id=%d', $wpdb->prefix.'tzfd_forms', $this->item) );  


$tzfd_current_page = intval( (empty($_GET["p"])?0:sanitize_text_field( wp_unslash( $_GET["p"] ?? '' ) )));
if (!$tzfd_current_page) $tzfd_current_page = 1;
$tzfd_records_per_page = 100;

$tzfd_date_start = '';
$tzfd_date_end = '';

$tzfd_rawfrom = (isset($_GET["dfrom"]) ? sanitize_text_field(wp_unslash( $_GET["dfrom"] ?? '' )) : '');
$tzfd_rawto = (isset($_GET["dto"]) ? sanitize_text_field(wp_unslash( $_GET["dto"] ?? '' )) : '');
if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy')
{
    $tzfd_rawfrom = str_replace('/','.',$tzfd_rawfrom);
    $tzfd_rawto = str_replace('/','.',$tzfd_rawto);
}

// Each optional filter is always bound. Its flag is 1 only when the filter applies, so the SQL text is fixed:
// "(flag = 0 OR condition)" matches every row when the filter is off.
$tzfd_search_on = ( ! empty( $_GET['search'] ) ) ? 1 : 0;
$tzfd_like      = '';
if ( $tzfd_search_on ) {
    $search    = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
    $tzfd_like = '%' . $wpdb->esc_like( $search ) . '%';
}

$tzfd_from_on = ( $tzfd_rawfrom != '' ) ? 1 : 0;
if ( $tzfd_from_on ) {
    $tzfd_date_start = gmdate( 'Y-m-d', strtotime( $tzfd_rawfrom ) );
}

$tzfd_to_on = ( $tzfd_rawto != '' ) ? 1 : 0;
if ( $tzfd_to_on ) {
    $tzfd_date_end = gmdate( 'Y-m-d', strtotime( $tzfd_rawto ) );
}

$tzfd_item_on = ( $this->item != 0 ) ? 1 : 0;

$tzfd_events = $wpdb->get_results( $wpdb->prepare(
    "SELECT ipaddr, time, notifyto, posted_data FROM %i
     WHERE 1=1
       AND (%d = 0 OR (data LIKE %s OR posted_data LIKE %s))
       AND (%d = 0 OR (`time` >= %s))
       AND (%d = 0 OR (`time` <= %s))
       AND (%d = 0 OR formid = %d)
     ORDER BY `time` DESC",
    $wpdb->prefix . 'tzfd_messages',
    $tzfd_search_on, $tzfd_like, $tzfd_like,
    $tzfd_from_on, $tzfd_date_start,
    $tzfd_to_on, $tzfd_date_end . ' 23:59:59',
    $tzfd_item_on, $this->item
) );

// general initialization
$tzfd_fields = array();
$tzfd_fields["date"] = array();
$tzfd_fields["ip"] = array();
$tzfd_fields["notifyto"] = array();

// --- MODIFICATION START: Init revenue variables ---
$tzfd_revenue_daily = array();
$tzfd_total_revenue = 0;
// --- MODIFICATION END ---

foreach ($tzfd_events as $tzfd_item)
{
    if (empty($tzfd_fields["date"]["k".substr($tzfd_item->time,0,10)])) $tzfd_fields["date"]["k".substr($tzfd_item->time,0,10)] = 0;
    if (empty($tzfd_fields["time"]["k".substr($tzfd_item->time,11,2)])) $tzfd_fields["time"]["k".substr($tzfd_item->time,11,2)] = 0;
    if (empty($tzfd_fields["notifyto"]["k".$tzfd_item->notifyto])) $tzfd_fields["notifyto"]["k".$tzfd_item->notifyto] = 0;
    if (empty($tzfd_fields["ip"]["k".$tzfd_item->ipaddr])) $tzfd_fields["ip"]["k".$tzfd_item->ipaddr] = 0;    
    
    $tzfd_fields["date"]["k".substr($tzfd_item->time,0,10)]++;
    $tzfd_fields["time"]["k".substr($tzfd_item->time,11,2)]++;
    $tzfd_fields["notifyto"]["k".$tzfd_item->notifyto]++;
    $tzfd_fields["ip"]["k".$tzfd_item->ipaddr]++;
    $tzfd_params = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
    if (null === $tzfd_params) { continue; }
    
    // --- MODIFICATION START: Calculate Revenue ---
    $tzfd_price = 0;
    if (isset($tzfd_params["final_price"])) 
        $tzfd_price = floatval($tzfd_params["final_price"]);
    
    $tzfd_date_key = "k".substr($tzfd_item->time,0,10);
    if (empty($tzfd_revenue_daily[$tzfd_date_key])) $tzfd_revenue_daily[$tzfd_date_key] = 0;
    $tzfd_revenue_daily[$tzfd_date_key] += $tzfd_price;
    $tzfd_total_revenue += $tzfd_price;
    // --- MODIFICATION END ---

    foreach ($tzfd_params as $tzfd_param => $tzfd_value)
        if (is_scalar($tzfd_value) && strlen((string)$tzfd_value) < 100)
        {
            if (empty($tzfd_fields[$tzfd_param]["k".$tzfd_value])) $tzfd_fields[$tzfd_param]["k".$tzfd_value] = 0;
            $tzfd_fields[$tzfd_param]["k".$tzfd_value]++;
        }
}


// line graphs
$tzfd_hourly_messages = '';
$tzfd_max_hourly_messages = 200;
for ($tzfd_i=0;$tzfd_i<=23;$tzfd_i++)
    if (isset($tzfd_fields['time']['k'.($tzfd_i<10?'0':'').$tzfd_i]))
    {
        $tzfd_hourly_messages .= $tzfd_fields['time']['k'.($tzfd_i<10?'0':'').$tzfd_i].($tzfd_i<23?',':'');
        if ($tzfd_max_hourly_messages < intval($tzfd_fields['time']['k'.($tzfd_i<10?'0':'').$tzfd_i]))
            $tzfd_max_hourly_messages = intval($tzfd_fields['time']['k'.($tzfd_i<10?'0':'').$tzfd_i]);
    }        
    else
        $tzfd_hourly_messages .='0'.($tzfd_i<23?',':'');

if ($tzfd_date_start == '')
{
    $tzfd_kkeys = array_keys($tzfd_fields["date"]);
    if (count($tzfd_kkeys))
        $tzfd_date_start = substr(min($tzfd_kkeys),1);
    else
        $tzfd_date_start = gmdate("Y-m-d");
}

if ($tzfd_date_end == '')
{
    $tzfd_kkeys = array_keys($tzfd_fields["date"]);    
    if (count($tzfd_kkeys))
        $tzfd_date_end = substr(max($tzfd_kkeys),1);
    else
        $tzfd_date_end = gmdate("Y-m-d");    
}

$tzfd_daily_messages = '';
$tzfd_daily_revenue_str = ''; // --- MODIFICATION: Init revenue string
$tzfd_max_daily_messages = 200;
$tzfd_date = $tzfd_date_start;
while ($tzfd_date <= $tzfd_date_end)
{
    // Messages Logic
    if (isset($tzfd_fields['date']['k'.$tzfd_date]))
    {
        $tzfd_daily_messages .= ','.$tzfd_fields['date']['k'.$tzfd_date];
        if ($tzfd_max_daily_messages < intval($tzfd_fields['date']['k'.$tzfd_date]))
            $tzfd_max_daily_messages = intval($tzfd_fields['date']['k'.$tzfd_date]);        
    }        
    else
        $tzfd_daily_messages .=',0';

    // --- MODIFICATION START: Revenue Logic ---
    if (isset($tzfd_revenue_daily['k'.$tzfd_date]))
        $tzfd_daily_revenue_str .= ','.$tzfd_revenue_daily['k'.$tzfd_date];
    else
        $tzfd_daily_revenue_str .= ',0';
    // --- MODIFICATION END ---

    $tzfd_date = gmdate("Y-m-d",strtotime($tzfd_date." +1 day"));
}
$tzfd_daily_messages = substr($tzfd_daily_messages,1);
$tzfd_daily_revenue_str = substr($tzfd_daily_revenue_str,1); // --- MODIFICATION: Trim string

if (!isset($_GET["field"]))
    $tzfd_field_filter = 'time';
else
    $tzfd_field_filter = sanitize_key(wp_unslash( $_GET["field"] ?? '' ));

$tzfd_color_array = array('ffb3ba','ffdfba','ffffba', 'baffc9', 'bae1ff', 'a8e6cf', 'dcedc1', 'ffd3b6', 'ffaaa5', 'ff8b94', 'eea990', 'adcbe3', 'e2f4c7');


if ($this->item)
{
    $tzfd_form = json_decode($this->cleanJSON($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)));
    $tzfd_form = $tzfd_form[0];
}
else
    $tzfd_form = array();

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
$tzfd_report_deps = array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker' );
if ( wp_script_is( 'tzfdstatschart', 'registered' ) ) {
    $tzfd_report_deps[] = 'tzfdstatschart';
}
wp_enqueue_script( 'tzfd-report', plugins_url( 'js/inline/admina-report.js', __FILE__ ), $tzfd_report_deps, '2.0.1', true );

?>

<h1><?php esc_html_e('Stats','titan-zero-trades-front-desk'); ?> - <?php echo esc_html($this->get_option("form_name")); ?></h1>

<div class="tzfd-buttons-container">
	<a href="<?php print esc_attr(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>

<?php do_action("tzfd_do_action_reportstats"); ?>

<?php require_once dirname( __FILE__ ).'/tzfd-full-stats.inc.php'; ?>


<div class="tzfd-section-container">
	<div class="tzfd-section">
       <form action="admin.php" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
        <input type="hidden" name="cal" value="<?php echo intval($this->item); ?>" />
        <input type="hidden" name="report" value="1" />
        <input type="hidden" name="field" value="<?php echo esc_attr($tzfd_field_filter); ?>" />
        <input type="hidden" name="anonce" value="<?php echo esc_attr( wp_create_nonce( 'tzfd_actions_booking' ) ); ?>" />
		<nobr><label><?php esc_html_e('Search for','titan-zero-trades-front-desk'); ?>:</label> <input type="text" name="search" value="<?php echo esc_attr( (!empty($_GET["search"])?sanitize_text_field(wp_unslash( $_GET["search"] ?? '' )):'')); ?>">&nbsp;&nbsp;</nobr>
		<nobr><label><?php esc_html_e('From','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dfrom" name="dfrom" value="<?php echo esc_attr((!empty($_GET["dfrom"])?sanitize_text_field(wp_unslash( $_GET["dfrom"] ?? '' )):'')); ?>" >&nbsp;&nbsp;</nobr>
		<nobr><label><?php esc_html_e('To','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dto" name="dto" value="<?php echo esc_attr((!empty($_GET["dto"])?sanitize_text_field(wp_unslash( $_GET["dto"] ?? '' )):'')); ?>" >&nbsp;&nbsp;</nobr>		
		<nobr><label><?php esc_html_e('Item','titan-zero-trades-front-desk'); ?>:</label> <select id="cal" name="cal">
          <?php if ($tzfd_current_user_access) { ?> <option value="0">[<?php esc_html_e('All Items','titan-zero-trades-front-desk'); ?>]</option><?php } ?>
   <?php
    $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i", $wpdb->prefix . 'tzfd_forms' ) );
	$tzfd_saveditem = $this->item;
    foreach ($tzfd_myrows as $tzfd_item)
    {
        $this->setId($tzfd_item->id);
        $tzfd_options = $tzfd_calendar_access_ids; 
		$this->setId($tzfd_saveditem);
        if (!is_array($tzfd_options))
             $tzfd_options = array();        
        if ($tzfd_current_user_access || in_array($current_user->ID, $tzfd_options, true))
            echo '<option value="'.intval($tzfd_item->id).'"'.(intval($tzfd_item->id)==intval($this->item)?" selected":"").'>'.esc_html($tzfd_item->form_name).'</option>';
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
	  	<span><?php esc_html_e('Days from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tzfd_date_start); ?> to <?php echo esc_html($tzfd_date_end); ?></span>
	  </div>
	  <div class="tzfd-statssection">
          <canvas id="chartperday"  st="<?php echo esc_attr($tzfd_date_start); ?>" et="<?php echo esc_attr($tzfd_date_end); ?>" label="<?php esc_html_e('Submissions.','titan-zero-trades-front-desk'); ?>" questions='[{"color":"#008ec2","values":[<?php echo esc_attr($tzfd_daily_messages); ?>]}]'></canvas>
	  </div>
    </div> 
  </div>  
  <div class="col6">  
    <div class="tzfd-graphs" >
	  <div class="tzfd-statssection-header">
	  	<h3><?php esc_html_e('Total submissions per hour in the selected date range.','titan-zero-trades-front-desk'); ?></h3>
	  	<span><?php esc_html_e('Days from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tzfd_date_start); ?> to <?php echo esc_html($tzfd_date_end); ?></span>
	  </div>
	  <div class="tzfd-statssection">
          <canvas id="chartperhour"  st="<?php echo esc_attr($tzfd_date_start); ?>" et="<?php echo esc_attr($tzfd_date_end); ?>" label="<?php esc_html_e('Submissions.','titan-zero-trades-front-desk'); ?>" questions='[{"color":"#008ec2","values":[<?php echo esc_attr($tzfd_hourly_messages); ?>]}]'></canvas>
	  </div>
    </div> 
  </div>
</div>

<br />

<div class="container">
  <div class="col12">
    <div class="tzfd-graphs">
        <div class="tzfd-statssection-header">
            <h3><?php esc_html_e('Revenue per day','titan-zero-trades-front-desk'); ?> (Total: <?php echo esc_html( number_format( $tzfd_total_revenue, 2 ) ); ?>)</h3>
            <span><?php esc_html_e('Days from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tzfd_date_start); ?> to <?php echo esc_html($tzfd_date_end); ?></span>
        </div>
        <div class="tzfd-statssection">
            <canvas id="chartrevenue" st="<?php echo esc_attr($tzfd_date_start); ?>" et="<?php echo esc_attr($tzfd_date_end); ?>" label="<?php esc_html_e('Revenue','titan-zero-trades-front-desk'); ?>" questions='[{"color":"#4caf50","values":[<?php echo esc_attr($tzfd_daily_revenue_str); ?>]}]'></canvas>
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
         <input type="hidden" name="search" value="<?php echo esc_attr((!empty($_GET["search"])?sanitize_text_field(wp_unslash( $_GET["search"] ?? '' )):'')); ?>" />
         <input type="hidden" name="dfrom" value="<?php echo esc_attr((!empty($_GET["dfrom"])?sanitize_text_field(wp_unslash( $_GET["dfrom"] ?? '' )):'')); ?>" />
         <input type="hidden" name="dto" value="<?php echo esc_attr((!empty($_GET["dto"])?sanitize_text_field(wp_unslash( $_GET["dto"] ?? '' )):'')); ?>" />
		 <h3><?php esc_html_e('Select field for the report','titan-zero-trades-front-desk'); ?>: <select name="field">
              <?php
                   foreach ($tzfd_fields as $tzfd_item => $tzfd_value)
                       echo '<option value="'.esc_attr($tzfd_item).'"'.($tzfd_field_filter==$tzfd_item?' selected':'').'>'.esc_html($this->get_form_field_label($tzfd_item,$tzfd_form)).'</option>';
              ?>
         </select></h3>
        </form>
	</div>
	<div class="tzfd-statssection">
        <div id="dex_printable_contents">

        <div style="width:100%;padding:0;background:#232834;border:1px solid #333a48;color:#f2f4f8;">
         <div style="padding:10px;background:#1f232d;color:#f2f4f8;font-weight: bold;">
           <?php esc_html_e('Report of values for','titan-zero-trades-front-desk'); ?>: <em><?php echo esc_html($this->get_form_field_label(sanitize_text_field($tzfd_field_filter),$tzfd_form)); ?></em>
         </div>

        <div style="padding:10px;">
        <?php
          $tzfd_arr = $tzfd_fields[$tzfd_field_filter] ?? null;
          if (!is_array($tzfd_arr))
              $tzfd_arr = array();
          arsort($tzfd_arr, SORT_NUMERIC);
          $tzfd_total = 0;
          /* $totalsize = 600; */
          foreach ($tzfd_arr as $tzfd_item => $tzfd_value)
              $tzfd_total += $tzfd_value;
          /* $max = max($tzfd_arr);
          $totalsize = round(600 / ($max/$tzfd_total) ); */
          $tzfd_count = 0;
          foreach ($tzfd_arr as $tzfd_item => $tzfd_value)
          {
              echo esc_html($tzfd_value.' times: '.(strlen($tzfd_item)>50?substr($tzfd_item,1,50).'...':substr($tzfd_item,1)));
              echo '<div style="width:'.esc_attr(round($tzfd_value/$tzfd_total*100)).'%;border:1px solid white;margin-bottom:3px;font-size:9px;text-align:center;font-weight:bold;background-color:#'.esc_attr($tzfd_color_array[$tzfd_count]).'">'.esc_html(round($tzfd_value/$tzfd_total*100,2)).'%</div>';
              $tzfd_count++;
              if ($tzfd_count >= count($tzfd_color_array)) $tzfd_count = count($tzfd_color_array)-1;
          }
        ?>
        </div>

         <div style="padding-right:5px;padding-left:5px;margin-bottom:20px;color:#a4acba;">&nbsp;&nbsp;* <?php esc_html_e('Number of times that appears each value. Percent in relation to the total of submissions.','titan-zero-trades-front-desk'); ?><br />&nbsp;&nbsp;&nbsp;&nbsp; <?php esc_html_e('Date range from','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tzfd_date_start); ?> <?php esc_html_e('to','titan-zero-trades-front-desk'); ?> <?php echo esc_html($tzfd_date_end); ?>.</div>
        </div>

        <div style="clear:both"></div>
        </div>
	</div>
</div>



<div class="tzfd-buttons-container">
	<input type="button" value="<?php esc_html_e('Print Stats','titan-zero-trades-front-desk'); ?>" id="tzfd-print-stats" class="button button-primary" />
	<a href="<?php print esc_attr(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>
