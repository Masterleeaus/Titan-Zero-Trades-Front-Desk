<?php
/** Read-only administrator recurrence/occupancy planner. GPL-2.0-only. */
if(!defined('ABSPATH')){exit;}
final class TZFD_Scheduling_Preview {
    public static function register(){add_action('admin_menu',array(__CLASS__,'menu'),20);}
    public static function menu(){
        add_submenu_page('',__('Scheduling Preview','titan-zero-trades-front-desk'),__('Scheduling Preview','titan-zero-trades-front-desk'),
            'manage_options','tzfd-scheduling-preview',array(__CLASS__,'page'));
    }
    private static function text($post,$tzfd_key,$default=''){
        if(!isset($post[$tzfd_key])){return $default;}
        if(!is_string($post[$tzfd_key]) || strlen($post[$tzfd_key])>8192){throw new InvalidArgumentException('invalid_input');}
        return sanitize_text_field(wp_unslash($post[$tzfd_key]));
    }
    public static function request($engine,$post){
        if(!current_user_can('manage_options')){throw new InvalidArgumentException('permission_denied');}
        if(!is_array($post) || !wp_verify_nonce(self::text($post,'tzfd_nonce'),'titan_zero_front_desk_schedule_preview')){throw new InvalidArgumentException('invalid_nonce');}
        $tzfd_form=TZFD_Scheduling::integer(self::text($post,'form_id'),1,PHP_INT_MAX);
        $index=TZFD_Scheduling::integer(self::text($post,'service_index'),0);
        $quantity=TZFD_Scheduling::integer(self::text($post,'quantity'),1);
        $tzfd_count=TZFD_Scheduling::integer(self::text($post,'count'),1,366);
        $interval=TZFD_Scheduling::integer(self::text($post,'interval'),1,52);
        $field=self::text($post,'field_name');
        if($tzfd_form===null || $index===null || $quantity===null || $tzfd_count===null || $interval===null || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D',$field)){throw new InvalidArgumentException('invalid_input');}
        $excluded=self::text($post,'exclude_dates');
        $rule=array('frequency'=>self::text($post,'frequency'),'count'=>$tzfd_count,'interval'=>$interval,
            'month_policy'=>self::text($post,'month_policy'),'exclude_dates'=>$excluded===''?array():preg_split('/[\s,;]+/',$excluded));
        $tzfd_date=self::text($post,'start_date');$time=self::text($post,'start_time');
        // Validate bounded recurrence before any database read.
        TZFD_Scheduling::expand_dates($tzfd_date,$rule);TZFD_Scheduling::slot($time,1);
        $original=$engine->getId();
        try{$engine->setId($tzfd_form);return $engine->preview_schedule($field,$index,$tzfd_date,$time,$quantity,$rule);}
        finally{$engine->setId($original);}
    }
    private static function input($name,$tzfd_label,$tzfd_value,$type='text',$min=null,$max=null){
        echo '<tr><th scope="row"><label for="tzfd-'.esc_attr($name).'">'.esc_html($tzfd_label).'</label></th><td><input class="regular-text" id="tzfd-'.esc_attr($name).'" name="'.esc_attr($name).'" type="'.esc_attr($type).'" value="'.esc_attr($tzfd_value).'"';
        if($min!==null){echo ' min="'.esc_attr($min).'"';}if($max!==null){echo ' max="'.esc_attr($max).'"';}
        echo ' required></td></tr>';
    }
    public static function page(){
        if(!current_user_can('manage_options')){wp_die(esc_html__('You cannot access this preview.','titan-zero-trades-front-desk'));return;}
        TZFD_Admin_Menu::render_settings_tabs('preview');
        global $tzfd_plugin;
        if(!($tzfd_plugin instanceof TZFD_Scheduling_Plugin)){wp_die(esc_html__('Scheduling engine is unavailable.','titan-zero-trades-front-desk'));return;}
        $values=array('form_id'=>'1','field_name'=>'fieldname1','service_index'=>'0','start_date'=>current_time('Y-m-d'),'start_time'=>'09:00',
            'quantity'=>'1','frequency'=>'weekly','count'=>'4','interval'=>'1','month_policy'=>'skip','exclude_dates'=>'');
        $result=null;$error='';
        if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']==='POST'){
            try{
                // The dedicated nonce is verified before any posted value is read; request() repeats the check and the capability test.
                if(!isset($_POST['tzfd_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tzfd_nonce'])),'titan_zero_front_desk_schedule_preview')){throw new InvalidArgumentException('invalid_nonce');}
                $result=self::request($tzfd_plugin,$_POST);
                foreach($values as$tzfd_key=>$default){$values[$tzfd_key]=self::text($_POST,$tzfd_key,$default);}
            }catch(InvalidArgumentException $exception){$error=__('The preview could not run. Check your inputs, refresh the page, or inspect the stored booking data.','titan-zero-trades-front-desk');}
        }
        echo '<div class="wrap"><h1>'.esc_html__('Scheduling Preview','titan-zero-trades-front-desk').'</h1>';
        echo '<p>'.esc_html__('Preview daily, weekly or monthly visits against calendar rules, recorded occupancy, capacity and service buffers. This does not create bookings, send messages or take payments.','titan-zero-trades-front-desk').'</p>';
        echo '<div class="notice notice-info inline"><p>'.esc_html__('A clear result is not a reservation. Opening hours, blackouts, lead times, timezone conversion and simultaneous booking requests require their own checks. Times use the existing site wall-clock convention.','titan-zero-trades-front-desk').'</p></div>';
        if($error!==''){echo '<div class="notice notice-error inline"><p>'.esc_html($error).'</p></div>';}
        echo '<form method="post">';wp_nonce_field('titan_zero_front_desk_schedule_preview','tzfd_nonce');
        echo '<table class="form-table"><tbody>';
        self::input('form_id',__('Booking form ID','titan-zero-trades-front-desk'),$values['form_id'],'number',1);
        self::input('field_name',__('Appointment field name','titan-zero-trades-front-desk'),$values['field_name']);
        self::input('service_index',__('Service index (first service is 0)','titan-zero-trades-front-desk'),$values['service_index'],'number',0);
        self::input('start_date',__('First date','titan-zero-trades-front-desk'),$values['start_date'],'date');
        self::input('start_time',__('Start time','titan-zero-trades-front-desk'),$values['start_time'],'time');
        self::input('quantity',__('Quantity','titan-zero-trades-front-desk'),$values['quantity'],'number',1,1000000);
        self::input('count',__('Occurrences before exclusions','titan-zero-trades-front-desk'),$values['count'],'number',1,366);
        self::input('interval',__('Repeat every N periods','titan-zero-trades-front-desk'),$values['interval'],'number',1,52);
        $selects=array('frequency'=>array(__('Frequency','titan-zero-trades-front-desk'),array('daily'=>__('Daily','titan-zero-trades-front-desk'),'weekly'=>__('Weekly','titan-zero-trades-front-desk'),'monthly'=>__('Monthly','titan-zero-trades-front-desk'))),
            'month_policy'=>array(__('Short-month handling','titan-zero-trades-front-desk'),array('skip'=>__('Skip months without that day','titan-zero-trades-front-desk'),'last_day'=>__('Use the last day, keeping the original anchor','titan-zero-trades-front-desk'))));
        foreach($selects as$name=>$tzfd_options){echo '<tr><th><label for="tzfd-'.esc_attr($name).'">'.esc_html($tzfd_options[0]).'</label></th><td><select id="tzfd-'.esc_attr($name).'" name="'.esc_attr($name).'">';
            foreach($tzfd_options[1]as$tzfd_key=>$tzfd_label){echo '<option value="'.esc_attr($tzfd_key).'"'.selected($values[$name],$tzfd_key,false).'>'.esc_html($tzfd_label).'</option>';}echo '</select></td></tr>';}
        echo '<tr><th><label for="tzfd-exclusions">'.esc_html__('Excluded dates','titan-zero-trades-front-desk').'</label></th><td><textarea id="tzfd-exclusions" name="exclude_dates" class="large-text" rows="3">'.esc_textarea($values['exclude_dates']).'</textarea><p class="description">'.esc_html__('Optional ISO dates (YYYY-MM-DD), separated by commas or spaces. Excluded dates do not add extra visits at the end.','titan-zero-trades-front-desk').'</p></td></tr></tbody></table>';
        submit_button(__('Preview only — do not create bookings','titan-zero-trades-front-desk'));echo '</form>';
        if(is_array($result)){
            echo '<h2>'.esc_html__('Preview result','titan-zero-trades-front-desk').'</h2><p>'.esc_html($result['available']?__('Calendar and recorded occupancy checks pass for this batch; nothing is reserved.','titan-zero-trades-front-desk'):__('Conflicts or data problems require attention.','titan-zero-trades-front-desk')).'</p>';
            $conflicts=array();$global=false;foreach($result['conflicts']as$conflict){if(isset($conflict['index'])){$conflicts[$conflict['index']]=true;}else{$global=true;}}
            echo '<table class="widefat striped"><thead><tr><th>'.esc_html__('Date','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Window','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Quantity','titan-zero-trades-front-desk').'</th><th>'.esc_html__('Calendar and occupancy','titan-zero-trades-front-desk').'</th></tr></thead><tbody>';
            foreach($result['appointments']as$index=>$appointment){echo '<tr><td>'.esc_html($appointment['date']).'</td><td>'.esc_html($appointment['slot']).'</td><td>'.esc_html($appointment['quant']).'</td><td>'.esc_html($global||isset($conflicts[$index])?__('Needs attention','titan-zero-trades-front-desk'):__('Clear — not reserved','titan-zero-trades-front-desk')).'</td></tr>';}
            echo '</tbody></table><details><summary>'.esc_html__('Structured preview (no customer details)','titan-zero-trades-front-desk').'</summary><textarea class="large-text code" rows="12" readonly aria-label="'.esc_attr__('Structured schedule preview','titan-zero-trades-front-desk').'">'.esc_textarea(wp_json_encode($result,JSON_PRETTY_PRINT)).'</textarea></details>';
        }
        echo '</div>';
    }
}

