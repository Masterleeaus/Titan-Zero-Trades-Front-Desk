<?php
/** Company boundary for administrative dashboard and calendar database reads. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Company_Read_Context {
    public static function id() {
        if ( ! class_exists( 'TZFD_Scoped_Store' ) || ! is_callable( array( 'TZFD_Scoped_Store', 'company_id' ) ) ) { return 0; }
        $id = TZFD_Scoped_Store::company_id();
        return is_numeric( $id ) && (int) $id > 0 ? (int) $id : 0;
    }
}
