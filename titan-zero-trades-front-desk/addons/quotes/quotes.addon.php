<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned quote tables. Direct queries are intentional: caching quotes would serve stale totals and statuses. Table names are bound with %i placeholders and values with prepare().
/**
 * AI-Assisted Quotes for Field Service
 *
 * - Builds line-item estimates from a job description + photos
 * - Learns from the site's own accepted quote history (retrieval-augmented)
 * - Presents a customer-facing approval page with e-signature
 * - Fires a hook on approval so payment/deposit addons can charge
 *
 * Requires: ai-assistant.addon.php (provider resolver + store)
 * Optional: ai-multichannel.addon.php for channel intake
 */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-quote-screens.php';

/* ============================================================
 *  Schema
 * ============================================================ */

if ( ! class_exists( 'TZFD_Quotes_Engine' ) ) {
    class TZFD_Quotes_Engine
    {
        const T_QUOTES     = 'tzfd_quotes';
        const T_ITEMS      = 'tzfd_quote_items';
        const T_CATALOG    = 'tzfd_quote_catalog';
        const T_REVISIONS  = 'tzfd_quote_revisions';
        const T_SIGNATURES = 'tzfd_quote_signatures';

        const DB_VERSION = '1';
        const OPTION     = 'tzfd_quotes_db_version';

        /** Creates the tables only when the schema version changes, not on every page view. */
        public static function maybe_install() {
            if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
                self::install();
                update_option( self::OPTION, self::DB_VERSION, false );
            }
        }

        public static function install() {
            global $wpdb;
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $p = $wpdb->prefix;

            dbDelta( "CREATE TABLE {$p}".self::T_QUOTES." (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                token varchar(40) NOT NULL,
                formid int DEFAULT 0 NOT NULL,
                conv_token varchar(40) DEFAULT '' NOT NULL,
                reference varchar(40) DEFAULT '' NOT NULL,
                customer_name varchar(190) DEFAULT '' NOT NULL,
                customer_email varchar(190) DEFAULT '' NOT NULL,
                customer_phone varchar(40) DEFAULT '' NOT NULL,
                service_address text,
                service_type varchar(120) DEFAULT '' NOT NULL,
                description text,
                urgency varchar(20) DEFAULT 'routine' NOT NULL,
                status varchar(20) DEFAULT 'draft' NOT NULL,
                subtotal decimal(12,2) DEFAULT 0 NOT NULL,
                tax_rate decimal(6,3) DEFAULT 0 NOT NULL,
                tax_amount decimal(12,2) DEFAULT 0 NOT NULL,
                discount decimal(12,2) DEFAULT 0 NOT NULL,
                total decimal(12,2) DEFAULT 0 NOT NULL,
                deposit decimal(12,2) DEFAULT 0 NOT NULL,
                currency varchar(8) DEFAULT '' NOT NULL,
                valid_until date DEFAULT NULL,
                notes text,
                customer_message text,
                confidence decimal(4,2) DEFAULT 0 NOT NULL,
                ai_model varchar(80) DEFAULT '' NOT NULL,
                ai_source varchar(20) DEFAULT '' NOT NULL,
                created_by int DEFAULT 0 NOT NULL,
                reviewed_by int DEFAULT 0 NOT NULL,
                sent_at datetime DEFAULT NULL,
                viewed_at datetime DEFAULT NULL,
                approved_at datetime DEFAULT NULL,
                declined_at datetime DEFAULT NULL,
                booking_id mediumint(9) DEFAULT 0 NOT NULL,
                nudged_at datetime DEFAULT NULL,
                created datetime DEFAULT CURRENT_TIMESTAMP,
                updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY token (token),
                UNIQUE KEY reference (reference),
                PRIMARY KEY  (id),
                KEY form_status (formid, status),
                KEY customer (customer_email),
                KEY conv (conv_token)
            ) {$charset};" );

            dbDelta( "CREATE TABLE {$p}".self::T_ITEMS." (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                quote_id mediumint(9) NOT NULL,
                sort_order int DEFAULT 0 NOT NULL,
                kind varchar(20) DEFAULT 'labor' NOT NULL,
                sku varchar(60) DEFAULT '' NOT NULL,
                label varchar(190) NOT NULL,
                description text,
                qty decimal(10,2) DEFAULT 1 NOT NULL,
                unit varchar(20) DEFAULT 'each' NOT NULL,
                unit_price decimal(12,2) DEFAULT 0 NOT NULL,
                taxable tinyint(1) DEFAULT 1 NOT NULL,
                ai_suggested tinyint(1) DEFAULT 0 NOT NULL,
                notes text,
                PRIMARY KEY  (id),
                KEY quote (quote_id, sort_order)
            ) {$charset};" );

            dbDelta( "CREATE TABLE {$p}".self::T_CATALOG." (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                sku varchar(60) DEFAULT '' NOT NULL,
                label varchar(190) NOT NULL,
                description text,
                kind varchar(20) DEFAULT 'labor' NOT NULL,
                unit varchar(20) DEFAULT 'each' NOT NULL,
                unit_price decimal(12,2) DEFAULT 0 NOT NULL,
                taxable tinyint(1) DEFAULT 1 NOT NULL,
                keywords varchar(255) DEFAULT '' NOT NULL,
                service_type varchar(120) DEFAULT '' NOT NULL,
                active tinyint(1) DEFAULT 1 NOT NULL,
                UNIQUE KEY sku (sku),
                PRIMARY KEY  (id),
                KEY svc (service_type, active)
            ) {$charset};" );

            dbDelta( "CREATE TABLE {$p}".self::T_REVISIONS." (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                quote_id mediumint(9) NOT NULL,
                revision int DEFAULT 1 NOT NULL,
                snapshot mediumtext,
                reason varchar(190) DEFAULT '' NOT NULL,
                actor int DEFAULT 0 NOT NULL,
                created datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY quote (quote_id, revision)
            ) {$charset};" );

            dbDelta( "CREATE TABLE {$p}".self::T_SIGNATURES." (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                quote_id mediumint(9) NOT NULL,
                signer_name varchar(190) NOT NULL,
                signer_email varchar(190) DEFAULT '' NOT NULL,
                signature_data mediumtext,
                ip_hash varchar(64) DEFAULT '' NOT NULL,
                user_agent varchar(255) DEFAULT '' NOT NULL,
                accepted_terms tinyint(1) DEFAULT 1 NOT NULL,
                created datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY quote (quote_id)
            ) {$charset};" );
        }
    }
}

/* ============================================================
 *  Quote model
 * ============================================================ */

