<?php
/** Per-site resource management and authenticated read-only preview. GPL-2.0-only. */
// This module is loaded only through an initialized WordPress context.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__.'/class-tzfd-resource-availability.php';
final class TZFD_Resource_Admin {
    const OPTION='titan_zero_front_desk_resource_catalog_v1';
    private static $registered=false;

    public static function register() {
        if (self::$registered) return;
        self::$registered=true;
        add_action('admin_menu',array(__CLASS__,'menu'),20);
        add_action('admin_enqueue_scripts',array(__CLASS__,'enqueue'));
        add_action('rest_api_init',array(__CLASS__,'routes'));
    }

    /** Named permission callback for the REST routes below: administrators only. */
    public static function allowed() { return current_user_can('manage_options'); }

    /** Loads the readable script for the remove-confirmation prompts on the two resource screens. */
    public static function enqueue($hook) {
        if (!is_string($hook) || (false===strpos($hook,self::WORKERS_SLUG) && false===strpos($hook,self::LOCATIONS_SLUG))) return;
        wp_enqueue_script('titan-zero-trades-front-desk-sched-res-resource-admin',
            plugin_dir_url(dirname(__DIR__).'/titan-zero-trades-front-desk.php').'js/inline/sched-res-resource-admin.js',
            array(),'2.0.1',true);
    }

    /** Sanitizes every posted or JSON string at the boundary. Each field's validator in TZFD_Resource_Catalog / TZFD_Resource_Availability still enforces its format. */
    private static function clean_input($value) {
        if (is_array($value)) {
            $clean=array();
            foreach ($value as $key=>$item) { $clean[$key]=self::clean_input($item); }
            return $clean;
        }
        return is_string($value) ? sanitize_textarea_field($value) : $value;
    }

    public static function catalog() {
        try { return TZFD_Resource_Catalog::validate(get_option(self::OPTION,TZFD_Resource_Catalog::empty_catalog())); }
        catch (InvalidArgumentException $e) { return self::error('catalog_invalid','Saved resource catalogue is invalid; no preview or overwrite was performed.'); }
    }

    public static function revision($catalog) { return hash('sha256',wp_json_encode($catalog)); }

    private static function error($code,$tzfd_message,$status=400) { return new WP_Error('titan_zero_front_desk_'.$code,$tzfd_message,array('status'=>$status)); }

    /** Admin form handler. Validate everything before changing the single per-site option. */
    public static function apply($post) {
        if (!self::allowed()) return self::error('forbidden','Administrator access is required.',403);
        if (!is_array($post) || !isset($post['_wpnonce']) || !is_string($post['_wpnonce']) || !wp_verify_nonce($post['_wpnonce'],'titan_zero_front_desk_resources')) {
            return self::error('nonce','The form expired. Reload the resource screen.',403);
        }
        $catalog=self::catalog(); if (is_wp_error($catalog)) return $catalog;
        try {
            $op=isset($post['operation'])?$post['operation']:'';
            if ('preview'===$op) {
                if (!isset($post['reservations_json'])) throw new InvalidArgumentException('Supply a reservation snapshot; use [] explicitly when no reservations exist.');
                $tzfd_rows=self::json_input($post['reservations_json']);
                $request=array('service_id'=>isset($post['service_id'])?$post['service_id']:null,
                    'location_id'=>isset($post['location_id'])?$post['location_id']:'',
                    'postcode'=>isset($post['postcode'])?$post['postcode']:'',
                    'start'=>isset($post['start'])?$post['start']:null,'end'=>isset($post['end'])?$post['end']:null,
                    'quantity'=>self::form_integer(isset($post['quantity'])?$post['quantity']:null),
                    'required_resource_ids'=>self::tokens(isset($post['required_resource_ids'])?$post['required_resource_ids']:''));
                return array('notice'=>'Availability preview; no reservation was created.',
                    'preview'=>TZFD_Resource_Availability::evaluate($catalog,$request,$tzfd_rows));
            }
            if (!isset($post['revision']) || !is_string($post['revision']) || !hash_equals(self::revision($catalog),$post['revision'])) {
                return self::error('stale','Catalogue changed since this page was opened. Reload before saving.',409);
            }
            $next=$catalog;
            if ('import'===$op) {
                $next=TZFD_Resource_Catalog::decode(isset($post['catalog_json'])?$post['catalog_json']:null);
            } elseif ('location_save'===$op) {
                $row=array('id'=>self::row_id($post,$catalog['locations']),'name'=>isset($post['name'])?$post['name']:null,
                    'timezone'=>isset($post['timezone'])?$post['timezone']:null,'enabled'=>isset($post['enabled']) && '1'===$post['enabled'],
                    'postcodes'=>self::tokens(isset($post['postcodes'])?$post['postcodes']:''),
                    'address'=>isset($post['address'])?$post['address']:'','phone'=>isset($post['phone'])?$post['phone']:'');
                $next['locations']=self::upsert($catalog['locations'],$row);
            } elseif ('resource_save'===$op) {
                $tzfd_weekly=array();
                $days=TZFD_Resource_Catalog::list_value(isset($post['days'])?$post['days']:array(),7,'days');
                foreach($days as $day) $tzfd_weekly[]=array('day'=>self::form_integer($day),'start'=>isset($post['start'])?$post['start']:null,'end'=>isset($post['end'])?$post['end']:null);
                $row=array('id'=>self::row_id($post,$catalog['resources']),'name'=>isset($post['name'])?$post['name']:null,
                    'kind'=>isset($post['kind'])?$post['kind']:null,'location_id'=>isset($post['location_id'])?$post['location_id']:null,
                    'role'=>isset($post['role'])?$post['role']:'','phone'=>isset($post['phone'])?$post['phone']:'',
                    'email'=>isset($post['email'])?$post['email']:'','notes'=>isset($post['notes'])?$post['notes']:'',
                    'enabled'=>isset($post['enabled']) && '1'===$post['enabled'],
                    'capacity'=>self::form_integer(isset($post['capacity'])?$post['capacity']:null),
                    'buffer_before'=>self::form_integer(isset($post['buffer_before'])?$post['buffer_before']:'0'),
                    'buffer_after'=>self::form_integer(isset($post['buffer_after'])?$post['buffer_after']:'0'),
                    'service_ids'=>self::tokens(isset($post['service_ids'])?$post['service_ids']:''),
                    'weekly'=>$tzfd_weekly,'blackouts'=>self::tokens(isset($post['blackouts'])?$post['blackouts']:''));
                $next['resources']=self::upsert($catalog['resources'],$row);
            } elseif ('location_delete'===$op || 'resource_delete'===$op) {
                $id=TZFD_Resource_Catalog::identifier(isset($post['id'])?$post['id']:null);
                $tzfd_key='location_delete'===$op?'locations':'resources';
                $next[$tzfd_key]=array_values(array_filter($catalog[$tzfd_key],function($row)use($id){return $row['id']!==$id;}));
            } else { throw new InvalidArgumentException('Unknown resource operation.'); }
            $next=TZFD_Resource_Catalog::validate($next);
            // This detects stale sequential edits, not an atomic multi-writer transaction.
            if ($next!==$catalog && !update_option(self::OPTION,$next,false)) return self::error('save','Catalogue was not saved. Reload and retry.',500);
            return array('notice'=>'Resource catalogue saved.','preview'=>null);
        } catch (InvalidArgumentException $e) { return self::error('invalid',$e->getMessage()); }
    }

