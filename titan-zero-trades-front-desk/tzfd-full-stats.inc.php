<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.NonceVerification.Recommended -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked. Read-only statistics parameters on a capability-gated admin screen; this screen changes no state.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';
require_once __DIR__ . '/includes/class-tzfd-booking-export-reader.php';

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

// pre-select time-slots
$tz_selection = array();
// Read the complete company- and calendar-scoped history. Reporting must never
// silently drop older bookings or present a partial result after an SQL failure.
$tz_rows = TZFD_Booking_Export_Reader::read(
    (int) $tzfd_report_company,
    (int) $this->item,
    '9999-12-31 23:59:59',
    (string) $this->blocked_by_admin_indicator
);
if ( null === $tz_rows ) {
    wp_die( esc_html__( 'Booking statistics are temporarily unavailable. No partial report was generated.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
}  

$tz_yearly_incoming = array();
$tz_monthly_incoming = array();
$tz_weekly_incoming = array();
$tz_daily_incoming = array();

$tz_yearly = array();
$tz_monthly = array();
$tz_weekly = array();
$tz_daily = array();
$tz_currentdate = strtotime(gmdate("Y-m-d"));


$tz_blockedstatuses = explode(",", get_option('tz_fd_statuses_block',',Attended'));

foreach($tz_rows as $tz_item)
{        
    $tz_data = TZFD_Stored_Data::decode_booking($tz_item->posted_data);
    if (null === $tz_data || !isset($tz_data["apps"])) { continue; }
    foreach($tz_data["apps"] as $tz_app)
        if ( in_array($tz_app["cancelled"],$tz_blockedstatuses) )
        {       
                $tz_dt = strtotime($tz_app["date"]);
                $tz_dt_incoming = strtotime($tz_item->time);
                if ($tz_dt>=$tz_currentdate)
                {  
                    //$tz_selection[] = array($tz_app["date"]." ".$tz_app["slot"], $tz_app["date"], $tz_app["slot"]);
					if (!isset($tz_yearly["x".gmdate("Y",$tz_dt)])) $tz_yearly["x".gmdate("Y",$tz_dt)] = 0;
                    $tz_yearly["x".gmdate("Y",$tz_dt)]++;
					if (!isset($tz_monthly["x".gmdate("Ym",$tz_dt)])) $tz_monthly["x".gmdate("Ym",$tz_dt)] = 0;
                    $tz_monthly["x".gmdate("Ym",$tz_dt)]++;
					if (!isset($tz_weekly["x".gmdate("YW",$tz_dt)])) $tz_weekly["x".gmdate("YW",$tz_dt)] = 0;
                    $tz_weekly["x".gmdate("YW",$tz_dt)]++;
					if (!isset($tz_daily["x".gmdate("Ymd",$tz_dt)])) $tz_daily["x".gmdate("Ymd",$tz_dt)] = 0;
                    $tz_daily["x".gmdate("Ymd",$tz_dt)]++;                                   
                }
                if (empty($tz_yearly_incoming["x".gmdate("Y",$tz_dt_incoming)])) $tz_yearly_incoming["x".gmdate("Y",$tz_dt_incoming)] = 0;
                if (empty($tz_monthly_incoming["x".gmdate("Ym",$tz_dt_incoming)])) $tz_monthly_incoming["x".gmdate("Ym",$tz_dt_incoming)] = 0;
                if (empty($tz_weekly_incoming["x".gmdate("YW",$tz_dt_incoming)])) $tz_weekly_incoming["x".gmdate("YW",$tz_dt_incoming)] = 0;
                if (empty($tz_daily_incoming["x".gmdate("Ymd",$tz_dt_incoming)])) $tz_daily_incoming["x".gmdate("Ymd",$tz_dt_incoming)] = 0;                
                
                $tz_yearly_incoming["x".gmdate("Y",$tz_dt_incoming)]++;
                $tz_monthly_incoming["x".gmdate("Ym",$tz_dt_incoming)]++;
                $tz_weekly_incoming["x".gmdate("YW",$tz_dt_incoming)]++;
                $tz_daily_incoming["x".gmdate("Ymd",$tz_dt_incoming)]++;                  
        }    
}


function tz_fd_stats_get_monthly($tz_arr, $is_incoming = false)
{
    $str = '';
    $tz_dt = ($is_incoming ? strtotime("-11 months") : time());
    $tz_dt = strtotime(gmdate("Y-m-01", $tz_dt));
    $x = []; $y = [];
    for ($tz_i=0;$tz_i<12;$tz_i++)
    {
        $x[] = gmdate("Ym",$tz_dt);
        $tz_key = "x".gmdate("Ym",$tz_dt);
        $y[] = (isset($tz_arr[$tz_key])?$tz_arr[$tz_key]:'0'); 
        $tz_dt = strtotime( "+1 month" ,$tz_dt);    
    }   
    echo ' x="'.esc_attr(join(",", $x)).'" y="'.esc_attr(join(",", $y)).'" '; 
}
function tz_fd_stats_get_weekly($tz_arr, $is_incoming = false)
{
    $str = '';
    $tz_dt = ($is_incoming ? strtotime("-11 weeks") : time());
    $x = []; $y = [];
    for ($tz_i=0;$tz_i<12;$tz_i++)
    {
        $x[] = gmdate("YW",$tz_dt);
        $tz_key = "x".gmdate("YW",$tz_dt);
        $y[] = (isset($tz_arr[$tz_key])?$tz_arr[$tz_key]:'0');         
        $tz_dt = strtotime("+1 week",$tz_dt);    
    }   
    echo ' x="'.esc_attr(join(",", $x)).'" y="'.esc_attr(join(",", $y)).'" ';
}
function tz_fd_stats_get_daily($tz_arr, $is_incoming = false)
{
    $str = '';
    $tz_dt = ($is_incoming ? strtotime("-29 days") : time());
    $x = []; $y = [];
    for ($tz_i=0;$tz_i<30;$tz_i++)
    {
        $x[] = gmdate("Ymd",$tz_dt);
        $tz_key = "x".gmdate("Ymd",$tz_dt); 
        $y[] = (isset($tz_arr[$tz_key])?$tz_arr[$tz_key]:'0'); 
        $tz_dt = strtotime("+1 day",$tz_dt);    
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
            <canvas id="chartMonthlyIncoming" label="<?php esc_html_e('Submissions','titan-zero-trades-front-desk'); ?>" <?php (tz_fd_stats_get_monthly($tz_monthly_incoming, true)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Incoming - Weekly Stats (lastest 12 weeks)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartWeeklyIncoming" label="<?php esc_html_e('Submissions','titan-zero-trades-front-desk'); ?>" <?php (tz_fd_stats_get_weekly($tz_weekly_incoming, true)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Daily Stats(next 30 days)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartDailyIncoming" label="<?php esc_html_e('Submissions','titan-zero-trades-front-desk'); ?>" <?php (tz_fd_stats_get_daily($tz_daily_incoming, true)); ?>  ></canvas>
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
            <canvas id="chartMonthly" label="<?php esc_html_e('Booked times','titan-zero-trades-front-desk'); ?>" <?php (tz_fd_stats_get_monthly($tz_monthly)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Weekly Stats (next 12 weeks)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartWeekly" label="<?php esc_html_e('Booked times','titan-zero-trades-front-desk'); ?>" <?php (tz_fd_stats_get_weekly($tz_weekly)); ?> ></canvas>
    	</div>
    </div> 
  </div>
  <div class="col4">  
    <div class="tzfd-graphs" >
    	<div class="tzfd-statssection-header">
    		<h3><?php esc_html_e('Daily Stats(next 30 days)','titan-zero-trades-front-desk'); ?></h3>
    	</div>
    	<div class="tzfd-statssection">
            <canvas id="chartDaily" label="<?php esc_html_e('Booked times','titan-zero-trades-front-desk'); ?>" <?php (tz_fd_stats_get_daily($tz_daily)); ?>  ></canvas>
    	</div>
    </div> 
  </div> 
</div>
