<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.NonceVerification.Recommended -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked. Read-only statistics parameters on a capability-gated admin screen; this screen changes no state.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';

global $wpdb;

$this->item = intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) ));

$current_user = wp_get_current_user();
$tzfd_current_user_access = current_user_can('edit_pages');
$tzfd_calendar_access_ids = TZFD_Access_List::decode( $this->get_option( 'tzfd_user_access', '' ) );

if ( !is_admin() || (!$tzfd_current_user_access && !in_array($current_user->ID, $tzfd_calendar_access_ids, true)))
{
    echo 'Direct access not allowed.';
    exit;
}

// pre-select time-slots
$tzfd_selection = array();
$tzfd_rows = $wpdb->get_results( $wpdb->prepare("SELECT time,posted_data FROM %i WHERE notifyto<>%s AND formid=%d ORDER BY time DESC LIMIT 0,100000", $wpdb->prefix.'tzfd_messages', $this->blocked_by_admin_indicator, $this->item) );  

$tzfd_yearly_incoming = array();
$tzfd_monthly_incoming = array();
$tzfd_weekly_incoming = array();
$tzfd_daily_incoming = array();

$tzfd_yearly = array();
$tzfd_monthly = array();
$tzfd_weekly = array();
$tzfd_daily = array();
$tzfd_currentdate = strtotime(gmdate("Y-m-d"));


$tzfd_blockedstatuses = explode(",", get_option('tzfd_statuses_block',',Attended'));

foreach($tzfd_rows as $tzfd_item)
{        
    $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
    if (null === $tzfd_data || !isset($tzfd_data["apps"])) { continue; }
    foreach($tzfd_data["apps"] as $tzfd_app)
        if ( in_array($tzfd_app["cancelled"],$tzfd_blockedstatuses) )
        {       
                $tzfd_dt = strtotime($tzfd_app["date"]);
                $tzfd_dt_incoming = strtotime($tzfd_item->time);
                if ($tzfd_dt>=$tzfd_currentdate)
                {  
                    //$tzfd_selection[] = array($tzfd_app["date"]." ".$tzfd_app["slot"], $tzfd_app["date"], $tzfd_app["slot"]);
					if (!isset($tzfd_yearly["x".gmdate("Y",$tzfd_dt)])) $tzfd_yearly["x".gmdate("Y",$tzfd_dt)] = 0;
                    $tzfd_yearly["x".gmdate("Y",$tzfd_dt)]++;
					if (!isset($tzfd_monthly["x".gmdate("Ym",$tzfd_dt)])) $tzfd_monthly["x".gmdate("Ym",$tzfd_dt)] = 0;
                    $tzfd_monthly["x".gmdate("Ym",$tzfd_dt)]++;
					if (!isset($tzfd_weekly["x".gmdate("YW",$tzfd_dt)])) $tzfd_weekly["x".gmdate("YW",$tzfd_dt)] = 0;
                    $tzfd_weekly["x".gmdate("YW",$tzfd_dt)]++;
					if (!isset($tzfd_daily["x".gmdate("Ymd",$tzfd_dt)])) $tzfd_daily["x".gmdate("Ymd",$tzfd_dt)] = 0;
                    $tzfd_daily["x".gmdate("Ymd",$tzfd_dt)]++;                                   
                }
                if (empty($tzfd_yearly_incoming["x".gmdate("Y",$tzfd_dt_incoming)])) $tzfd_yearly_incoming["x".gmdate("Y",$tzfd_dt_incoming)] = 0;
                if (empty($tzfd_monthly_incoming["x".gmdate("Ym",$tzfd_dt_incoming)])) $tzfd_monthly_incoming["x".gmdate("Ym",$tzfd_dt_incoming)] = 0;
                if (empty($tzfd_weekly_incoming["x".gmdate("YW",$tzfd_dt_incoming)])) $tzfd_weekly_incoming["x".gmdate("YW",$tzfd_dt_incoming)] = 0;
                if (empty($tzfd_daily_incoming["x".gmdate("Ymd",$tzfd_dt_incoming)])) $tzfd_daily_incoming["x".gmdate("Ymd",$tzfd_dt_incoming)] = 0;                
                
                $tzfd_yearly_incoming["x".gmdate("Y",$tzfd_dt_incoming)]++;
                $tzfd_monthly_incoming["x".gmdate("Ym",$tzfd_dt_incoming)]++;
                $tzfd_weekly_incoming["x".gmdate("YW",$tzfd_dt_incoming)]++;
                $tzfd_daily_incoming["x".gmdate("Ymd",$tzfd_dt_incoming)]++;                  
        }    
}