if ( ! class_exists( 'TZFD_Quote' ) ) {
    class TZFD_Quote
    {
        public static function create( array $data ) {
            global $wpdb;
            $token = wp_generate_password( 32, false, false );
            $reference = self::next_reference();
            $wpdb->insert( $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES, array_merge( array(
                'token'      => $token,
                'reference'  => $reference,
                'currency'   => ( class_exists('TZFD_Money') ? TZFD_Money::currency_code() : 'AUD' ),
                'created_by' => get_current_user_id(),
            ), $data ) );
            return intval( $wpdb->insert_id );
        }

        public static function get( $id ) {
            global $wpdb;
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM %i WHERE id=%d",
                $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES, intval($id) ) );
        }

        public static function get_by_token( $token ) {
            global $wpdb;
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM %i WHERE token=%s",
                $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES, sanitize_text_field($token) ) );
        }

        /** True when a quote's valid-until date has passed. The date counts through to the end of that day (site time zone). */
        public static function is_past_valid_until( $q ) {
            $until = (string) ( $q->valid_until ?? '' );
            return '' !== $until && '0000-00-00' !== $until && current_time( 'Y-m-d' ) > $until;
        }

        /**
         * Why a quote cannot be approved now, or '' when it can. Only a sent quote still within its
         * valid-until date can be approved.
         */
        public static function approval_refusal( $q ) {
            if ( ! $q ) return 'not_found';
            switch ( $q->status ) {
                case 'approved': return 'approved';
                case 'declined': return 'declined';
                case 'expired':  return 'expired';
                case 'sent':     return self::is_past_valid_until( $q ) ? 'expired' : '';
                default:         return 'not_sent';
            }
        }

        /** Customer-facing message for a refused approval. */
        public static function refusal_message( $reason ) {
            switch ( $reason ) {
                case 'approved': return __( 'This quote has already been approved.', 'titan-zero-trades-front-desk' );
                case 'declined': return __( 'This quote has been declined, so it cannot be approved.', 'titan-zero-trades-front-desk' );
                case 'expired':  return __( 'This quote has expired, so it cannot be approved.', 'titan-zero-trades-front-desk' );
                default:         return __( 'This quote is not open for approval.', 'titan-zero-trades-front-desk' );
            }
        }

        /** A text parameter from a REST request as a string, unslashed. Arrays and objects become ''. */
        public static function request_text( WP_REST_Request $req, $key ) {
            $value = $req->get_param( $key );
            return is_scalar( $value ) ? wp_unslash( (string) $value ) : '';
        }

        /**
         * REST permission check for the public quote routes (approve, decline and the PDF). Anyone with the
         * emailed link may use them, so the token in that link is the credential. It must match the stored
         * token exactly; hash_equals() avoids timing leaks and case-insensitive matching.
         */
        public static function rest_token_permission( WP_REST_Request $req ) {
            $token = sanitize_text_field( wp_unslash( (string) $req->get_param( 'token' ) ) );
            $quote = '' !== $token ? self::get_by_token( $token ) : null;
            if ( ! $quote ) {
                // Same message and status as a wrong token, so a bad link does not reveal whether a quote exists.
                return new WP_Error( 'tzfd_quote_token', __( 'This quote link is not valid.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
            }
            if ( ! hash_equals( (string) $quote->token, $token ) ) {
                return new WP_Error( 'tzfd_quote_token', __( 'This quote link is not valid.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
            }
            return true;
        }

        public static function items( $quote_id ) {
            global $wpdb;
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE quote_id=%d ORDER BY sort_order ASC, id ASC",
                $wpdb->prefix.TZFD_Quotes_Engine::T_ITEMS, intval($quote_id) ) );
        }

        public static function add_item( $quote_id, array $item ) {
            global $wpdb;
            $wpdb->insert( $wpdb->prefix.TZFD_Quotes_Engine::T_ITEMS, array(
                'quote_id'     => intval($quote_id),
                'sort_order'   => intval( $item['sort_order'] ?? 0 ),
                'kind'         => sanitize_text_field( $item['kind'] ?? 'labor' ),
                'sku'          => sanitize_text_field( $item['sku'] ?? '' ),
                'label'        => sanitize_text_field( $item['label'] ?? '' ),
                'description'  => wp_kses_post( $item['description'] ?? '' ),
                'qty'          => floatval( $item['qty'] ?? 1 ),
                'unit'         => sanitize_text_field( $item['unit'] ?? 'each' ),
                'unit_price'   => floatval( $item['unit_price'] ?? 0 ),
                'taxable'      => ! empty( $item['taxable'] ) ? 1 : 0,
                'ai_suggested' => ! empty( $item['ai_suggested'] ) ? 1 : 0,
                'notes'        => wp_kses_post( $item['notes'] ?? '' ),
            ) );
            return intval( $wpdb->insert_id );
        }

        public static function replace_items( $quote_id, array $items ) {
            global $wpdb;
            $wpdb->delete( $wpdb->prefix.TZFD_Quotes_Engine::T_ITEMS, array('quote_id'=>$quote_id), array('%d') );
            $i = 0;
            foreach ( $items as $item ) {
                $item['sort_order'] = $i++;
                self::add_item( $quote_id, $item );
            }
            self::recalculate( $quote_id );
        }

        public static function recalculate( $quote_id ) {
            global $wpdb;
            $q = self::get( $quote_id );
            if ( ! $q ) return;
            $items = self::items( $quote_id );

            $subtotal = 0; $taxable = 0;
            foreach ( $items as $it ) {
                $line = floatval($it->qty) * floatval($it->unit_price);
                $subtotal += $line;
                if ( $it->taxable ) $taxable += $line;
            }
            $discount = floatval( $q->discount );
            $taxable_after_discount = max( 0, $taxable - $discount * ($taxable / max($subtotal,0.01)) );
            $tax = $taxable_after_discount * (floatval($q->tax_rate) / 100);
            $total = max( 0, $subtotal - $discount + $tax );

            $wpdb->update( $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES, array(
                'subtotal'   => round($subtotal,2),
                'tax_amount' => round($tax,2),
                'total'      => round($total,2),
            ), array( 'id' => $quote_id ) );
        }

        public static function snapshot( $quote_id, $reason = '' ) {
            global $wpdb;
            $q = self::get( $quote_id );
            if ( ! $q ) return;
            $items = self::items( $quote_id );
            $rev = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(revision) FROM %i WHERE quote_id=%d",
                $wpdb->prefix.TZFD_Quotes_Engine::T_REVISIONS, $quote_id ) ) ) + 1;

            $wpdb->insert( $wpdb->prefix.TZFD_Quotes_Engine::T_REVISIONS, array(
                'quote_id' => $quote_id,
                'revision' => $rev,
                'snapshot' => wp_json_encode( array('quote'=>$q, 'items'=>$items) ),
                'reason'   => sanitize_text_field( $reason ),
                'actor'    => get_current_user_id(),
            ) );
        }

        public static function approve( $quote_id, array $signature ) {
            global $wpdb;
            $q = self::get( $quote_id );
            if ( ! $q ) return false;
            if ( '' !== self::approval_refusal( $q ) ) return false;

            // Claim the transition first. Only one concurrent request can move the quote from 'sent' to 'approved'.
            $claimed = $wpdb->query( $wpdb->prepare(
                'UPDATE '.$wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES." SET status = 'approved', approved_at = %s WHERE id = %d AND status = 'sent'",
                current_time('mysql'), $quote_id ) );
            if ( 1 !== (int) $claimed ) return false;

            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
            $wpdb->insert( $wpdb->prefix.TZFD_Quotes_Engine::T_SIGNATURES, array(
                'quote_id'       => $quote_id,
                'signer_name'    => sanitize_text_field( $signature['name'] ?? '' ),
                'signer_email'   => sanitize_email( $signature['email'] ?? '' ),
                'signature_data' => sanitize_text_field( is_string( $signature['data'] ?? null ) ? $signature['data'] : '' ),
                'ip_hash'        => substr( hash_hmac('sha256', $ip, wp_salt('auth')), 0, 32 ),
                'user_agent'     => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ),
                'accepted_terms' => ! empty( $signature['terms'] ) ? 1 : 0,
            ) );
            /**
             * Fires when a customer approves a quote. Payment / deposit addons
             * can hook here to charge a card and mark the quote paid.
             */
            do_action( 'tzfd_quote_approved', $quote_id, $q );

            return true;
        }

        public static function decline( $quote_id, $reason = '' ) {
            global $wpdb;
            $q = self::get( $quote_id );
            if ( ! $q || ! in_array( $q->status, array('draft','sent'), true ) ) return false;
            $wpdb->update( $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES,
                array( 'status' => 'declined', 'declined_at' => current_time('mysql'), 'notes' => trim( $q->notes."\n\nCustomer declined: ".$reason ) ),
                array( 'id' => $quote_id ) );
            do_action( 'tzfd_quote_declined', $quote_id, $q, $reason );
            return true;
        }

        /** Public approval link for a quote token. */
        public static function public_url( $token ) {
            $page = get_option('tzfd_quotes_page', '');
            $base = $page ? get_permalink( intval($page) ) : home_url('/');
            return add_query_arg( 'tzfd_quote', $token, $base );
        }

        /**
         * Mark a draft or sent quote as sent, fire tzfd_quote_sent BEFORE the email
         * goes out (so the PDF addon can attach to it), then email the customer.
         * Used by the tech screen and the admin "Resend" button.
         */
        public static function send( $quote_id ) {
            global $wpdb;
            $q = self::get( $quote_id );
            if ( ! $q || ! in_array( $q->status, array('draft','sent'), true ) ) return false;

            $wpdb->update( $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES,
                array( 'status' => 'sent', 'sent_at' => current_time('mysql'), 'nudged_at' => null ),
                array( 'id' => $quote_id ) );
            $q   = self::get( $quote_id );
            $url = self::public_url( $q->token );

            do_action( 'tzfd_quote_sent', intval( $quote_id ), $q->customer_email );

            if ( $q->customer_email ) {
                wp_mail( $q->customer_email,
                    sprintf( '[%s] Your quote %s', get_bloginfo('name'), $q->reference ),
                    sprintf( "Hi %s,\n\nHere is your estimate for %s.\nTotal: %s\n\nReview, approve, and download as PDF:\n%s\n\n— %s",
                        $q->customer_name ?: 'there',
                        $q->service_type ?: 'your job',
                        TZFD_Quote_Screens::money( $q->total ),
                        $url,
                        get_bloginfo('name') ) );
            }

            return array(
                'to'    => $q->customer_email ?: $q->customer_phone,
                'url'   => $url,
                'total' => floatval( $q->total ),
            );
        }

        /** Human-friendly reference like Q-20260309-A4F1 */
        private static function next_reference() {
            return 'Q-'.gmdate('Ymd').'-'.strtoupper( wp_generate_password(4, false, false) );
        }
    }
}

