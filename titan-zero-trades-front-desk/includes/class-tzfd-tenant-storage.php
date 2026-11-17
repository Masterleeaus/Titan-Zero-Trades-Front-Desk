<?php
/**
 * Tenant-scoped key/value storage seam for the Free AI foundation (#42).
 *
 * Production reads and writes go through TZFD_Scoped_Store, so each company's values live in its own option
 * (tzfd_c{company}_{key}). Tests inject an in-memory implementation of the same interface.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface TZFD_Tenant_Storage {
    /** Returns the company's stored value, or $default when none exists or the company is unknown. */
    public function read( $key, $company, $default );

    /** Stores the company's value. Returns false when the company is unknown or the write fails. */
    public function write( $key, $company, $value );
}

/** Production storage: delegates to TZFD_Scoped_Store. */
final class TZFD_Scoped_Storage implements TZFD_Tenant_Storage {

    public function read( $key, $company, $default ) {
        if ( ! class_exists( 'TZFD_Scoped_Store', false ) ) { return $default; }
        return TZFD_Scoped_Store::get( $key, $default, (int) $company );
    }

    public function write( $key, $company, $value ) {
        if ( ! class_exists( 'TZFD_Scoped_Store', false ) ) { return false; }
        return (bool) TZFD_Scoped_Store::update( $key, $value, (int) $company );
    }
}
