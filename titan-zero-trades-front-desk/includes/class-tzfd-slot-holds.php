<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Company-scoped, non-destructive slot holds. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/class-tzfd-resource-availability.php';
require_once __DIR__.'/class-tzfd-tables.php';
final class TZFD_Slot_Holds {
    const TABLE = 'tz_slot_holds';
    const MAX_TTL = 900;
    /** Explicit, authorised schema installation for an administrator request. */
    public static function install() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) return new WP_Error('tzfd_forbidden',__( 'Administrator permission required', 'titan-zero-trades-front-desk' ));
        return self::create_schema();
    }

    /**
     * Creates or upgrades the table with dbDelta. Idempotent. No capability check: the caller must be a
     * trusted server path (plugin activation runs with or without a logged-in user). Use install() from requests.
     */
    public static function create_schema() {
        global $wpdb;
        $table=TZFD_Tables::checked_name(self::TABLE);
        if (is_wp_error($table)) return $table;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        // No DEFAULT '' on any column: dbDelta matches a DEFAULT '' in the statement and emits SET DEFAULT on the AUTO_INCREMENT id,
        // which MariaDB rejects on every run after the first (#186).
        $sql="CREATE TABLE $table (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, company_id varchar(80) NOT NULL, token_hash char(64) NOT NULL, state varchar(16) NOT NULL, start_utc datetime NOT NULL, end_utc datetime NOT NULL, expires_at datetime NOT NULL, payload longtext NOT NULL, booking_id varchar(80) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY company_token (company_id,token_hash), KEY company_state_time (company_id,state,start_utc,end_utc)) ".$wpdb->get_charset_collate().';';
        dbDelta($sql);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return false;
        $columns=$wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ),ARRAY_A );
        $indexes=$wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ),ARRAY_A );
        return self::schema_ready($columns,$indexes);
    }
    /** Verify required columns and the exact company-scoped uniqueness guarantee after dbDelta. */
    public static function schema_ready($columns,$indexes) {
        if(!is_array($columns) || !is_array($indexes)) return false;
        $names=[];
        foreach($columns as $column) {
            if(!is_array($column) || !is_string($column['Field']??null)) return false;
            $names[$column['Field']]=true;
        }
        foreach(['id','company_id','token_hash','state','start_utc','end_utc','expires_at','payload','booking_id','created_at'] as $required) {
            if(!isset($names[$required])) return false;
        }
        $keys=[];
        foreach($indexes as $index) {
            if(!is_array($index) || !is_string($index['Key_name']??null) || !is_string($index['Column_name']??null) ||
               !isset($index['Seq_in_index']) || !is_numeric($index['Seq_in_index']) ||
               !isset($index['Non_unique']) || !in_array((string)$index['Non_unique'],['0','1'],true)) return false;
            $key=$index['Key_name']; $seq=(int)$index['Seq_in_index'];
            if($seq<1 || $seq>20 || isset($keys[$key]['columns'][$seq])) return false;
            if(isset($keys[$key]) && $keys[$key]['unique']!==((string)$index['Non_unique']==='0')) return false;
            $keys[$key]['unique']=((string)$index['Non_unique']==='0');
            $keys[$key]['columns'][$seq]=$index['Column_name'];
        }
        foreach($keys as $key) {
            ksort($key['columns']);
            if($key['unique'] && array_keys($key['columns'])===[1,2] && array_values($key['columns'])===['company_id','token_hash']) return true;
        }
        return false;
    }
    public static function validate($request) {
        if (!is_array($request)) throw new InvalidArgumentException('Invalid hold request');
        $company=TZFD_Resource_Catalog::identifier($request['company_id']??null);
        if (!isset($request['verified_company_id']) || $request['verified_company_id']!==$company) throw new InvalidArgumentException('Unverified company');
        $ids=$request['required_resource_ids']??null;
        if (!is_array($ids) || !$ids || count($ids)>50) throw new InvalidArgumentException('Resources required');
        $seen=[];
        foreach($ids as $id) { $id=TZFD_Resource_Catalog::identifier($id); if(isset($seen[$id])) throw new InvalidArgumentException('Duplicate resource'); $seen[$id]=true; }
        $start=TZFD_Resource_Availability::instant($request['start']??null);
        $end=TZFD_Resource_Availability::instant($request['end']??null);
        if($end<=$start || $end-$start>86400) throw new InvalidArgumentException('Invalid interval');
        $ttl=$request['ttl_seconds']??300;
        if(!is_int($ttl) || $ttl<30 || $ttl>self::MAX_TTL) throw new InvalidArgumentException('Invalid TTL');
        return ['company_id'=>$company,'start'=>$start,'end'=>$end,'required_resource_ids'=>array_keys($seen),'ttl_seconds'=>$ttl];
    }
    /** All writers for a company MUST use this advisory lock; external booking writes must use the same coordinator. */
    private static function lock_name($company) { return 'tzfd_slot_'.substr(hash('sha256',$company),0,40); }
    private static function locked($company,$callback) {
        global $wpdb;
        $name=self::lock_name($company);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$name))!=='1') return new WP_Error('tzfd_lock_busy',__( 'Reservation coordinator unavailable', 'titan-zero-trades-front-desk' ));
        try { return $callback(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name)); }
    }
    /** Never pass damaged or inconsistent hold state to an authoritative booking writer. */
    public static function confirmation_payload($entry,$company) {
        if (!is_array($entry) || ($entry['company_id']??null)!==$company || ($entry['state']??null)!=='held') return false;
        $raw=$entry['payload']??null;
        if (!is_string($raw) || strlen($raw)>16384 || ''===$raw) return false;
        $data=json_decode($raw,true,16);
        if (!is_array($data) || !is_array($data['required_resource_ids']??null) || !$data['required_resource_ids'] || count($data['required_resource_ids'])>50) return false;
        $seen=[];
        try {
            foreach ($data['required_resource_ids'] as $id) {
                $normal=TZFD_Resource_Catalog::identifier($id);
                if (isset($seen[$normal])) return false;
                $seen[$normal]=true;
            }
            $start=TZFD_Resource_Availability::instant($data['start']??null);
            $end=TZFD_Resource_Availability::instant($data['end']??null);
        } catch (Throwable $e) { return false; }
        if ($end<=$start || $end-$start>86400 || !isset($data['quantity']) || !is_int($data['quantity']) || $data['quantity']<1 || $data['quantity']>10000) return false;
        if (($entry['start_utc']??null)!==gmdate('Y-m-d H:i:s',$start) || ($entry['end_utc']??null)!==gmdate('Y-m-d H:i:s',$end)) return false;
        return $data;
    }
}
