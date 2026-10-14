<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned quote tables; caching would serve stale quote data. Table names are bound with %i.
/**
 * Quote PDF Export
 *
 * Renders a print-optimized HTML view of a quote (which every browser can
 * save to PDF) and, if a PDF engine is already present in the WordPress
 * install (Dompdf, mPDF, TCPDF — shipped by many other plugins), produces a
 * real PDF attachment for the customer email.
 *
 * This addon does NOT download, install, or bundle any PDF library. It
 * detects what is already available and exposes a filter for site owners to
 * plug in their own engine.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once dirname( __DIR__ ) . '/core/base.addon.php';

if ( ! class_exists( 'TZFD_Quote_PDF' ) ) {

    class TZFD_Quote_PDF extends TZFD_BaseAddon
    {
        protected $addonID = "tzfd-QuotePDF";
        protected $name = "Quote PDF Export";
        protected $description;
        public $category = ' Quote PDF summaries (included in this plugin version)';
        public $help = '';

        function __construct() {
            $this->description = $this->tr_apply(
                "Renders a branded, print-optimized quote page (Save as PDF from any browser) and attaches a real PDF to customer emails when a PDF engine is available in your WordPress install.",
                'titan-zero-trades-front-desk'
            );
            if ( ! $this->addon_is_active() ) return;

            add_shortcode( 'tzfd_quote_print', array( &$this, 'shortcode_print' ) );
            add_action( 'rest_api_init',          array( &$this, 'register_routes' ) );
            add_action( 'tzfd_quote_sent',      array( &$this, 'attach_to_email' ), 10, 2 );
            add_action( 'admin_init',             array( &$this, 'handle_settings' ) );
        }

        /* ============================================================
         *  Engine detection
         * ============================================================ */

        /**
         * Return an identifier for whichever PDF engine is already present
         * in this WordPress install. Returns null if none.
         *
         * Allowed engines are ones already loaded by other plugins (or the
         * site owner's own mu-plugins). We never ship or download one.
         */
        public static function detect_engine() {
            $preferred = get_option('tzfd_quotes_pdf_engine', 'auto');
            if ( $preferred === 'none' ) return null;
            if ( $preferred !== 'auto' && self::engine_exists( $preferred ) ) return $preferred;
            foreach ( array('dompdf','mpdf','tcpdf') as $id ) {
                if ( self::engine_exists( $id ) ) return $id;
            }
            return null;
        }

        private static function engine_exists( $id ) {
            switch ( $id ) {
                case 'dompdf':
                    return class_exists( 'Dompdf\\Dompdf' ) || class_exists( 'DOMPDF' );
                case 'mpdf':
                    return class_exists( 'Mpdf\\Mpdf' ) || class_exists( 'mPDF' );
                case 'tcpdf':
                    return class_exists( 'TCPDF' );
            }
            return false;
        }

        /**
         * Render a quote to PDF bytes.
         *
         * @return array{ ok:bool, bytes:string, error:string, engine:string }
         */
        public static function render_pdf( $quote_id ) {
            $q = TZFD_Quote::get( $quote_id );
            if ( ! $q ) return array( 'ok'=>false, 'bytes'=>'', 'error'=>'quote_not_found', 'engine'=>'' );

            // Allow the site owner to fully replace rendering
            $override = apply_filters( 'tzfd_quotes_pdf_bytes', null, $q, self::detect_engine() );
            if ( is_string($override) && strncmp( $override, '%PDF-', 5 ) === 0 ) {
                return array( 'ok'=>true, 'bytes'=>$override, 'error'=>'', 'engine'=>'filter' );
            }

            $engine = self::detect_engine();
            $html   = self::render_html( $quote_id, true );

            switch ( $engine ) {
                case 'dompdf':
                    try {
                        $cls = class_exists('Dompdf\\Dompdf') ? 'Dompdf\\Dompdf' : 'DOMPDF';
                        // Remote loading stays off: the logo is a data URI and the document links no outside file.
                        $pdf = new $cls( array( 'isRemoteEnabled'=>false, 'isHtml5ParserEnabled'=>true ) );
                        if ( ! method_exists( $pdf, 'getCss' ) ) {
                            // Older Dompdf builds cannot take a stylesheet through the API. Fall back to the print view rather than send an unstyled PDF.
                            return array( 'ok'=>false, 'bytes'=>'', 'error'=>'dompdf_css_api_missing', 'engine'=>'dompdf' );
                        }
                        $pdf->loadHtml( $html );
                        // The quote stylesheet is passed to Dompdf's own stylesheet object, so the HTML needs no style element.
                        $css = self::print_css();
                        $pdf->getCss()->load_css( $css );
                        $pdf->setPaper( 'A4', 'portrait' );
                        $pdf->render();
                        return array( 'ok'=>true, 'bytes'=>$pdf->output(), 'error'=>'', 'engine'=>'dompdf' );
                    } catch ( \Throwable $e ) {
                        return array( 'ok'=>false, 'bytes'=>'', 'error'=>$e->getMessage(), 'engine'=>'dompdf' );
                    }

                case 'mpdf':
                    try {
                        $cls = class_exists('Mpdf\\Mpdf') ? 'Mpdf\\Mpdf' : 'mPDF';
                        $pdf = new $cls( array( 'format'=>'A4', 'margin_top'=>18, 'margin_bottom'=>18, 'margin_left'=>16, 'margin_right'=>16 ) );
                        $pdf->WriteHTML( $html );
                        return array( 'ok'=>true, 'bytes'=>$pdf->Output('', 'S'), 'error'=>'', 'engine'=>'mpdf' );
                    } catch ( \Throwable $e ) {
                        return array( 'ok'=>false, 'bytes'=>'', 'error'=>$e->getMessage(), 'engine'=>'mpdf' );
                    }

                case 'tcpdf':
                    try {
                        $pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
                        $pdf->SetPrintHeader( false );
                        $pdf->SetPrintFooter( true );
                        $pdf->SetMargins( 16, 18, 16 );
                        $pdf->AddPage();
                        $pdf->writeHTML( $html, true, false, true, false, '' );
                        return array( 'ok'=>true, 'bytes'=>$pdf->Output('', 'S'), 'error'=>'', 'engine'=>'tcpdf' );
                    } catch ( \Throwable $e ) {
                        return array( 'ok'=>false, 'bytes'=>'', 'error'=>$e->getMessage(), 'engine'=>'tcpdf' );
                    }

            }

            return array( 'ok'=>false, 'bytes'=>'', 'error'=>'no_engine', 'engine'=>'' );
        }

        /* ============================================================
         *  HTML template
         * ============================================================ */

        /**
         * Stylesheet for the quote, read from css/quote-pdf.css. Used by the browser print view (through
         * wp_print_styles) and by the PDF engine (through Dompdf's stylesheet object).
         */
        public static function print_css() {
            $css = file_get_contents( TZFD_Brand::path( 'css/quote-pdf.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
            return false === $css ? '' : rtrim( $css );
        }

        /**
         * The logo as a data URI for the PDF engine, which does not fetch remote files. Only an image in this
         * site's uploads folder is embedded. Anything else is left out, so the PDF still renders without a logo.
         */
        private static function logo_data_uri( $url ) {
            $url     = (string) $url;
            $uploads = wp_upload_dir( null, false );
            if ( '' === $url || ! empty( $uploads['error'] ) || 0 !== strpos( $url, $uploads['baseurl'] . '/' ) ) {
                return '';
            }
            $base = realpath( $uploads['basedir'] );
            $file = realpath( $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) ) );
            if ( ! $base || ! $file || 0 !== strpos( $file, $base . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || filesize( $file ) > 2 * 1024 * 1024 ) {
                return '';
            }
            $info = wp_getimagesize( $file );
            $mime = is_array( $info ) && isset( $info['mime'] ) ? $info['mime'] : '';
            if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
                return '';
            }
            $bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local image in the uploads folder, read to embed it.
            if ( false === $bytes ) {
                return '';
            }
            // A data URI for an image, so the PDF needs no remote fetch.
            return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Image data URI, not obfuscated code.
        }

        public static function render_html( $quote_id, $for_pdf = false ) {
            $q = TZFD_Quote::get( $quote_id );
            if ( ! $q ) return '';
            $items = TZFD_Quote::items( $quote_id );
            $terms = get_option('tzfd_quotes_terms', '');
            $logo  = get_option('tzfd_quotes_pdf_logo', '');
            $logo_src = $for_pdf ? self::logo_data_uri( $logo ) : (string) $logo;

            $site  = get_bloginfo('name');
            $addr  = get_option('tzfd_business_profile', array());
            $cur   = $q->currency;
            // Declined and expired quotes get no signature block. A sent quote past its valid-until date counts as expired.
            $closed = in_array( TZFD_Quote::approval_refusal( $q ), array( 'declined', 'expired' ), true );

            ob_start();
            ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title><?php echo esc_html( $q->reference ); ?> — <?php echo esc_html( $site ); ?></title>
</head>
<body>

<?php if ( ! $for_pdf ): ?>
<div class="printbar no-print">
    <span><strong><?php echo esc_html( $q->reference ); ?></strong> — <?php echo esc_html( $q->customer_name ); ?></span>
    <span>
        <a class="ghost" href="<?php echo esc_url( TZFD_Quote::public_url( $q->token ) ); ?>">← Back to quote</a>
        <button type="button" data-tzfd-print>📄 Save as PDF / Print</button>
    </span>
</div>
<?php endif; ?>

<div class="page">

    <div class="head">
        <div>
            <?php if ( $logo_src ): ?><img class="logo" src="<?php echo $for_pdf ? esc_attr( $logo_src ) : esc_url( $logo_src ); ?>" alt="" /><?php endif; ?>
            <h1><?php echo esc_html( $addr['name'] ?? $site ); ?></h1>
            <?php if ( ! empty($addr['area']) ): ?>
                <div style="font-size:9pt;color:#64748b;"><?php echo esc_html( str_replace("\n", ' · ', trim($addr['area']) ) ); ?></div>
            <?php endif; ?>
        </div>
        <div class="meta">
            <strong>QUOTE</strong>
            <?php echo esc_html( $q->reference ); ?><br />
            <?php echo esc_html( mysql2date('j F Y', $q->created) ); ?><br />
            <?php if ( $q->valid_until ): ?>Valid until <?php echo esc_html( mysql2date('j F Y', $q->valid_until) ); ?><?php endif; ?>
        </div>
    </div>

    <div class="parties">
        <div class="block">
            <h3>Prepared for</h3>
            <p>
                <strong><?php echo esc_html( $q->customer_name ?: '—' ); ?></strong><br />
                <?php if ( $q->service_address ): ?><?php echo esc_html( $q->service_address ); ?><br /><?php endif; ?>
                <?php if ( $q->customer_email ): ?><?php echo esc_html( $q->customer_email ); ?><br /><?php endif; ?>
                <?php if ( $q->customer_phone ): ?><?php echo esc_html( $q->customer_phone ); ?><?php endif; ?>
            </p>
        </div>
        <div class="block">
            <h3>Job</h3>
            <p>
                <strong><?php echo esc_html( $q->service_type ?: 'Service' ); ?></strong><br />
                <?php if ( $q->urgency && $q->urgency !== 'routine' ): ?>
                    <span style="color:#991b1b;"><?php echo esc_html( ucfirst($q->urgency) ); ?> priority</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ( $q->customer_message ): ?>
        <div class="intro"><?php echo wp_kses_post( wpautop( $q->customer_message ) ); ?></div>
    <?php endif; ?>

    <table class="items">
        <thead>
            <tr>
                <th style="width:60%;">Description</th>
                <th class="num" style="width:10%;">Qty</th>
                <th class="num" style="width:15%;">Unit</th>
                <th class="num" style="width:15%;">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $items as $i ): ?>
            <tr class="avoid-break">
                <td>
                    <strong><?php echo esc_html( $i->label ); ?></strong>
                    <?php if ( $i->description ): ?>
                        <div class="item-desc"><?php echo esc_html( $i->description ); ?></div>
                    <?php endif; ?>
                </td>
                <td class="num"><?php echo esc_html( rtrim(rtrim(number_format(floatval($i->qty),2),'0'),'.') ); ?> <?php echo esc_html($i->unit); ?></td>
                <td class="num"><?php echo esc_html( $cur.' '.number_format(floatval($i->unit_price),2) ); ?></td>
                <td class="num"><strong><?php echo esc_html( $cur.' '.number_format(floatval($i->qty)*floatval($i->unit_price),2) ); ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="subtotal"><td colspan="3" class="num">Subtotal</td><td class="num"><?php echo esc_html( $cur.' '.number_format(floatval($q->subtotal),2) ); ?></td></tr>
            <?php if ( $q->discount > 0 ): ?>
                <tr class="discount"><td colspan="3" class="num">Discount</td><td class="num">−<?php echo esc_html( $cur.' '.number_format(floatval($q->discount),2) ); ?></td></tr>
            <?php endif; ?>
            <?php if ( $q->tax_amount > 0 ): ?>
                <tr class="tax"><td colspan="3" class="num">Tax (<?php echo esc_html($q->tax_rate); ?>%)</td><td class="num"><?php echo esc_html( $cur.' '.number_format(floatval($q->tax_amount),2) ); ?></td></tr>
            <?php endif; ?>
            <tr class="total"><td colspan="3" class="num">Total</td><td class="num"><?php echo esc_html( $cur.' '.number_format(floatval($q->total),2) ); ?></td></tr>
            <?php if ( $q->deposit > 0 ): ?>
                <tr class="tax"><td colspan="3" class="num" style="font-weight:bold;">Deposit due on approval</td><td class="num" style="font-weight:bold;"><?php echo esc_html( $cur.' '.number_format(floatval($q->deposit),2) ); ?></td></tr>
            <?php endif; ?>
        </tfoot>
    </table>

    <?php if ( $terms ): ?>
        <div class="terms avoid-break">
            <h4>Terms &amp; Conditions</h4>
            <?php echo wp_kses_post( wpautop( $terms ) ); ?>
        </div>
    <?php endif; ?>

    <?php if ( $q->status === 'approved' ): ?>
        <div class="signature avoid-break">
            <h4 style="margin:0 0 6px;">Approved</h4>
            <p style="margin:0;font-size:10pt;color:#64748b;">
                Approved by <strong><?php echo esc_html( $q->customer_name ); ?></strong>
                on <?php echo esc_html( mysql2date('j F Y \a\t H:i', $q->approved_at) ); ?>.
            </p>
            <div class="sig-grid">
                <div>
                    <div class="sig-box" style="border:none;padding-top:20px;">
                        <span class="signed"><?php echo esc_html( $q->customer_name ); ?></span>
                    </div>
                    <div class="sig-label">Customer signature</div>
                </div>
                <div>
                    <div class="sig-box" style="border:none;padding-top:20px;">
                        <span class="signed" style="font-family:inherit;font-size:14pt;color:#64748b;">On file</span>
                    </div>
                    <div class="sig-label">Authorized representative</div>
                </div>
            </div>
        </div>
    <?php elseif ( ! $closed ): ?>
        <div class="signature avoid-break">
            <h4 style="margin:0 0 6px;">Approval</h4>
            <p style="margin:0;font-size:10pt;color:#64748b;">
                To approve this quote, sign below or approve online at
                <strong><?php echo esc_html( TZFD_Quote::public_url( $q->token ) ); ?></strong>
            </p>
            <div class="sig-grid">
                <div>
                    <div class="sig-box"></div>
                    <div class="sig-label">Customer signature</div>
                </div>
                <div>
                    <div class="sig-box"></div>
                    <div class="sig-label">Date</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="footer">
        <?php echo esc_html( $addr['name'] ?? $site ); ?>
        <?php if ( ! empty($addr['emergency']) ): ?> · <?php echo esc_html( $addr['emergency'] ); ?><?php endif; ?>
        · <?php echo esc_html( $q->reference ); ?>
        · Generated <?php echo esc_html( mysql2date('j M Y', current_time('mysql')) ); ?>
    </div>