/* ============================================================
 *  Catalog
 * ============================================================ */

if ( ! class_exists( 'TZFD_Quote_Catalog' ) ) {
    class TZFD_Quote_Catalog
    {
        public static function all() {
            global $wpdb;
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM %i WHERE active=1 ORDER BY service_type, label", $wpdb->prefix.TZFD_Quotes_Engine::T_CATALOG ) );
        }

        /**
         * Keyword-search the catalog. Used to seed the AI prompt with the
         * items most likely to appear in a quote for this job.
         */
        public static function search( $query, $service_type = '', $limit = 40 ) {
            global $wpdb;
            $q_words = array_unique( array_filter( preg_split('/\W+/', strtolower($query)),
                function($w){ return strlen($w) > 2; } ) );

            $tzfd_catalog = $wpdb->prefix.TZFD_Quotes_Engine::T_CATALOG;
            $rows = $service_type
                ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE active=1 AND (service_type=%s OR service_type='') LIMIT 500", $tzfd_catalog, $service_type ) )
                : $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE active=1 LIMIT 500", $tzfd_catalog ) );
            if ( ! $rows ) return array();

            $scored = array();
            foreach ( $rows as $r ) {
                $hay = strtolower( $r->label.' '.$r->keywords.' '.$r->description.' '.$r->service_type );
                $score = 0;
                foreach ( $q_words as $w ) if ( strpos($hay, $w) !== false ) $score += 2;
                foreach ( array_filter( array_map('trim', explode(',', strtolower($r->keywords)) ) ) as $k ) {
                    if ( $k && strpos( strtolower($query), $k) !== false ) $score += 3;
                }
                if ( $service_type && stripos($r->service_type, $service_type) !== false ) $score += 4;
                if ( $score > 0 ) $scored[] = array( $score, $r );
            }
            usort( $scored, function($a,$b){ return $b[0] <=> $a[0]; } );
            return array_map( function($s){ return $s[1]; }, array_slice( $scored, 0, $limit ) );
        }

        /** Import a starter catalog for common trades. */
        public static function seed() {
            global $wpdb;
            $items = self::starter_items();
            $existing = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM %i", $wpdb->prefix.TZFD_Quotes_Engine::T_CATALOG ) ) );
            if ( $existing > 0 ) return;
            foreach ( $items as $it ) $wpdb->insert( $wpdb->prefix.TZFD_Quotes_Engine::T_CATALOG, $it );
        }

        private static function starter_items() {
            $mk = function( $sku, $label, $kind, $unit, $price, $svc, $kw = '', $tax = 1 ) {
                return array(
                    'sku'=>$sku, 'label'=>$label, 'kind'=>$kind, 'unit'=>$unit,
                    'unit_price'=>$price, 'service_type'=>$svc, 'keywords'=>$kw,
                    'taxable'=>$tax, 'active'=>1,
                );
            };
            return array(
                // Plumbing
                $mk('PLB-LAB-1','Plumbing labor (hourly)','labor','hour',95,'Plumbing','labour,work,hands'),
                $mk('PLB-CALL','Callout fee','labor','each',65,'Plumbing','callout,dispatch,trip'),
                $mk('PLB-FLX','Flexible hose — 3/8" × 12"','material','each',12,'Plumbing','hose,supply,flex',1),
                $mk('PLB-VLV-BALL','Ball valve — 1/2"','material','each',28,'Plumbing','valve,shutoff,isolation',1),
                $mk('PLB-TRAP-P','P-trap assembly','material','each',32,'Plumbing','trap,sink,drain',1),
                $mk('PLB-WH-HEAT','Water heater — heater element','material','each',85,'Plumbing','element,heater,hotwater',1),
                $mk('PLB-WH-TANK','Water heater — tank replacement (40gal)','material','each',780,'Plumbing','tank,waterheater,replace',1),
                $mk('PLB-DRAIN-CLN','Drain cleaning (per line)','labor','each',145,'Plumbing','drain,clog,blockage,sewer'),
                // HVAC
                $mk('HVC-LAB-1','HVAC labor (hourly)','labor','hour',120,'HVAC','labour,work,hands'),
                $mk('HVC-CALL','HVAC diagnostic visit','labor','each',89,'HVAC','diagnostic,visit,trip'),
                $mk('HVC-REF','Refrigerant R-410A (per lb)','material','lb',18,'HVAC','refrigerant,freon,r410',1),
                $mk('HVC-CAP','Run capacitor','material','each',45,'HVAC','capacitor,cap,run',1),
                $mk('HVC-FILT','Air filter replacement','material','each',28,'HVAC','filter,airfilter,hepa',1),
                $mk('HVC-CLEAN','Coil cleaning','labor','each',210,'HVAC','coil,clean,maintenance'),
                $mk('HVC-STAT','Smart thermostat install','labor','each',195,'HVAC','thermostat,tstat,smart'),
                // Electrical
                $mk('ELC-LAB-1','Electrical labor (hourly)','labor','hour',110,'Electrical','labour,work,hands'),
                $mk('ELC-CALL','Electrical diagnostic visit','labor','each',99,'Electrical','diagnostic,visit'),
                $mk('ELC-GFCI','GFCI outlet replacement','labor','each',125,'Electrical','gfci,outlet,receptacle'),
                $mk('ELC-BRK','Circuit breaker — 20A','material','each',38,'Electrical','breaker,circuit,panel',1),
                $mk('ELC-WIRE-12','12/2 NM-B wire (per ft)','material','ft',1.4,'Electrical','wire,cable,nm',1),
                // Locksmith
                $mk('LCK-CALL','Callout — lockout service','labor','each',135,'Locksmith','lockout,emergency'),
                $mk('LCK-REKEY','Rekey lock (per cylinder)','labor','each',45,'Locksmith','rekey,cylinder'),
                $mk('LCK-CYL','Standard key cylinder','material','each',55,'Locksmith','cylinder,barrel',1),
                $mk('LCK-SMART','Smart deadbolt install','labor','each',185,'Locksmith','smart,deadbolt,keypad'),
                // Cleaning
                $mk('CLN-STD-1','Standard clean (per hour)','labor','hour',55,'Cleaning','clean,hourly,regular'),
                $mk('CLN-DEEP-1','Deep clean (per hour)','labor','hour',85,'Cleaning','deep,thorough'),
                $mk('CLN-SUP','Supplies surcharge','material','each',15,'Cleaning','supplies,sundries',1),
                // Pest
                $mk('PST-INSP','Pest inspection','labor','each',85,'Pest control','inspect,check,visit'),
                $mk('PST-TRT-1','General pest treatment','labor','each',175,'Pest control','treatment,spray,general'),
                $mk('PST-TERM','Termite inspection + report','labor','each',295,'Pest control','termite,inspection,report'),
            );
        }
    }
}