function tzfd_stats_get_monthly($tzfd_arr, $is_incoming = false)
{
    $str = '';
    $tzfd_dt = ($is_incoming ? strtotime("-11 months") : time());
    $tzfd_dt = strtotime(gmdate("Y-m-01", $tzfd_dt));
    $x = []; $y = [];
    for ($tzfd_i=0;$tzfd_i<12;$tzfd_i++)
    {
        $x[] = gmdate("Ym",$tzfd_dt);
        $tzfd_key = "x".gmdate("Ym",$tzfd_dt);
        $y[] = (isset($tzfd_arr[$tzfd_key])?$tzfd_arr[$tzfd_key]:'0'); 
        $tzfd_dt = strtotime( "+1 month" ,$tzfd_dt);    
    }   
    echo ' x="'.esc_attr(join(",", $x)).'" y="'.esc_attr(join(",", $y)).'" '; 
}
function tzfd_stats_get_weekly($tzfd_arr, $is_incoming = false)
{
    $str = '';
    $tzfd_dt = ($is_incoming ? strtotime("-11 weeks") : time());
    $x = []; $y = [];
    for ($tzfd_i=0;$tzfd_i<12;$tzfd_i++)
    {
        $x[] = gmdate("YW",$tzfd_dt);
        $tzfd_key = "x".gmdate("YW",$tzfd_dt);
        $y[] = (isset($tzfd_arr[$tzfd_key])?$tzfd_arr[$tzfd_key]:'0');         
        $tzfd_dt = strtotime("+1 week",$tzfd_dt);    
    }   
    echo ' x="'.esc_attr(join(",", $x)).'" y="'.esc_attr(join(",", $y)).'" ';
}
function tzfd_stats_get_daily($tzfd_arr, $is_incoming = false)
{
    $str = '';
    $tzfd_dt = ($is_incoming ? strtotime("-29 days") : time());
    $x = []; $y = [];
    for ($tzfd_i=0;$tzfd_i<30;$tzfd_i++)
    {
        $x[] = gmdate("Ymd",$tzfd_dt);
        $tzfd_key = "x".gmdate("Ymd",$tzfd_dt); 
        $y[] = (isset($tzfd_arr[$tzfd_key])?$tzfd_arr[$tzfd_key]:'0'); 
        $tzfd_dt = strtotime("+1 day",$tzfd_dt);    
    }
    echo ' x="'.esc_attr(join(",", $x)).'" y="'.esc_attr(join(",", $y)).'" ';
}

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
wp_register_style( 'tzfd-full-stats-inline', false, array(), '2.0.1' );
wp_enqueue_style( 'tzfd-full-stats-inline' );
wp_add_inline_style( 'tzfd-full-stats-inline', "#cTable th{background:#2b3140;color:#f2f4f8}\n#cTable td{vertical-align:top}" );

?>
<div class="tzfd-statssection-header"><h2><?php esc_html_e('Submission time stats (date in which the appointment request was received)','titan-zero-trades-front-desk')?></h2></div>
<div class="container">  
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Incoming - Monthly Stats (lastest 12 months)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartMonthlyIncoming" label="<?php esc_html_e('Submissions','titan-zero-trades-front-desk'); ?>" <?php (tzfd_stats_get_monthly($tzfd_monthly_incoming, true)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Incoming - Weekly Stats (lastest 12 weeks)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartWeeklyIncoming" label="<?php esc_html_e('Submissions','titan-zero-trades-front-desk'); ?>" <?php (tzfd_stats_get_weekly($tzfd_weekly_incoming, true)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Daily Stats(next 30 days)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartDailyIncoming" label="<?php esc_html_e('Submissions','titan-zero-trades-front-desk'); ?>" <?php (tzfd_stats_get_daily($tzfd_daily_incoming, true)); ?>  ></canvas>
    	</div>
    </div> 
  </div> 
</div>

<div class="tzfd-statssection-header margin-top-15"><h2><?php esc_html_e('Booked times stats (appointment date)','titan-zero-trades-front-desk')?></h2></div>
<div class="container">
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Monthly Stats (next 12 months)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartMonthly" label="<?php esc_html_e('Booked times','titan-zero-trades-front-desk'); ?>" <?php (tzfd_stats_get_monthly($tzfd_monthly)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Weekly Stats (next 12 weeks)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartWeekly" label="<?php esc_html_e('Booked times','titan-zero-trades-front-desk'); ?>" <?php (tzfd_stats_get_weekly($tzfd_weekly)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Daily Stats(next 30 days)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartDaily" label="<?php esc_html_e('Booked times','titan-zero-trades-front-desk'); ?>" <?php (tzfd_stats_get_daily($tzfd_daily)); ?>  ></canvas>
    	</div>
    </div> 
  </div> 
</div>