    /** The ID of a saved row: the posted ID when editing, otherwise one made from the name (unique in the list). */
    private static function row_id($post,$tzfd_rows) {
        $posted=isset($post['id']) && is_string($post['id']) ? trim($post['id']) : '';
        if ($posted!=='') return $posted;
        $name=isset($post['name']) && is_string($post['name']) ? $post['name'] : '';
        $base=trim(preg_replace('/[^a-z0-9]+/','-',strtolower(trim($name))),'-');
        $base=substr($base===''?'item':$base,0,50);
        $taken=array_map(function($row){return $row['id'];},$tzfd_rows);
        $id=$base; $n=2;
        while (in_array($id,$taken,true)) { $id=$base.'-'.$n; $n++; }
        return $id;
    }

    private static function upsert($tzfd_rows,$row) {
        $id=TZFD_Resource_Catalog::identifier($row['id']);
        foreach($tzfd_rows as $index=>$old) if ($old['id']===$id) { $tzfd_rows[$index]=$row;return $tzfd_rows; }
        $tzfd_rows[]=$row;return $tzfd_rows;
    }

    private static function tokens($tzfd_value) {
        if (!is_string($tzfd_value) || strlen($tzfd_value)>16384) throw new InvalidArgumentException('Invalid list field.');
        $tzfd_value=trim($tzfd_value);return ''===$tzfd_value?array():array_values(array_filter(array_map('trim',preg_split('/[,\r\n]+/',$tzfd_value)),function($s){return ''!==$s;}));
    }

    private static function form_integer($tzfd_value) {
        if (is_int($tzfd_value)) return $tzfd_value;
        if (!is_string($tzfd_value) || !preg_match('/^[0-9]{1,5}$/D',$tzfd_value)) throw new InvalidArgumentException('Use a whole number for numeric fields.');
        return (int)$tzfd_value;
    }

    private static function json_input($tzfd_value) {
        if (!is_string($tzfd_value) || strlen($tzfd_value)>TZFD_Resource_Catalog::MAX_BYTES) throw new InvalidArgumentException('JSON input exceeds 256 KiB.');
        $decoded=json_decode($tzfd_value,true,16);
        if (JSON_ERROR_NONE!==json_last_error()) throw new InvalidArgumentException('Invalid JSON input.');
        return $decoded;
    }