</div>
</body>
</html>
            <?php
            return ob_get_clean();
        }

        /* ============================================================
         *  Shortcode + REST
         * ============================================================ */

        public function shortcode_print() {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public quote link: the unguessable token is the authentication, and this read changes no state.
            $token = sanitize_text_field( wp_unslash( $_GET['tzfd_quote'] ?? '' ) );
            if ( ! $token ) return '<p>' . esc_html__('No quote specified.', 'titan-zero-trades-front-desk') . '</p>';
            $q = TZFD_Quote::get_by_token( $token );
            if ( ! $q ) return '<p>' . esc_html__('Quote not found.', 'titan-zero-trades-front-desk') . '</p>';
            $html = self::render_html( $q->id, false );

            // The print view is a complete page of its own, so its stylesheet is printed here, not from the theme head.
            wp_register_style( 'tzfd-quote-print', false, array(), '2.0.1' );
            wp_add_inline_style( 'tzfd-quote-print', self::print_css() );
            ob_start();
            wp_print_styles( 'tzfd-quote-print' );
            $styles = ob_get_clean();
            wp_enqueue_script( 'tzfd-quote-print', TZFD_Brand::url( 'js/inline/quotes-print.js' ), array(), '2.0.1', true );

            return $styles . $html;
        }

        public function register_routes() {
            register_rest_route( 'tzfd/v1', '/quotes/pdf/(?P<token>[a-zA-Z0-9]+)', array(
                'methods'             => 'GET',
                'permission_callback' => array( 'TZFD_Quote', 'rest_token_permission' ),
                'callback'            => array( &$this, 'rest_pdf' ),
            ) );
        }

        public function rest_pdf( WP_REST_Request $req ) {
            $q = TZFD_Quote::get_by_token( sanitize_text_field( TZFD_Quote::request_text( $req, 'token' ) ) );
            if ( ! $q ) return new WP_REST_Response( 'Not found', 404 );

            $res = self::render_pdf( $q->id );
            if ( $res['ok'] && $res['bytes'] ) {
                $resp = new WP_REST_Response( $res['bytes'], 200 );
                $resp->header( 'Content-Type', 'application/pdf' );
                $resp->header( 'Content-Disposition', 'inline; filename="quote-'.sanitize_file_name($q->reference).'.pdf"' );
                return $resp;
            }
            // No PDF engine — send the print view (browser can save as PDF)
            wp_safe_redirect( TZFD_Quote::public_url( $q->token ) );
            exit;
        }

        /* ============================================================
         *  Email attachment + embed
         * ============================================================ */

        /**
         * Hooks `tzfd_quote_sent` (fired by the quotes addon when a quote
         * is emailed). Attaches a PDF if an engine is available.
         */
        public function attach_to_email( $quote_id, $to_email ) {
            if ( ! $to_email ) return;
            $q = TZFD_Quote::get( $quote_id );
            if ( ! $q ) return;

            $res = self::render_pdf( $quote_id );
            if ( ! $res['ok'] || ! $res['bytes'] ) return;

            $tmp = wp_tempnam( 'quote-'.sanitize_file_name( $q->reference ).'.pdf' );
            if ( ! $tmp || false === file_put_contents( $tmp, $res['bytes'] ) ) return;
            // Remove the temp file once the request (and the send) has finished.
            register_shutdown_function( function() use ( $tmp ) { if ( file_exists( $tmp ) ) wp_delete_file( $tmp ); } );

            // Attach only to the email addressed to this customer, then unhook so
            // later mail (admin alerts, other customers) is never affected.
            $handler = function( $args ) use ( $tmp, $to_email, &$handler ) {
                $to = is_array( $args['to'] ?? null ) ? implode( ',', $args['to'] ) : (string) ( $args['to'] ?? '' );
                if ( stripos( $to, $to_email ) !== false ) {
                    $args['attachments'] = array_merge( (array) ( $args['attachments'] ?? array() ), array( $tmp ) );
                    remove_filter( 'wp_mail', $handler, 99 );
                }
                return $args;
            };
            add_filter( 'wp_mail', $handler, 99 );
        }

        /* ============================================================
         *  Admin settings
         * ============================================================ */

        public function handle_settings() {
            if ( ! isset($_POST['tzfd_quotes_pdf_nonce']) ) return;
            if ( ! current_user_can('manage_options') ) return;
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tzfd_quotes_pdf_nonce'] ) ), 'tzfd_quotes_pdf_save' ) ) return;

            $engine = sanitize_key( isset( $_POST['pdf_engine'] ) && is_string( $_POST['pdf_engine'] ) ? wp_unslash( $_POST['pdf_engine'] ) : 'auto' );
            if ( ! in_array( $engine, array( 'auto', 'dompdf', 'mpdf', 'tcpdf', 'none' ), true ) ) {
                $engine = 'auto';
            }
            update_option( 'tzfd_quotes_pdf_engine', $engine );
            // (string) keeps a posted array from reaching esc_url_raw() as an array.
            update_option( 'tzfd_quotes_pdf_logo', esc_url_raw( (string) wp_unslash( $_POST['pdf_logo'] ?? '' ) ) );

            wp_safe_redirect( add_query_arg('pdf_saved','1', wp_get_referer() ) );
            exit;
        }

        public function get_addon_settings() {
            $engine = self::detect_engine();
            $pref   = get_option('tzfd_quotes_pdf_engine','auto');
            ?>
            <div class="postbox">
                <h3 class="hndle" style="padding:5px;"><span><?php echo esc_html($this->name); ?></span></h3>
                <div class="inside">
                    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag that shows a notice after save; changes no state. ?>
                    <?php if ( isset($_GET['pdf_saved']) ): ?>
                        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.','titan-zero-trades-front-desk'); ?></p></div>
                    <?php endif; ?>

                    <p style="background:#f0f6fc;border-left:4px solid #2563eb;padding:12px;margin:0 0 16px;">
                        <strong><?php esc_html_e('Engine status:','titan-zero-trades-front-desk'); ?></strong>
                        <?php if ( $engine ): ?>
                            <span style="color:#065f46;"><?php echo esc_html( strtoupper($engine) ); ?> <?php esc_html_e('detected','titan-zero-trades-front-desk'); ?></span> —
                            <?php esc_html_e('PDFs will be attached to customer emails.','titan-zero-trades-front-desk'); ?>
                        <?php else: ?>
                            <span style="color:#92400e;"><?php esc_html_e('No PDF engine detected','titan-zero-trades-front-desk'); ?></span> —
                            <?php esc_html_e('Customers will get a print-optimized web view they can save as PDF from any browser. That is fully functional; a server-side engine is optional.','titan-zero-trades-front-desk'); ?>
                        <?php endif; ?>
                    </p>

                    <form method="post">
                        <?php wp_nonce_field('tzfd_quotes_pdf_save','tzfd_quotes_pdf_nonce'); ?>
                        <table class="form-table">
                            <tr><th><?php esc_html_e('PDF engine','titan-zero-trades-front-desk'); ?></th>
                                <td>
                                    <select name="pdf_engine">
                                        <option value="auto" <?php selected($pref,'auto'); ?>>Auto-detect (recommended)</option>
                                        <option value="dompdf" <?php selected($pref,'dompdf'); ?> <?php disabled( ! self::engine_exists('dompdf') ); ?>>Dompdf <?php if(!self::engine_exists('dompdf')) echo '(not available)'; ?></option>
                                        <option value="mpdf" <?php selected($pref,'mpdf'); ?> <?php disabled( ! self::engine_exists('mpdf') ); ?>>mPDF <?php if(!self::engine_exists('mpdf')) echo '(not available)'; ?></option>
                                        <option value="tcpdf" <?php selected($pref,'tcpdf'); ?> <?php disabled( ! self::engine_exists('tcpdf') ); ?>>TCPDF <?php if(!self::engine_exists('tcpdf')) echo '(not available)'; ?></option>
                                        <option value="none" <?php selected($pref,'none'); ?>>None — print-optimized HTML only</option>
                                    </select>
                                    <p><em>
                                        <?php esc_html_e('This addon does not download or install a PDF engine. Auto-detect uses whichever library is already loaded by another plugin in this WordPress install. Site owners with a preferred engine can hook into', 'titan-zero-trades-front-desk'); ?>
                                        <code>tzfd_quotes_pdf_bytes</code>.
                                    </em></p>
                                </td></tr>

                            <tr><th><?php esc_html_e('Logo URL','titan-zero-trades-front-desk'); ?></th>
                                <td><input type="url" name="pdf_logo" size="50" value="<?php echo esc_attr( get_option('tzfd_quotes_pdf_logo','') ); ?>" />
                                    <p><em><?php esc_html_e('Shown at the top of the PDF. Leave blank for text-only header.','titan-zero-trades-front-desk'); ?></em></p></td></tr>
                        </table>
                        <p><button class="button button-primary"><?php esc_html_e('Save','titan-zero-trades-front-desk'); ?></button></p>
                    </form>

                    <hr />
                    <h4><?php esc_html_e('Test','titan-zero-trades-front-desk'); ?></h4>
                    <p><?php esc_html_e('Preview the print view of the most recent quote. From your browser you can choose "Save as PDF" in the print dialog.','titan-zero-trades-front-desk'); ?></p>
                    <?php
                    global $wpdb;
                    $last = $wpdb->get_row( $wpdb->prepare( "SELECT token FROM %i ORDER BY id DESC LIMIT 1", $wpdb->prefix.TZFD_Quotes_Engine::T_QUOTES ) );
                    if ( $last ):
                        $print_url = TZFD_Quote::public_url( $last->token );
                        $pdf_url   = rest_url( 'tzfd/v1/quotes/pdf/'.$last->token );
                    ?>
                        <p>
                            <a class="button" href="<?php echo esc_url($print_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open print view','titan-zero-trades-front-desk'); ?></a>
                            <a class="button" href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Try PDF endpoint','titan-zero-trades-front-desk'); ?></a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    $TZFD_Quote_PDF_obj = new TZFD_Quote_PDF();
    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $TZFD_Quote_PDF_obj->get_addon_id() ] = $TZFD_Quote_PDF_obj;
}