/* ============================================================
 *  AI quote engine
 * ============================================================ */

if ( ! class_exists( 'TZFD_Quote_AI' ) ) {
    class TZFD_Quote_AI
    {
        /**
         * Build a suggested quote from a job description.
         *
         * @return array [
         *   'ok'            => bool,
         *   'items'         => [ [ kind, sku, label, description, qty, unit, unit_price, taxable, ai_suggested ], ... ],
         *   'notes'         => string  (internal tech note),
         *   'customer_message' => string (customer-facing explanation),
         *   'confidence'    => float 0–1,
         *   'needs_visit'   => bool,
         *   'source'        => 'history' | 'catalog' | 'ai'
         * ]
         */
        public static function suggest( $job, array $context = array() ) {
            // 1. Retrieve similar accepted quotes for few-shot grounding
            $history = self::similar_history( $job, 5 );

            // 2. Retrieve relevant catalog items
            $catalog = TZFD_Quote_Catalog::search(
                $job['description'] ?? '',
                $job['service_type'] ?? '',
                50 );

            // 3. Get the provider
            $provider = self::resolve_provider();
            if ( ! $provider ) {
                return self::fallback( $job, $catalog );
            }

            // 4. Build system prompt + tool
            $system = self::build_system( $job, $catalog, $history, $context );
            $tool   = self::build_tool();

            // 5. Call the provider with forced tool usage
            $messages = array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => self::build_user_turn( $job ) ),
            );
            $res = $provider->chat( $messages, array( $tool ), array(
                'temperature' => 0.2,
                'max_tokens'  => 1500,
                'tool_choice' => array( 'type'=>'function', 'function'=>array('name'=>'build_quote') ),
            ) );

            if ( ! empty($res['error']) ) return self::fallback( $job, $catalog, $res['error'] );

            $call = null;
            foreach ( (array)($res['tool_calls'] ?? array()) as $c ) {
                if ( ( $c['name'] ?? '' ) === 'build_quote' ) { $call = $c; break; }
            }
            if ( ! $call ) return self::fallback( $job, $catalog, 'no_tool_call' );

            $args = $call['args'] ?? array();
            $items = self::normalize_items( $args['line_items'] ?? array() );
            if ( empty($items) ) return self::fallback( $job, $catalog, 'empty_items' );

            return array(
                'ok'               => true,
                'items'            => $items,
                'notes'            => wp_kses_post( $args['internal_notes'] ?? '' ),
                'customer_message' => wp_kses_post( $args['customer_message'] ?? '' ),
                'confidence'       => min( 1, max( 0, floatval( $args['confidence'] ?? 0.6 ) ) ),
                'needs_visit'      => ! empty( $args['needs_site_visit'] ),
                'source'           => $history ? 'history' : 'ai',
                'provider'         => method_exists( $provider, 'id' ) ? $provider->id() : 'custom',
            );
        }

        /** Filter first (lets a site inject a provider), then the AI Booking Assistant's provider. */
        private static function resolve_provider() {
            $p = apply_filters( 'tzfd_ai_provider', null );
            if ( $p instanceof TZFD_AI_Provider ) return $p;
            global $tzfd_addons_objs_list;
            $assistant = $tzfd_addons_objs_list['tzfd-AIAssistant'] ?? null;
            return ( $assistant && method_exists( $assistant, 'get_provider' ) ) ? $assistant->get_provider() : null;
        }

        /* ---------------- Prompt assembly ---------------- */

        private static function build_system( $job, $catalog, $history, $context ) {
            $biz   = get_option('tzfd_business_profile', array());
            $name  = $biz['name'] ?? get_bloginfo('name');
            $cur   = ( class_exists('TZFD_Money') ? TZFD_Money::currency_code() : 'AUD' );
            $tax   = floatval( $context['tax_rate'] ?? 0 );
            $mark  = floatval( $context['markup']   ?? 0 );

            $catalog_txt = '';
            foreach ( $catalog as $c ) {
                $catalog_txt .= sprintf(
                    "- [%s] %s — %s%s per %s\n",
                    $c->sku ?: '—',
                    $c->label,
                    $cur.' '.number_format( floatval($c->unit_price), 2 ),
                    $c->taxable ? ' (taxable)' : '',
                    $c->unit
                );
            }
            if ( ! $catalog_txt ) $catalog_txt = '(none matched — propose sensible items)';

            $history_txt = '';
            foreach ( $history as $h ) {
                $history_txt .= sprintf(
                    "\nHISTORICAL QUOTE %s — accepted, total %s%s\n  Service: %s\n  Job: %s\n  Line items:\n",
                    $h['reference'],
                    $cur, number_format( $h['total'], 2 ),
                    $h['service_type'],
                    mb_substr( $h['description'], 0, 240 )
                );
                foreach ( $h['items'] as $it ) {
                    $history_txt .= sprintf( "    • %s — %s × %s %s = %s%s\n",
                        $it['label'],
                        $it['qty'], $it['unit'],
                        $cur.number_format($it['unit_price'],2),
                        $cur.number_format($it['qty']*$it['unit_price'],2)
                    );
                }
            }
            if ( ! $history_txt ) $history_txt = '(no historical quotes yet — reason from the catalog only)';

            $urgency = $job['urgency'] ?? 'routine';
            $emergency_note = $urgency === 'emergency'
                ? "\nURGENCY: EMERGENCY. Apply a 50% surcharge line item labelled 'Emergency callout surcharge'."
                : ($urgency === 'urgent' ? "\nURGENCY: URGENT. Apply a 20% surcharge line item labelled 'Priority service surcharge'." : '');

            $extra = trim( get_option('tzfd_quotes_extra_prompt', '') );

            return trim( implode( "\n", array(
            'You are quoting for ' . $name . ', a field service business. Currency: ' . $cur . '.',
            'Default tax rate: ' . $tax . '%. Desired margin/markup on materials: ' . $mark . '%.',
            '',
            'Your job: build a professional, realistic line-item estimate for the job described by the customer. Ground every line item in either the CATALOG below or a HISTORICAL QUOTE pattern. Never invent a price for a catalog item — use the catalog price. When you must propose an item not in the catalog, mark it clearly and use a conservative industry-standard price.',
            '',
            'Rules:',
            '- Separate labor from materials with "kind": "labor" | "material" | "fee" | "travel".',
            '- Labor is billed in hours unless the catalog says otherwise.',
            '- Include a callout / diagnostic fee if the job warrants one.',
            '- Materials should reflect realistic quantities. Round to 2 decimals.',
            '- Keep the total defensible. If the job is ambiguous, reduce the item list and set needs_site_visit=true.',
            '- Set confidence 0.0–1.0 based on how well the job matches the catalog + history. Low confidence → needs_site_visit.',
            '- Never include a total line — the system computes subtotal, tax, and total.',
            '- Customer message: 2–4 short sentences, plain language, no jargon, no markdown. ',
            $emergency_note,
            '',
            'CATALOG (matched to this job):',
            $catalog_txt,
            '',
            'HISTORY:',
            $history_txt,
            '',
            $extra,
            ) ) );
        }

        private static function build_user_turn( $job ) {
            $out  = "JOB TO QUOTE\n";
            $out .= "Service type: " . ($job['service_type'] ?? 'unknown') . "\n";
            $out .= "Urgency: " . ($job['urgency'] ?? 'routine') . "\n";
            if ( ! empty($job['customer_name']) ) $out .= "Customer: {$job['customer_name']}\n";
            if ( ! empty($job['address']) )       $out .= "Address: {$job['address']}\n";
            $out .= "\nDescription:\n" . ($job['description'] ?? '') . "\n";
            if ( ! empty($job['equipment']) )  $out .= "\nEquipment: {$job['equipment']}\n";
            if ( ! empty($job['access_notes']) ) $out .= "\nAccess notes: {$job['access_notes']}\n";
            if ( ! empty($job['photos']) && is_array($job['photos']) ) {
                $out .= "\nPhotos: " . count($job['photos']) . " attached\n";
            }
            return $out;
        }

        /* ---------------- Tool schema ---------------- */

        private static function build_tool() {
            return array(
                'type' => 'function',
                'function' => array(
                    'name' => 'build_quote',
                    'description' => 'Return a structured line-item quote for the described job.',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'line_items' => array(
                                'type' => 'array',
                                'items' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'kind'        => array('type'=>'string','enum'=>array('labor','material','fee','travel')),
                                        'sku'         => array('type'=>'string'),
                                        'label'       => array('type'=>'string'),
                                        'description' => array('type'=>'string'),
                                        'qty'         => array('type'=>'number'),
                                        'unit'        => array('type'=>'string'),
                                        'unit_price'  => array('type'=>'number'),
                                        'taxable'     => array('type'=>'boolean'),
                                    ),
                                    'required' => array('kind','label','qty','unit','unit_price'),
                                ),
                            ),
                            'internal_notes'    => array('type'=>'string','description'=>'Note for the tech, not the customer.'),
                            'customer_message'  => array('type'=>'string','description'=>'Customer-facing summary, plain language.'),
                            'confidence'        => array('type'=>'number','description'=>'0.0 to 1.0'),
                            'needs_site_visit'  => array('type'=>'boolean'),
                        ),
                        'required' => array('line_items','confidence'),
                    ),
                ),
            );
        }

        /* ---------------- History retrieval ---------------- */

        private static function similar_history( $job, $limit = 5 ) {
            global $wpdb;
            $svc = sanitize_text_field( $job['service_type'] ?? '' );
            $tzfd_quotes = $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES;
            $rows = $svc
                ? $wpdb->get_results( $wpdb->prepare( "SELECT id, reference, service_type, description, total FROM %i WHERE status='approved' AND (service_type=%s OR service_type='') ORDER BY approved_at DESC LIMIT 50", $tzfd_quotes, $svc ) )
                : $wpdb->get_results( $wpdb->prepare( "SELECT id, reference, service_type, description, total FROM %i WHERE status='approved' ORDER BY approved_at DESC LIMIT 50", $tzfd_quotes ) );
            if ( ! $rows ) return array();

            // Score by word overlap with the current description
            $q_words = array_unique( array_filter( preg_split('/\W+/', strtolower( $job['description'] ?? '' )),
                function($w){ return strlen($w) > 3; } ) );
            $scored = array();
            foreach ( $rows as $r ) {
                $hay = strtolower( $r->description . ' ' . $r->service_type );
                $s = 0;
                foreach ( $q_words as $w ) if ( strpos($hay, $w) !== false ) $s += 2;
                if ( $svc && stripos($r->service_type, $svc) !== false ) $s += 5;
                $scored[] = array( $s, $r );
            }
            usort( $scored, function($a,$b){ return $b[0] <=> $a[0]; } );

            $out = array();
            foreach ( array_slice( $scored, 0, $limit ) as $s ) {
                if ( $s[0] < 3 ) continue;
                $items = TZFD_Quote::items( $s[1]->id );
                $out[] = array(
                    'reference'    => $s[1]->reference,
                    'service_type' => $s[1]->service_type,
                    'description'  => $s[1]->description,
                    'total'        => floatval( $s[1]->total ),
                    'items'        => array_map( function($i){
                        return array(
                            'label'      => $i->label,
                            'qty'        => floatval($i->qty),
                            'unit'       => $i->unit,
                            'unit_price' => floatval($i->unit_price),
                        );
                    }, $items ),
                );
            }
            return $out;
        }

        /* ---------------- Normalization + fallback ---------------- */

        private static function normalize_items( $items ) {
            $out = array();
            foreach ( (array)$items as $it ) {
                if ( ! is_array($it) ) continue;
                $label = trim( (string)($it['label'] ?? '') );
                if ( ! $label ) continue;
                $out[] = array(
                    'kind'         => in_array( $it['kind'] ?? 'labor', array('labor','material','fee','travel'), true ) ? ( $it['kind'] ?? 'labor' ) : 'labor',
                    'sku'          => sanitize_text_field( $it['sku'] ?? '' ),
                    'label'        => sanitize_text_field( $label ),
                    'description'  => wp_kses_post( $it['description'] ?? '' ),
                    'qty'          => max( 0.01, floatval( $it['qty'] ?? 1 ) ),
                    'unit'         => sanitize_text_field( $it['unit'] ?? 'each' ),
                    'unit_price'   => max( 0, floatval( $it['unit_price'] ?? 0 ) ),
                    'taxable'      => ! empty( $it['taxable'] ),
                    'ai_suggested' => 1,
                );
            }
            return $out;
        }

        /**
         * Deterministic fallback when there is no AI provider configured.
         * Uses the catalog + keyword matching so the quote flow never breaks.
         */
        private static function fallback( $job, $catalog, $error = '' ) {
            $items = array();
            $svc = sanitize_text_field( $job['service_type'] ?? '' );
            $desc = strtolower( $job['description'] ?? '' );

            // Always include a callout/diagnostic if the catalog has one
            foreach ( $catalog as $c ) {
                if ( stripos($c->label, 'callout') !== false || stripos($c->label, 'diagnostic') !== false ) {
                    $items[] = array(
                        'kind'=>'fee','sku'=>$c->sku,'label'=>$c->label,
                        'qty'=>1,'unit'=>$c->unit,'unit_price'=>floatval($c->unit_price),
                        'taxable'=>$c->taxable ? 1 : 0,'ai_suggested'=>0,
                    );
                    break;
                }
            }
            // Add the 4 highest-scoring catalog items
            $added = 0;
            foreach ( $catalog as $c ) {
                if ( $added >= 4 ) break;
                if ( stripos($c->label, 'callout') !== false ) continue;
                $items[] = array(
                    'kind'=>$c->kind,'sku'=>$c->sku,'label'=>$c->label,
                    'qty'=>1,'unit'=>$c->unit,'unit_price'=>floatval($c->unit_price),
                    'taxable'=>$c->taxable ? 1 : 0,'ai_suggested'=>0,
                );
                $added++;
            }
            // Guarantee a labor line
            $has_labor = false;
            foreach ( $items as $i ) if ( $i['kind'] === 'labor' ) { $has_labor = true; break; }
            if ( ! $has_labor ) {
                $items[] = array(
                    'kind'=>'labor','label'=>'Field labor (estimated)',
                    'qty'=>2,'unit'=>'hour','unit_price'=>95,'taxable'=>1,'ai_suggested'=>0,
                );
            }

            return array(
                'ok'               => true,
                'items'            => $items,
                'notes'            => $error ? 'AI unavailable ('.$error.'). Fallback catalog items proposed — review before sending.' : 'Review before sending.',
                'customer_message' => 'Here is our initial estimate based on your description. Final pricing may be adjusted after inspection.',
                'confidence'       => 0.3,
                'needs_visit'      => true,
                'source'           => 'catalog',
            );
        }
    }
}