    public static function routes() {
        register_rest_route('tzfd/v1','/resources/catalog',array('methods'=>'GET',
            'callback'=>array(__CLASS__,'rest_catalog'),'permission_callback'=>array(__CLASS__,'allowed')));
        register_rest_route('tzfd/v1','/resources/preview',array('methods'=>'POST',
            'callback'=>array(__CLASS__,'rest_preview'),'permission_callback'=>array(__CLASS__,'allowed')));
    }

    private static function response($tzfd_value) {
        $response=new WP_REST_Response($tzfd_value,200);$response->header('Cache-Control','private, no-store');return $response;
    }

    public static function rest_catalog($request) {
        if (!self::allowed()) return self::error('forbidden','Administrator access is required.',403);
        $catalog=self::catalog();return is_wp_error($catalog)?$catalog:self::response($catalog);
    }

    public static function rest_preview($request) {
        if (!self::allowed()) return self::error('forbidden','Administrator access is required.',403);
        $catalog=self::catalog();if(is_wp_error($catalog))return $catalog;
        try {
            if (strlen($request->get_body())>TZFD_Resource_Catalog::MAX_BYTES) throw new InvalidArgumentException('Preview input exceeds 256 KiB.');
            $input=self::clean_input($request->get_json_params());
            if(!is_array($input) || !isset($input['request']))throw new InvalidArgumentException('A request object is required.');
            if(!isset($input['reservations']))throw new InvalidArgumentException('Supply a reservation snapshot; use [] explicitly when no reservations exist.');
            return self::response(TZFD_Resource_Availability::evaluate($catalog,$input['request'],$input['reservations']));
        } catch(InvalidArgumentException $e){return self::error('invalid',$e->getMessage());}
    }

    const WORKERS_SLUG   = 'tzfd-resources';
    const LOCATIONS_SLUG = 'tzfd-locations';

    /** Two menu pages: Workers (people, equipment and rooms) and Locations (where they are based). */
    public static function menu() {
        add_submenu_page('tzfd',__('Workers','titan-zero-trades-front-desk'),__('Workers','titan-zero-trades-front-desk'),'manage_options',self::WORKERS_SLUG,array(__CLASS__,'page'));
        add_submenu_page('tzfd',__('Locations','titan-zero-trades-front-desk'),__('Locations','titan-zero-trades-front-desk'),'manage_options',self::LOCATIONS_SLUG,array(__CLASS__,'page_locations'));
    }

    private static function form_start($operation,$catalog) {
        echo '<form method="post" class="tzfd-form">';
        wp_nonce_field('titan_zero_front_desk_resources');
        echo '<input type="hidden" name="operation" value="'.esc_attr($operation).'">';
        echo '<input type="hidden" name="revision" value="'.esc_attr(self::revision($catalog)).'">';
    }

    private static function url_for($slug,$args=array()) {
        return add_query_arg(array_merge(array('page'=>$slug),$args),admin_url('admin.php'));
    }

