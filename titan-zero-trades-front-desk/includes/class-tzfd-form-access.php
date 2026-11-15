<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Company-scoped access to form rows in tz_forms (19 Oct round 10).
 *
 * Every read of a form row by id goes through here. The company comes from the row itself (one column is read
 * first), and the full row is then read only when it belongs to that company. A caller that names a company must
 * match the row's company, otherwise the read returns null. Company 0 never matches.
 *
 * Note: a public booking form is shown to anyone who has its id, by design. These helpers stop a read from
 * crossing into another company's data. They cannot stop a visitor from viewing that company's public form.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Form_Access {

    /** The form row, scoped to the company the row itself names. Null when unknown or the company is 0. */
    public static function row( $formid ) {
        $company = TZFD_Scoped_Store::company_for_form( $formid );
        return self::row_for_company( $formid, $company );
    }

    /**
     * The form row, only when it belongs to $company. Null when $company is 0 or the row is not that company's.
     * Used where the caller already knows the company (for example a booking's company).
     */
    public static function row_for_company( $formid, $company ) {
        global $wpdb;
        $company = absint( $company );
        if ( $company < 1 || intval( $formid ) < 1 || empty( $wpdb ) ) { return null; }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND company_id = %d',
            $wpdb->prefix . TZFD_Tables::FORMS, intval( $formid ), $company
        ) );
        return ( is_array( $rows ) && isset( $rows[0] ) ) ? $rows[0] : null;
    }
}