/* ============================================================
 *  Main addon
 * ============================================================ */

if ( ! class_exists( 'TZFD_QuotesAddon' ) ) {

    class TZFD_QuotesAddon extends TZFD_BaseAddon
    {
        public function get_addon_settings()
        {
            return TZFD_Addon_Support::info_block( __( 'Quote pricing, tax, deposits, validity and terms are set on the Quotes page.', 'titan-zero-trades-front-desk' ), array( __( 'Open the Quotes page', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tzfd-quotes' ) ) );
        }

        
        protected $addonID = "tzfd-Quotes";
        protected $name = "AI-Assisted Quotes";
        protected $description;
        public $category = ' Quotes and estimates (included in this plugin version)';
        public $help = '';

        function __construct() {
            $this->description = $this->tr_apply(
                "Builds line-item estimates from a job description, learns from your own accepted quote history, and gives customers a branded approval page with e-signature and deposit tracking.",
                'titan-zero-trades-front-desk'
            );
            if ( ! $this->addon_is_active() ) return;

            TZFD_Quotes_Engine::maybe_install();

            add_shortcode( 'tzfd_quote',            array( &$this, 'shortcode_quote_page' ) );
            add_shortcode( 'tzfd_quote_button',     array( &$this, 'shortcode_quote_button' ) );
            add_action( 'rest_api_init',              array( &$this, 'register_routes' ) );
            // Admin screens, saves and the price catalog live in the Quotes screens class.
            TZFD_Quote_Screens::register();

            // Cron: expire stale quotes, follow up on sent-but-unviewed
            if ( ! wp_next_scheduled( 'tzfd_quote_engine_tick' ) ) {
                wp_schedule_event( time() + 600, 'hourly', 'tzfd_quote_engine_tick' );
            }
            add_action( 'tzfd_quote_engine_tick', array( &$this, 'cron' ) );
        }

        /* ============================================================
         *  Customer-facing page
         * ============================================================ */

        private function public_url( $token ) {
            $page = get_option('tzfd_quotes_page', '');
            if ( $page ) return add_query_arg( 'tzfd_quote', $token, get_permalink( intval($page) ) );
            return add_query_arg( 'tzfd_quote', $token, home_url('/') );
        }

        public function shortcode_quote_page() {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public quote link: the unguessable token is the authentication, and this read changes no state.
            $token = sanitize_text_field( wp_unslash( $_GET['tzfd_quote'] ?? '' ) );
            if ( ! $token ) return '<p>' . esc_html__('No quote specified.', 'titan-zero-trades-front-desk') . '</p>';
            $q = TZFD_Quote::get_by_token( $token );
            if ( ! $q ) return '<p>' . esc_html__('Quote not found.', 'titan-zero-trades-front-desk') . '</p>';

            // Mark viewed
            if ( ! $q->viewed_at ) {
                global $wpdb;
                $wpdb->update( $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES,
                    array( 'viewed_at' => current_time('mysql') ), array( 'id' => $q->id ) );
            }

            $items = TZFD_Quote::items( $q->id );
            $terms = get_option('tzfd_quotes_terms', '');

            // Approve and decline behaviour lives in a script file; the token and texts are passed through localize.
            wp_enqueue_script( 'tzfd-quote-approve', TZFD_Brand::url( 'js/inline/quotes-approve.js' ), array( 'jquery' ), '2.0.1', true );
            wp_localize_script( 'tzfd-quote-approve', 'tzfdQuoteApprove', array(
                'token'      => $q->token,
                'approveUrl' => rest_url( 'tzfd/v1/quotes/approve' ),
                'declineUrl' => rest_url( 'tzfd/v1/quotes/decline' ),
                'i18n'       => array(
                    'processing'    => __( 'Processing…', 'titan-zero-trades-front-desk' ),
                    'approvedTitle' => __( 'Approved — thank you!', 'titan-zero-trades-front-desk' ),
                    'approvedBody'  => __( 'A confirmation email is on its way.', 'titan-zero-trades-front-desk' ),
                    'error'         => __( 'Error', 'titan-zero-trades-front-desk' ),
                    'networkError'  => __( 'Network error. Please try again.', 'titan-zero-trades-front-desk' ),
                    'declinePrompt' => __( 'Anything we should know?', 'titan-zero-trades-front-desk' ),
                    'declined'      => __( 'This quote has been declined. Thank you for letting us know.', 'titan-zero-trades-front-desk' ),
                ),
            ) );

            // Expired here means the status, or a sent quote whose valid-until date has passed (the cron may not have run yet).
            $is_expired = 'expired' === TZFD_Quote::approval_refusal( $q );

            ob_start(); ?>
            <div class="tzfd-quote-wrap" style="max-width:760px;margin:30px auto;font:15px/1.6 system-ui;color:#0f172a;">
                <div class="tzfd-quote-head" style="border-bottom:2px solid #0f172a;padding-bottom:12px;">
                    <h1 style="margin:0;"><?php echo esc_html( get_bloginfo('name') ); ?></h1>
                    <p style="margin:4px 0 0;color:#64748b;">
                        <?php esc_html_e('Quote', 'titan-zero-trades-front-desk'); ?>
                        <strong><?php echo esc_html($q->reference); ?></strong>
                        · <?php echo esc_html( mysql2date('j M Y', $q->created) ); ?>
                    </p>
                </div>

                <div style="margin-top:20px;">
                    <p style="margin:0;color:#64748b;"><?php esc_html_e('Prepared for', 'titan-zero-trades-front-desk'); ?></p>
                    <p style="margin:0;font-weight:bold;"><?php echo esc_html($q->customer_name ?: $q->customer_email); ?></p>
                    <?php if ( $q->service_address ): ?><p style="margin:0;color:#64748b;"><?php echo esc_html($q->service_address); ?></p><?php endif; ?>
                </div>

                <?php if ( $q->customer_message ): ?>
                    <div style="background:#f0f6fc;border-left:4px solid #2563eb;padding:14px;margin:20px 0;">
                        <?php echo wp_kses_post( wpautop($q->customer_message) ); ?>
                    </div>
                <?php endif; ?>

                <table style="width:100%;border-collapse:collapse;margin:20px 0;">
                    <thead>
                        <tr style="background:#f1f5f9;">
                            <th style="text-align:left;padding:10px;"><?php esc_html_e('Description','titan-zero-trades-front-desk'); ?></th>
                            <th style="text-align:right;padding:10px;"><?php esc_html_e('Qty','titan-zero-trades-front-desk'); ?></th>
                            <th style="text-align:right;padding:10px;"><?php esc_html_e('Unit','titan-zero-trades-front-desk'); ?></th>
                            <th style="text-align:right;padding:10px;"><?php esc_html_e('Total','titan-zero-trades-front-desk'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $items as $i ): ?>
                        <tr style="border-bottom:1px solid #e5e7eb;">
                            <td style="padding:10px;">
                                <strong><?php echo esc_html($i->label); ?></strong>
                                <?php if ( $i->description ): ?><br /><small style="color:#64748b;"><?php echo esc_html($i->description); ?></small><?php endif; ?>
                            </td>
                            <td style="text-align:right;padding:10px;"><?php echo esc_html( rtrim(rtrim(number_format(floatval($i->qty),2),'0'),'.') ); ?></td>
                            <td style="text-align:right;padding:10px;"><?php echo esc_html( TZFD_Quote_Screens::money( floatval($i->unit_price) ) ); ?></td>
                            <td style="text-align:right;padding:10px;font-weight:bold;"><?php echo esc_html( TZFD_Quote_Screens::money( floatval($i->qty)*floatval($i->unit_price) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" style="text-align:right;padding:8px;"><?php esc_html_e('Subtotal','titan-zero-trades-front-desk'); ?></td><td style="text-align:right;padding:8px;"><?php echo esc_html(TZFD_Quote_Screens::money( floatval($q->subtotal) )); ?></td></tr>
                        <?php if ( $q->discount > 0 ): ?>
                            <tr><td colspan="3" style="text-align:right;padding:8px;"><?php esc_html_e('Discount','titan-zero-trades-front-desk'); ?></td><td style="text-align:right;padding:8px;">−<?php echo esc_html(TZFD_Quote_Screens::money( floatval($q->discount) )); ?></td></tr>
                        <?php endif; ?>
                        <?php if ( $q->tax_amount > 0 ): ?>
                            <tr><td colspan="3" style="text-align:right;padding:8px;"><?php esc_html_e('Tax','titan-zero-trades-front-desk'); ?> (<?php echo esc_html($q->tax_rate); ?>%)</td><td style="text-align:right;padding:8px;"><?php echo esc_html(TZFD_Quote_Screens::money( floatval($q->tax_amount) )); ?></td></tr>
                        <?php endif; ?>
                        <tr style="background:#0f172a;color:#fff;">
                            <td colspan="3" style="text-align:right;padding:12px;font-weight:bold;"><?php esc_html_e('Total','titan-zero-trades-front-desk'); ?></td>
                            <td style="text-align:right;padding:12px;font-weight:bold;font-size:18px;"><?php echo esc_html(TZFD_Quote_Screens::money( floatval($q->total) )); ?></td>
                        </tr>
                        <?php if ( $q->deposit > 0 ): ?>
                            <tr><td colspan="3" style="text-align:right;padding:8px;color:#64748b;"><?php esc_html_e('Deposit due on approval','titan-zero-trades-front-desk'); ?></td><td style="text-align:right;padding:8px;color:#64748b;"><?php echo esc_html(TZFD_Quote_Screens::money( floatval($q->deposit) )); ?></td></tr>
                        <?php endif; ?>
                    </tfoot>
                </table>

                <?php if ( $q->valid_until ): ?>
                    <p style="color:#64748b;"><?php /* translators: %s: date until which the quote is valid. */ printf( esc_html__('Valid until %s', 'titan-zero-trades-front-desk'), esc_html( mysql2date('j M Y', $q->valid_until) ) ); ?></p>
                <?php endif; ?>

                <?php if ( $q->status === 'approved' ): ?>
                    <div style="background:#d1fae5;border-left:4px solid #065f46;padding:14px;margin:20px 0;">
                        <strong><?php esc_html_e('Approved — thank you!', 'titan-zero-trades-front-desk'); ?></strong>
                        <p style="margin:6px 0 0;"><?php esc_html_e('We will be in touch shortly to schedule the work.', 'titan-zero-trades-front-desk'); ?></p>
                    </div>
                <?php elseif ( $q->status === 'declined' ): ?>
                    <div style="background:#fee2e2;border-left:4px solid #991b1b;padding:14px;margin:20px 0;">
                        <strong><?php esc_html_e('This quote was declined.', 'titan-zero-trades-front-desk'); ?></strong>
                    </div>
                <?php elseif ( $is_expired ): ?>
                    <div style="background:#f3f4f6;border-left:4px solid #6b7280;padding:14px;margin:20px 0;">
                        <strong><?php esc_html_e('This quote has expired.', 'titan-zero-trades-front-desk'); ?></strong>
                    </div>
                <?php else: ?>
                    <div id="tzfd-quote-actions" style="border-top:1px solid #e5e7eb;padding-top:20px;margin-top:20px;">
                        <h3><?php esc_html_e('Approve this quote','titan-zero-trades-front-desk'); ?></h3>
                        <form id="tzfd-quote-approve-form">
                            <input type="hidden" name="token" value="<?php echo esc_attr($q->token); ?>" />
                            <p><input type="text" name="signer_name" required placeholder="<?php esc_attr_e('Your full name','titan-zero-trades-front-desk'); ?>" style="width:100%;max-width:400px;padding:8px;" /></p>
                            <p><input type="email" name="signer_email" required value="<?php echo esc_attr($q->customer_email); ?>" placeholder="<?php esc_attr_e('Your email','titan-zero-trades-front-desk'); ?>" style="width:100%;max-width:400px;padding:8px;" /></p>
                            <p>
                                <label><input type="checkbox" name="terms" value="1" required />
                                    <?php echo wp_kses_post( $terms ?: __('I accept the estimate and terms above.', 'titan-zero-trades-front-desk') ); ?></label>
                            </p>
                            <p>
                                <button type="submit" class="button-primary" style="background:#065f46;color:#fff;border:0;padding:12px 24px;border-radius:6px;font-size:16px;cursor:pointer;">
                                    <?php esc_html_e('Approve & sign','titan-zero-trades-front-desk'); ?>
                                </button>
                                <button type="button" id="tzfd-quote-decline" style="margin-left:8px;background:none;border:1px solid #991b1b;color:#991b1b;padding:11px 20px;border-radius:6px;cursor:pointer;">
                                    <?php esc_html_e('Decline','titan-zero-trades-front-desk'); ?>
                                </button>
                            </p>
                        </form>
                        <div id="tzfd-quote-result"></div>
                    </div>
                <?php endif; ?>

                <p style="color:#94a3b8;font-size:12px;margin-top:30px;">
                    <?php esc_html_e('Reference', 'titan-zero-trades-front-desk'); ?>: <?php echo esc_html($q->reference); ?>
                </p>
            </div>

            <?php
            return ob_get_clean();
        }

        public function shortcode_quote_button( $atts ) {
            $atts = shortcode_atts( array(
                'label' => __('Get a quote', 'titan-zero-trades-front-desk'),
                'class' => '',
                'service_type' => '',
            ), $atts );
            $page = get_option('tzfd_quotes_page', '');
            $url  = $page ? get_permalink( intval($page) ) : home_url('/');
            $url  = add_query_arg( 'tzfd_new_quote', '1', $url );
            if ( $atts['service_type'] ) $url = add_query_arg( 'svc', rawurlencode($atts['service_type']), $url );
            return sprintf( '<a class="tzfd-quote-button %s" href="%s">%s</a>',
                esc_attr($atts['class']), esc_url($url), esc_html($atts['label']) );
        }

        /* ============================================================
         *  REST endpoints
         * ============================================================ */

        public function register_routes() {
            register_rest_route( 'tzfd/v1', '/quotes/approve', array(
                'methods'             => 'POST',
                'permission_callback' => array( 'TZFD_Quote', 'rest_token_permission' ),
                'callback'            => array( &$this, 'rest_approve' ),
            ) );
            register_rest_route( 'tzfd/v1', '/quotes/decline', array(
                'methods'             => 'POST',
                'permission_callback' => array( 'TZFD_Quote', 'rest_token_permission' ),
                'callback'            => array( &$this, 'rest_decline' ),
            ) );
        }

        public function rest_approve( WP_REST_Request $req ) {
            $token = sanitize_text_field( TZFD_Quote::request_text( $req, 'token' ) );
            $name  = sanitize_text_field( TZFD_Quote::request_text( $req, 'name' ) );
            $email = sanitize_email( TZFD_Quote::request_text( $req, 'email' ) );
            $terms = absint( $req->get_param('terms') );
            $q = TZFD_Quote::get_by_token( $token );
            if ( ! $q ) return new WP_REST_Response( array('ok'=>false,'message'=>__( 'Quote not found.', 'titan-zero-trades-front-desk' )), 404 );

            // Only a sent quote still within its valid-until date can be approved. Anything else gets a clear reason.
            $refusal = TZFD_Quote::approval_refusal( $q );
            if ( '' !== $refusal ) {
                return new WP_REST_Response( array('ok'=>false,'message'=>TZFD_Quote::refusal_message( $refusal )), 409 );
            }

            if ( trim( $name ) === '' || ! $terms ) {
                return new WP_REST_Response( array('ok'=>false,'message'=>__( 'Please enter your name and accept the terms.', 'titan-zero-trades-front-desk' )), 400 );
            }

            $ok = TZFD_Quote::approve( $q->id, array(
                'name'  => $name,
                'email' => $email,
                'data'  => '',
                'terms' => $terms,
            ) );
            if ( ! $ok ) return new WP_REST_Response( array('ok'=>false,'message'=>__( 'This quote can no longer be approved.', 'titan-zero-trades-front-desk' )), 409 );

            // Notify admin + customer
            $admin = get_option('admin_email');
            wp_mail( $admin, sprintf('[Quote approved] %s — %s', $q->reference, $q->customer_name ?: $q->customer_email),
                sprintf("Reference: %s\nTotal: %s\nApproved by: %s <%s>",
                    $q->reference, TZFD_Quote_Screens::money( $q->total ),
                    $name, $email ) );

            if ( $q->customer_email ) {
                wp_mail( $q->customer_email,
                    sprintf('[%s] Quote %s approved', get_bloginfo('name'), $q->reference),
                    sprintf("Thanks %s — we've received your approval for %s.\n\nTotal: %s\n\nWe'll be in touch shortly to schedule the work.",
                        $name, $q->reference, TZFD_Quote_Screens::money( $q->total ) ) );
            }

            return new WP_REST_Response( array('ok'=>true), 200 );
        }

        public function rest_decline( WP_REST_Request $req ) {
            $token  = sanitize_text_field( TZFD_Quote::request_text( $req, 'token' ) );
            $reason = sanitize_textarea_field( TZFD_Quote::request_text( $req, 'reason' ) );
            $q = TZFD_Quote::get_by_token( $token );
            if ( ! $q ) return new WP_REST_Response( array('ok'=>false), 404 );
            if ( ! TZFD_Quote::decline( $q->id, $reason ) ) {
                return new WP_REST_Response( array('ok'=>false,'message'=>__( 'This quote can no longer be declined.', 'titan-zero-trades-front-desk' )), 409 );
            }
            return new WP_REST_Response( array('ok'=>true), 200 );
        }

        /* ============================================================
         *  Cron — expire quotes, nudge unviewed
         * ============================================================ */

        public function cron() {
            global $wpdb;

            // Expire
            $wpdb->query( $wpdb->prepare(
                "UPDATE %i
                 SET status='expired'
                 WHERE status='sent' AND valid_until IS NOT NULL AND valid_until < %s",
                $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES, current_time('Y-m-d') ) );

            // Nudge unviewed quotes 48h after sending
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE status='sent'
                   AND sent_at < %s
                   AND viewed_at IS NULL
                   AND nudged_at IS NULL
                   AND customer_email <> ''
                 LIMIT 20",
                $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES, wp_date('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS) ) );
            foreach ( $rows as $q ) {
                wp_mail( $q->customer_email,
                    sprintf('Quick follow-up on your estimate %s', $q->reference),
                    sprintf("Hi%s — just checking you received the estimate for your %s job.\n\nReview: %s",
                        $q->customer_name ? ' '.$q->customer_name : '',
                        $q->service_type,
                        $this->public_url( $q->token ) ) );
                $wpdb->update( $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES, array( 'nudged_at' => current_time('mysql') ), array( 'id' => $q->id ) );
            }
        }
    }

    $TZFD_QuotesAddon_obj = new TZFD_QuotesAddon();
    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $TZFD_QuotesAddon_obj->get_addon_id() ] = $TZFD_QuotesAddon_obj;
}