    /** Link back to the page the form is on, so Save and Cancel stay on Workers or Locations. */
    private static function page_url($args=array()) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
        $page=isset($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):self::WORKERS_SLUG;
        if(!in_array($page,array(self::WORKERS_SLUG,self::LOCATIONS_SLUG),true)) $page=self::WORKERS_SLUG;
        return self::url_for($page,$args);
    }

    private static function day_names() {
        return array(1=>__('Mon','titan-zero-trades-front-desk'),2=>__('Tue','titan-zero-trades-front-desk'),3=>__('Wed','titan-zero-trades-front-desk'),
            4=>__('Thu','titan-zero-trades-front-desk'),5=>__('Fri','titan-zero-trades-front-desk'),6=>__('Sat','titan-zero-trades-front-desk'),7=>__('Sun','titan-zero-trades-front-desk'));
    }

    private static function kind_labels() {
        return array('provider'=>__('Staff member','titan-zero-trades-front-desk'),'equipment'=>__('Equipment','titan-zero-trades-front-desk'),'room'=>__('Room or space','titan-zero-trades-front-desk'));
    }

    private static function site_timezone() {
        $zone=function_exists('wp_timezone_string') ? (string) wp_timezone_string() : 'UTC';
        return in_array($zone,DateTimeZone::listIdentifiers(),true) ? $zone : 'UTC';
    }

    private static function find($rows,$id) {
        foreach($rows as $row) { if ($row['id']===$id) return $row; }
        return null;
    }

    private static function requested($key) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: chooses which record the form shows.
        return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
    }

    /** One text field in the form grid. $extra maps attribute names to values (true for a bare attribute such as required). */
    private static function input($label,$name,$value='',$type='text',$full=false,$help='',$extra=array()) {
        $id='tzfd_res_'.$name;
        echo '<div'.($full?' class="tzfd-full"':'').'><label for="'.esc_attr($id).'">'.esc_html($label).'</label>';
        echo '<input id="'.esc_attr($id).'" class="regular-text" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'"';
        foreach((array)$extra as $attr=>$attr_value) { echo ' '.esc_attr((string)$attr).(true===$attr_value?'':'="'.esc_attr((string)$attr_value).'"'); }
        echo '>';
        if ($help!=='') echo '<p class="description">'.esc_html($help).'</p>';
        echo '</div>';
    }

    private static function area($label,$name,$value='',$full=true,$help='',$rows=3) {
        $id='tzfd_res_'.$name;
        echo '<div'.($full?' class="tzfd-full"':'').'><label for="'.esc_attr($id).'">'.esc_html($label).'</label>';
        echo '<textarea id="'.esc_attr($id).'" class="large-text" name="'.esc_attr($name).'" rows="'.(int)$rows.'">'.esc_textarea($value).'</textarea>';
        if ($help!=='') echo '<p class="description">'.esc_html($help).'</p>';
        echo '</div>';
    }

    private static function select($label,$name,$options,$selected,$full=false,$help='') {
        $id='tzfd_res_'.$name;
        echo '<div'.($full?' class="tzfd-full"':'').'><label for="'.esc_attr($id).'">'.esc_html($label).'</label>';
        echo '<select id="'.esc_attr($id).'" name="'.esc_attr($name).'">';
        foreach($options as $value=>$text) echo '<option value="'.esc_attr((string)$value).'"'.selected((string)$selected,(string)$value,false).'>'.esc_html($text).'</option>';
        echo '</select>';
        if ($help!=='') echo '<p class="description">'.esc_html($help).'</p>';
        echo '</div>';
    }

    private static function check($label,$name,$checked,$full=true) {
        echo '<div'.($full?' class="tzfd-full"':'').'><label class="tzfd-check"><input type="checkbox" name="'.esc_attr($name).'" value="1"'.($checked?' checked':'').'> '.esc_html($label).'</label></div>';
    }

    private static function days_field($selected) {
        echo '<fieldset class="tzfd-full tzfd-days"><legend>'.esc_html__('Working days','titan-zero-trades-front-desk').'</legend>';
        foreach(self::day_names() as $day=>$label) {
            echo '<label class="tzfd-check"><input type="checkbox" name="days[]" value="'.(int)$day.'"'.(in_array($day,$selected,true)?' checked':'').'> '.esc_html($label).'</label>';
        }
        echo '<p class="description">'.esc_html__('Leave every day unticked to take this worker out of bookings.','titan-zero-trades-front-desk').'</p></fieldset>';
    }

    private static function submit($label,$cancel='') {
        echo '<div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">'.esc_html($label).'</button>';
        if ($cancel!=='') echo '<a class="tzfd-btn tzfd-btn-secondary" href="'.esc_url($cancel).'">'.esc_html__('Cancel','titan-zero-trades-front-desk').'</a>';
        echo '</div></form>';
    }

    private static function remove_form($operation,$catalog,$id,$question) {
        echo '<form method="post" class="tzfd-inline-form" data-tzfd-confirm="'.esc_attr($question).'">';
        wp_nonce_field('titan_zero_front_desk_resources');
        echo '<input type="hidden" name="operation" value="'.esc_attr($operation).'">';
        echo '<input type="hidden" name="revision" value="'.esc_attr(self::revision($catalog)).'">';
        echo '<input type="hidden" name="id" value="'.esc_attr($id).'">';
        echo '<button type="submit" class="tzfd-btn tzfd-btn-danger">'.esc_html__('Remove','titan-zero-trades-front-desk').'</button></form>';
    }

    private static function state_badge($on) {
        return $on ? '<span class="tzfd-badge tzfd-badge-confirmed">'.esc_html__('Enabled','titan-zero-trades-front-desk').'</span>'
                   : '<span class="tzfd-badge tzfd-badge-other">'.esc_html__('Disabled','titan-zero-trades-front-desk').'</span>';
    }

    /** The only markup state_badge() produces: a span with a class attribute. */
    private static function badge_allowed() {
        return array('span'=>array('class'=>true));
    }

    /** Opening hours as text, for example "Mon, Tue, Wed 08:00–17:00". */
    private static function hours_text($weekly) {
        if (empty($weekly)) return __('No hours set','titan-zero-trades-front-desk');
        $names=self::day_names(); $groups=array();
        foreach($weekly as $w) { $groups[$w['start'].'–'.$w['end']][]=(int)$w['day']; }
        $parts=array();
        foreach($groups as $time=>$days) {
            sort($days);
            $parts[]=implode(', ',array_map(function($d)use($names){return $names[$d];},$days)).' '.$time;
        }
        return implode('; ',$parts);
    }

    /** True when the stored hours have more than one opening window (set by JSON import). */
    private static function is_split($weekly) {
        $keys=array();
        foreach($weekly as $w) { $keys[$w['start'].'|'.$w['end']]=true; }
        return count($keys)>1;
    }

    private static function location_form($catalog,$row) {
        $zones=array(); foreach(DateTimeZone::listIdentifiers() as $zone) { $zones[$zone]=$zone; }
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">'.esc_html($row ? __('Edit location','titan-zero-trades-front-desk') : __('Add a location','titan-zero-trades-front-desk')).'</h2>';
        self::form_start('location_save',$catalog);
        echo '<div class="tzfd-form-grid">';
        if ($row) {
            echo '<input type="hidden" name="id" value="'.esc_attr($row['id']).'"><div class="tzfd-full tzfd-muted">'.esc_html__('ID','titan-zero-trades-front-desk').': '.esc_html($row['id']).'</div>';
        } else {
            self::input(__('ID (optional)','titan-zero-trades-front-desk'),'id','','text',false,__('Leave blank and one is made from the name.','titan-zero-trades-front-desk'));
        }
        self::input(__('Name','titan-zero-trades-front-desk'),'name',$row?$row['name']:'','text',false,'',array('required'=>true));
        self::area(__('Address','titan-zero-trades-front-desk'),'address',$row?$row['address']:'',true,'',3);
        self::input(__('Phone (optional)','titan-zero-trades-front-desk'),'phone',$row?$row['phone']:'','tel');
        self::select(__('Timezone','titan-zero-trades-front-desk'),'timezone',$zones,$row?$row['timezone']:self::site_timezone());
        self::area(__('Postcodes covered (optional)','titan-zero-trades-front-desk'),'postcodes',$row?implode("\n",$row['postcodes']):'',true,__('Exact postcodes, one per line. Leave empty to cover any postcode.','titan-zero-trades-front-desk'),3);
        echo '</div><div class="tzfd-form-grid">';
        self::check(__('Location is open for bookings','titan-zero-trades-front-desk'),'enabled',$row?$row['enabled']:true);
        echo '</div>';
        self::submit(__('Save location','titan-zero-trades-front-desk'),self::page_url());
        echo '</div>';
    }

    private static function worker_form($catalog,$row) {
        $days=array(); $start='08:00'; $end='17:00'; $services=''; $blackouts='';
        if ($row) {
            foreach($row['weekly'] as $w) { $days[]=(int)$w['day']; }
            if (!empty($row['weekly'])) { $start=$row['weekly'][0]['start']; $end=$row['weekly'][0]['end']; }
            $services=implode("\n",$row['service_ids']);
            $blackouts=implode("\n",$row['blackouts']);
        }
        $locations=array(); foreach($catalog['locations'] as $loc) { $locations[$loc['id']]=$loc['name']; }
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">'.esc_html($row ? __('Edit worker','titan-zero-trades-front-desk') : __('Add a worker, equipment or room','titan-zero-trades-front-desk')).'</h2>';
        if (empty($locations)) {
            echo '<p>'.esc_html__('Add a location first. Each worker, piece of equipment or room works at one location.','titan-zero-trades-front-desk').'</p>';
            echo '<p><a class="tzfd-btn tzfd-btn-primary" href="'.esc_url(self::url_for(self::LOCATIONS_SLUG,array('add'=>'location'))).'">'.esc_html__('Add a location','titan-zero-trades-front-desk').'</a></p></div>';
            return;
        }
        if ($row && self::is_split($row['weekly'])) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html__('This worker has split hours. Saving here replaces them with one opening window. Split hours are edited in the advanced import.','titan-zero-trades-front-desk').'</p></div>';
        }
        self::form_start('resource_save',$catalog);
        echo '<h3 class="tzfd-section-title">'.esc_html__('Details','titan-zero-trades-front-desk').'</h3><div class="tzfd-form-grid">';
        if ($row) {
            echo '<input type="hidden" name="id" value="'.esc_attr($row['id']).'"><div class="tzfd-full tzfd-muted">'.esc_html__('ID','titan-zero-trades-front-desk').': '.esc_html($row['id']).'</div>';
        } else {
            self::input(__('ID (optional)','titan-zero-trades-front-desk'),'id','','text',false,__('Leave blank and one is made from the name.','titan-zero-trades-front-desk'));
        }
        self::input(__('Name','titan-zero-trades-front-desk'),'name',$row?$row['name']:'','text',false,'',array('required'=>true));
        self::input(__('Role (optional)','titan-zero-trades-front-desk'),'role',$row?$row['role']:'','text',false,__('For example: Stylist, Technician, Room A.','titan-zero-trades-front-desk'));
        self::select(__('Type','titan-zero-trades-front-desk'),'kind',self::kind_labels(),$row?$row['kind']:'provider');
        self::select(__('Works at','titan-zero-trades-front-desk'),'location_id',$locations,$row?$row['location_id']:(string)array_key_first($locations));
        self::input(__('Phone (optional)','titan-zero-trades-front-desk'),'phone',$row?$row['phone']:'','tel');
        self::input(__('Email (optional)','titan-zero-trades-front-desk'),'email',$row?$row['email']:'','email');
        self::area(__('Notes (for staff, not shown to customers)','titan-zero-trades-front-desk'),'notes',$row?$row['notes']:'',true,'',3);
        echo '</div>';

        echo '<h3 class="tzfd-section-title">'.esc_html__('Working hours','titan-zero-trades-front-desk').'</h3><div class="tzfd-form-grid">';
        self::days_field($days);
        self::input(__('Starts (HH:MM)','titan-zero-trades-front-desk'),'start',$start,'text',false,'',array('placeholder'=>'08:00','pattern'=>'([01][0-9]|2[0-3]):[0-5][0-9]'));
        self::input(__('Finishes (HH:MM, or 24:00 for midnight)','titan-zero-trades-front-desk'),'end',$end,'text',false,'',array('placeholder'=>'17:00','pattern'=>'([01][0-9]|2[0-3]):[0-5][0-9]|24:00'));
        self::area(__('Days off (YYYY-MM-DD, one per line)','titan-zero-trades-front-desk'),'blackouts',$blackouts,true,'',3);
        echo '</div>';

        echo '<h3 class="tzfd-section-title">'.esc_html__('Bookings','titan-zero-trades-front-desk').'</h3><div class="tzfd-form-grid">';
        self::input(__('Bookings at the same time','titan-zero-trades-front-desk'),'capacity',$row?(string)$row['capacity']:'1','number');
        self::input(__('Time needed before each booking (minutes)','titan-zero-trades-front-desk'),'buffer_before',$row?(string)$row['buffer_before']:'0','number');
        self::input(__('Time needed after each booking (minutes)','titan-zero-trades-front-desk'),'buffer_after',$row?(string)$row['buffer_after']:'0','number');
        self::area(__('Services this worker offers (one per line; empty means all)','titan-zero-trades-front-desk'),'service_ids',$services,true,'',3);
        self::check(__('Available for bookings','titan-zero-trades-front-desk'),'enabled',$row?$row['enabled']:true);
        echo '</div>';
        self::submit(__('Save worker','titan-zero-trades-front-desk'),self::page_url());
        echo '</div>';
    }

    private static function locations_list($catalog) {
        echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2>'.esc_html__('Locations','titan-zero-trades-front-desk').'</h2></div>';
        if (empty($catalog['locations'])) {
            echo '<p class="tzfd-muted">'.esc_html__('No locations yet. Add the place where your workers, equipment or rooms are based.','titan-zero-trades-front-desk').'</p></div>';
            return;
        }
        echo '<div class="tzfd-table-wrap"><table class="widefat striped tzfd-table"><thead><tr>';
        echo '<th>'.esc_html__('Name','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Address','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Phone','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Timezone','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Workers','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Status','titan-zero-trades-front-desk').'</th><th></th>';
        echo '</tr></thead><tbody>';
        foreach($catalog['locations'] as $loc) {
            $count=0; foreach($catalog['resources'] as $r) { if ($r['location_id']===$loc['id']) $count++; }
            echo '<tr><td><strong>'.esc_html($loc['name']).'</strong></td>';
            echo '<td>'.nl2br(esc_html($loc['address'])).'</td><td>'.esc_html($loc['phone']).'</td><td>'.esc_html($loc['timezone']).'</td>';
            echo '<td>'.(int)$count.'</td><td>'.wp_kses(self::state_badge($loc['enabled']),self::badge_allowed()).'</td>';
            echo '<td class="tzfd-row-actions"><a class="tzfd-btn tzfd-btn-secondary" href="'.esc_url(self::page_url(array('location'=>$loc['id']))).'">'.esc_html__('Edit','titan-zero-trades-front-desk').'</a> ';
            self::remove_form('location_delete',$catalog,$loc['id'],__('Remove this location? Its workers must be removed first.','titan-zero-trades-front-desk'));
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** Team members (staff) or equipment and rooms, depending on the tab. */
    private static function workers_list($catalog, $view='team') {
        $team = 'equipment' !== $view;
        $rows = array_values(array_filter($catalog['resources'], function ($r) use ($team) { return $team ? 'provider' === $r['kind'] : 'provider' !== $r['kind']; }));
        echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2>'.($team ? esc_html__('Team members','titan-zero-trades-front-desk') : esc_html__('Equipment and rooms','titan-zero-trades-front-desk')).'</h2></div>';
        if (empty($rows)) {
            TZFD_Brand::empty_state('workers',$team ? __('No team members yet. Add a worker to get started.','titan-zero-trades-front-desk') : __('No equipment or rooms yet. Add one to get started.','titan-zero-trades-front-desk'));
            echo '</div>';
            return;
        }
        $locations=array(); foreach($catalog['locations'] as $loc) { $locations[$loc['id']]=$loc['name']; }
        $kinds=self::kind_labels();
        echo '<div class="tzfd-table-wrap"><table class="widefat striped tzfd-table"><thead><tr>';
        echo '<th>'.esc_html__('Name','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Type','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Works at','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Contact','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Hours','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Status','titan-zero-trades-front-desk').'</th><th></th>';
        echo '</tr></thead><tbody>';
        foreach($rows as $r) {
            echo '<tr><td><strong>'.esc_html($r['name']).'</strong>';
            if ($r['role']!=='') echo '<br><span class="tzfd-muted">'.esc_html($r['role']).'</span>';
            echo '</td><td>'.esc_html($kinds[$r['kind']] ?? $r['kind']).'</td>';
            echo '<td>'.esc_html($locations[$r['location_id']] ?? $r['location_id']).'</td>';
            echo '<td>'.esc_html(implode(' · ',array_filter(array($r['phone'],$r['email']),'strlen'))).'</td>';
            echo '<td>'.esc_html(self::hours_text($r['weekly'])).'</td><td>'.wp_kses(self::state_badge($r['enabled']),self::badge_allowed()).'</td>';
            echo '<td class="tzfd-row-actions"><a class="tzfd-btn tzfd-btn-secondary" href="'.esc_url(self::page_url(array('worker'=>$r['id']))).'">'.esc_html__('Edit','titan-zero-trades-front-desk').'</a> ';
            self::remove_form('resource_delete',$catalog,$r['id'],__('Remove this worker, equipment or room?','titan-zero-trades-front-desk'));
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function preview_result($out) {
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">'.esc_html__('Preview result','titan-zero-trades-front-desk').'</h2>';
        echo '<p class="tzfd-muted">'.esc_html__('Only the reservations you pasted were checked. Nothing was booked or reserved.','titan-zero-trades-front-desk').'</p>';
        if (null!==$out['preview']['selection_available']) echo '<p><strong>'.esc_html__('Required resource selection:','titan-zero-trades-front-desk').' '.esc_html($out['preview']['selection_available']?__('available','titan-zero-trades-front-desk'):__('unavailable','titan-zero-trades-front-desk')).'</strong></p>';
        echo '<ul>';
        foreach($out['preview']['resources'] as $row) {
            echo '<li>'.esc_html($row['name'].': '.($row['available']?'available':'unavailable').' — '.implode(', ',$row['reasons']).' (remaining capacity '.$row['remaining_capacity'].')').'</li>';
        }
        echo '</ul></div>';
    }

    private static function preview_card($catalog) {
        $locations=array(''=>__('All locations','titan-zero-trades-front-desk'));
        foreach($catalog['locations'] as $loc) { $locations[$loc['id']]=$loc['name']; }
        echo '<div class="tzfd-card"><details><summary class="tzfd-section-title">'.esc_html__('Availability preview','titan-zero-trades-front-desk').'</summary>';
        echo '<p class="tzfd-muted">'.esc_html__('Checks which workers could take a booking. Existing bookings are not included unless you paste their reservations below. This is a check only, not a booking or a guarantee.','titan-zero-trades-front-desk').'</p>';
        self::form_start('preview',$catalog);
        echo '<div class="tzfd-form-grid">';
        self::input(__('Service ID','titan-zero-trades-front-desk'),'service_id');
        self::select(__('Location','titan-zero-trades-front-desk'),'location_id',$locations,'');
        self::input(__('Postcode','titan-zero-trades-front-desk'),'postcode');
        self::input(__('Start (ISO timestamp with Z or an offset)','titan-zero-trades-front-desk'),'start');
        self::input(__('End (ISO timestamp with Z or an offset)','titan-zero-trades-front-desk'),'end');
        self::input(__('Units needed','titan-zero-trades-front-desk'),'quantity','1','number');
        self::area(__('Required worker IDs (comma or line separated; empty checks all)','titan-zero-trades-front-desk'),'required_resource_ids','',true,'',2);
        self::area(__('Existing reservations (JSON; [] means none)','titan-zero-trades-front-desk'),'reservations_json','[]',true,'',4);
        echo '</div>';
        self::submit(__('Preview availability','titan-zero-trades-front-desk'));
        echo '</details></div>';
    }

    private static function advanced_card($catalog) {
        echo '<div class="tzfd-card"><details><summary class="tzfd-section-title">'.esc_html__('Backup and import (advanced)','titan-zero-trades-front-desk').'</summary>';
        echo '<p class="tzfd-muted">'.esc_html__('Copy this JSON as a backup. An import replaces everything here, after it is checked. Do not paste customer details, passwords or private notes.','titan-zero-trades-front-desk').'</p>';
        self::form_start('import',$catalog);
        echo '<div class="tzfd-form-grid">';
        self::area(__('Locations and workers (JSON)','titan-zero-trades-front-desk'),'catalog_json',wp_json_encode($catalog,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),true,'',16);
        echo '</div>';
        self::submit(__('Check and replace everything','titan-zero-trades-front-desk'));
        echo '</details></div>';
    }

    /** Workers page: people, equipment and rooms, the availability preview and the advanced import. */
    public static function page() {
        self::render_page('workers');
    }

    /** Locations page: the places where workers, equipment and rooms are based. */
    public static function page_locations() {
        self::render_page('locations');
    }

    /** Tabs for the Workers and locations screens: Team, Locations, and Equipment and rooms. */
    private static function tabs_bar($view) {
        $tabs = array(
            'team'      => array(__('Team','titan-zero-trades-front-desk'), self::url_for(self::WORKERS_SLUG)),
            'locations' => array(__('Locations','titan-zero-trades-front-desk'), self::url_for(self::LOCATIONS_SLUG)),
            'equipment' => array(__('Equipment & rooms','titan-zero-trades-front-desk'), self::url_for(self::WORKERS_SLUG, array('view'=>'equipment'))),
        );
        echo '<nav class="tzfd-tabs" aria-label="'.esc_attr__('Workers and locations','titan-zero-trades-front-desk').'">';
        foreach ($tabs as $key => $tab) {
            echo '<a href="'.esc_url($tab[1]).'"'.($key===$view?' class="is-active" aria-current="page"':'').'>'.esc_html($tab[0]).'</a>';
        }
        echo '</nav>';
    }

    /** Three tiles above the tables: team members, locations, and equipment and rooms. */
    private static function tiles($catalog) {
        $team = 0; $team_on = 0; $equip = 0; $locs_on = 0;
        foreach ((array) $catalog['resources'] as $r) {
            if ('provider' === $r['kind']) { $team++; if (!empty($r['enabled'])) $team_on++; } else { $equip++; }
        }
        foreach ((array) $catalog['locations'] as $loc) { if (!empty($loc['enabled'])) $locs_on++; }
        echo '<div class="tzfd-stats" role="list">';
        TZFD_Admin_Layout::stat((string) $team, __('Team members','titan-zero-trades-front-desk'), sprintf(/* translators: %d: number of enabled team members. */ __('%d active','titan-zero-trades-front-desk'), $team_on), 'red');
        TZFD_Admin_Layout::stat((string) count((array) $catalog['locations']), __('Locations','titan-zero-trades-front-desk'), sprintf(/* translators: %d: number of enabled locations. */ __('%d active','titan-zero-trades-front-desk'), $locs_on), 'blue');
        TZFD_Admin_Layout::stat((string) $equip, __('Resources','titan-zero-trades-front-desk'), __('rooms and equipment','titan-zero-trades-front-desk'), 'green');
        echo '</div>';
    }

    private static function render_page($which) {
        if (!self::allowed()) { wp_die('Administrator access is required.','',array('response'=>403));return; }
        $is_locations = 'locations'===$which;
        $out=null;
        if (isset($_SERVER['REQUEST_METHOD']) && 'POST'===$_SERVER['REQUEST_METHOD']) {
            // Same check and same message as apply(), made before any posted value is read.
            $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce,'titan_zero_front_desk_resources')) {
                $out=self::error('nonce','The form expired. Reload the resource screen.',403);
            } else {
                $out=self::apply(self::clean_input(wp_unslash($_POST)));
            }
        }
        $view = $is_locations ? 'locations' : ('equipment'===self::requested('view') ? 'equipment' : 'team');
        $lead = __('Manage the people, equipment, rooms and places that bookings use. Saving here does not change existing bookings.','titan-zero-trades-front-desk');
        $action = $is_locations
            ? '<a class="tzfd-btn tzfd-btn-primary" href="'.esc_url(self::page_url(array('add'=>'location'))).'">+ '.esc_html__('Add location','titan-zero-trades-front-desk').'</a>'
            : '<a class="tzfd-btn tzfd-btn-primary" href="'.esc_url(self::page_url(array('add'=>'worker'))).'">+ '.esc_html__('Add team member','titan-zero-trades-front-desk').'</a>';
        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-'.($is_locations?'locations':'workers').'">';
        TZFD_Admin_Layout::header(__('Workers & locations','titan-zero-trades-front-desk'), $lead, $action);
        if($out)echo '<div class="notice '.(is_wp_error($out)?'notice-error':'notice-success').' inline"><p>'.esc_html(is_wp_error($out)?$out->get_error_message():$out['notice']).'</p></div>';
        $catalog=self::catalog();
        if(is_wp_error($catalog)){echo '<div class="notice notice-error"><p>'.esc_html($catalog->get_error_message()).'</p></div></div>';return;}
        self::tabs_bar($view);
        self::tiles($catalog);
        if(!$is_locations && $out && !is_wp_error($out) && !empty($out['preview'])) self::preview_result($out);

        if ($is_locations) {
            $edit_location=self::requested('location');
            if ('location'===self::requested('add') || ''!==$edit_location) self::location_form($catalog,''!==$edit_location?self::find($catalog['locations'],$edit_location):null);
            self::locations_list($catalog);
        } else {
            $edit_worker=self::requested('worker');
            if ('worker'===self::requested('add') || ''!==$edit_worker) self::worker_form($catalog,''!==$edit_worker?self::find($catalog['resources'],$edit_worker):null);
            self::workers_list($catalog, $view);
            self::preview_card($catalog);
            self::advanced_card($catalog);
        }
        echo '</div>';
    }
}
