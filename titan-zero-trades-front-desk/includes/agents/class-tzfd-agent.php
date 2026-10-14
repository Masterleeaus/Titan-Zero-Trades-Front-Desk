<?php
/**
 * Base class for every agent. An agent has a name and one tier. Every action goes through act(),
 * which checks the tier's permissions before the agent's own handler runs.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent-tier.php';

abstract class TZFD_Agent {

    private $name;
    private $tier;

    public function __construct( $name, $tier ) {
        if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9\-]{1,39}$/', (string) $name ) ) {
            throw new InvalidArgumentException( 'Agent names are lower-case letters, digits and dashes.' );
        }
        if ( 0 === TZFD_Agent_Tier::rank( $tier ) ) {
            throw new InvalidArgumentException( 'Unknown agent tier.' );
        }
        $this->name = (string) $name;
        $this->tier = (string) $tier;
    }

    public function name() { return $this->name; }

    public function tier() { return $this->tier; }

    public function can( $action ) {
        return TZFD_Agent_Tier::permits( $this->tier, $action );
    }

    /**
     * Runs one action. Refused, and not run, when the tier does not permit it.
     * @return array  The handler's result, or ok false with error not_permitted.
     */
    public function act( $action, array $args = array() ) {
        if ( ! $this->can( $action ) ) {
            return array( 'ok' => false, 'error' => 'not_permitted', 'agent' => $this->name, 'action' => (string) $action );
        }
        return $this->handle( (string) $action, $args );
    }

    /** The agent's own work for a permitted action. */
    abstract protected function handle( $action, array $args );

    protected function fail( $code, array $extra = array() ) {
        return array_merge( array( 'ok' => false, 'error' => (string) $code ), $extra );
    }
}
