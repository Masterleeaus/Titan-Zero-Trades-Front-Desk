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

/**
 * Optional second seam: an exclusive lock per company and key. A read-check-write of a versioned document is only
 * safe under concurrent requests when it runs inside this lock. Storage without it still works, but then two
 * simultaneous writers that hold the same version can both pass the version check.
 */
interface TZFD_Tenant_Lock {
    /** Returns true when the lock is taken, false when another request holds it. */
    public function acquire( $key, $company );

    /** Releases the lock. Call only after acquire() returned true, or it can drop another request's lock. */
    public function release( $key, $company );
}

/** Production storage: delegates to TZFD_Scoped_Store. Locks use add_option(), which is atomic for a new name. */
final class TZFD_Scoped_Storage implements TZFD_Tenant_Storage, TZFD_Tenant_Lock {

    const LOCK_PREFIX = 'tzfd_lock_';
    const LOCK_STALE  = 10; // seconds before a lock left by a crashed request may be taken over

    public function read( $key, $company, $default ) {
        if ( ! class_exists( 'TZFD_Scoped_Store', false ) ) { return $default; }
        return TZFD_Scoped_Store::get( $key, $default, (int) $company );
    }

    public function write( $key, $company, $value ) {
        if ( ! class_exists( 'TZFD_Scoped_Store', false ) ) { return false; }
        return (bool) TZFD_Scoped_Store::update( $key, $value, (int) $company );
    }

    public function acquire( $key, $company ) {
        $name = self::lock_name( $key, $company );
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            if ( add_option( $name, time(), '', 'no' ) ) { return true; }
            $taken = (int) get_option( $name, 0 );
            if ( $taken > 0 && time() - $taken <= self::LOCK_STALE ) { return false; }
            delete_option( $name ); // stale lock from a crashed request
        }
        return false;
    }

    public function release( $key, $company ) {
        delete_option( self::lock_name( $key, $company ) );
    }

    private static function lock_name( $key, $company ) {
        return self::LOCK_PREFIX . md5( (string) $key . '|' . (int) $company );
    }
}
