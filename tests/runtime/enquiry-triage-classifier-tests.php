<?php
/**
 * Fixture tests for TZFD_Enquiry_Triage::classify(). Pure PHP, no WordPress needed.
 * Run: php enquiry-triage-classifier-tests.php /path/to/includes/class-tzfd-enquiry-triage.php
 * Exits 1 on any failure.
 */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
$includes = $argv[1] ?? dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-enquiry-triage.php';
require_once $includes;

$pass = 0;
$fail = 0;
function tzfd_triage_check( $label, $ok, $detail = '' ) {
    global $pass, $fail;
    if ( $ok ) { $pass++; return; }
    $fail++;
    echo 'FAIL: ' . $label . ( '' === $detail ? '' : ' :: ' . $detail ) . "\n";
}

// Fixture rows: text, expected primary intent, tags that must be present, tags that must be absent.
$fixtures = array(
    // Basic routing for each intent.
    array( 'I would like to book an appointment for Tuesday', 'booking', array(), array() ),
    array( 'Please cancel my booking for tomorrow', 'cancel', array(), array() ),
    array( 'How much would it cost to clean a 3 bedroom house?', 'quote', array(), array() ),
    array( 'There was a complaint: the technician was rude and damaged my wall', 'complaint', array(), array() ),
    array( 'My tap is leaking under the sink, can you repair it?', 'service', array(), array() ),
    array( 'WIN BIG at our online casino, click here for free money', 'spam', array( 'possible_spam' ), array() ),
    array( 'Hello, what are your opening hours?', 'general', array( 'needs_review' ), array() ),
    // Overlapping intents: priority picks the primary, the rest become tags.
    array( 'Cancel my booking and rebook for Friday', 'cancel', array( 'booking', 'multi_intent' ), array() ),
    array( 'Can you fix the leak and tell me the price?', 'quote', array( 'service', 'multi_intent' ), array() ),
    array( 'I want to cancel, the service was terrible', 'complaint', array( 'cancel', 'multi_intent' ), array() ),
    array( 'Crypto returns guaranteed. Also book me in for Monday', 'booking', array( 'possible_spam' ), array() ),
    // Negation: a negator in the three words before a keyword cancels that keyword only.
    array( 'I do not want to cancel, just reschedule my visit', 'booking', array(), array( 'cancel' ) ),
    array( 'No quote needed, please book me in', 'booking', array(), array( 'quote' ) ),
    array( 'This is not a complaint, I just want a quote', 'quote', array(), array( 'complaint' ) ),
    array( 'No. Book me in for Thursday.', 'booking', array(), array() ),
    array( 'Ce n\'est pas une plainte, je voudrais un devis', 'quote', array(), array( 'complaint' ) ),
    // Multilingual keywords.
    array( '¿Puedo reservar una cita mañana?', 'booking', array(), array() ),
    array( 'Je voudrais annuler mon rendez-vous', 'cancel', array( 'booking' ), array() ),
    array( 'Ich brauche eine Reparatur, bitte ein Angebot', 'quote', array( 'service', 'multi_intent' ), array() ),
    array( 'Quiero cancelar, el servicio fue terrible', 'complaint', array( 'cancel' ), array() ),
    array( 'Ich möchte meinen Termin stornieren', 'cancel', array(), array() ),
    // Quotes: wording variants.
    array( 'Could I get a quote for gutter cleaning?', 'quote', array(), array() ),
    array( 'What is your pricing for a deep clean?', 'quote', array(), array() ),
    // Urgent flag does not change the queue.
    array( 'URGENT: burst pipe flooding the kitchen', 'service', array( 'urgent' ), array() ),
    // False positives that must not route to a customer intent.
    array( 'Thanks for the great service last week', 'general', array(), array( 'booking' ) ),
    array( 'Visit our website for more information about the team', 'general', array(), array( 'booking' ) ),
    array( 'Are you hiring? I am looking for part-time work', 'general', array(), array() ),
    array( 'Do you have a brochure I can download?', 'general', array(), array() ),
    // Spam: a link pair is a signal, and spam never wins over a customer need.
    array( 'Earn money online fast, guaranteed traffic for your site', 'spam', array( 'possible_spam' ), array() ),
    array( 'See https://a.example and https://b.example for SEO services', 'spam', array( 'possible_spam' ), array() ),
    array( 'Book now, click here', 'booking', array( 'possible_spam' ), array() ),
);

foreach ( $fixtures as $i => $fx ) {
    list( $text, $intent, $must, $mustnot ) = $fx;
    $result = TZFD_Enquiry_Triage::classify( $text );
    tzfd_triage_check( 'fixture ' . $i . ' intent for: ' . $text, $result['intent'] === $intent, 'got ' . $result['intent'] );
    foreach ( $must as $tag ) {
        tzfd_triage_check( 'fixture ' . $i . ' has tag ' . $tag, in_array( $tag, $result['tags'], true ), implode( ',', $result['tags'] ) );
    }
    foreach ( $mustnot as $tag ) {
        tzfd_triage_check( 'fixture ' . $i . ' lacks tag ' . $tag, ! in_array( $tag, $result['tags'], true ), implode( ',', $result['tags'] ) );
    }
    tzfd_triage_check( 'fixture ' . $i . ' queue is allowlisted', in_array( $result['queue'], TZFD_Enquiry_Triage::QUEUES, true ) );
    foreach ( $result['tags'] as $tag ) {
        tzfd_triage_check( 'fixture ' . $i . ' tag is allowlisted', in_array( $tag, array_merge( TZFD_Enquiry_Triage::INTENTS, TZFD_Enquiry_Triage::FLAG_TAGS ), true ), $tag );
    }
}

// Safe reclassification: same input gives the same output, and the result never carries a status or delete instruction.
$sample = 'Cancel my booking and rebook for Friday';
tzfd_triage_check( 'reclassification is deterministic', TZFD_Enquiry_Triage::classify( $sample ) === TZFD_Enquiry_Triage::classify( $sample ) );
tzfd_triage_check( 'result has no status key', ! array_key_exists( 'status', TZFD_Enquiry_Triage::classify( $sample ) ) );

// Invalid and empty input never throws and falls back to general.
foreach ( array( '', null, 12, "\xC3\x28 bad utf8 book", str_repeat( 'x', 20000 ) ) as $bad ) {
    try {
        $r = TZFD_Enquiry_Triage::classify( $bad );
        tzfd_triage_check( 'invalid input returns an intent', in_array( $r['intent'], TZFD_Enquiry_Triage::INTENTS, true ) );
    } catch ( Throwable $e ) {
        tzfd_triage_check( 'invalid input does not throw', false, $e->getMessage() );
    }
}
tzfd_triage_check( 'bad utf8 still finds booking', TZFD_Enquiry_Triage::classify( "\xC3\x28 bad utf8 book" )['intent'] === 'booking' );

// High volume: 20,000 mixed classifications stay fast and stable.
$volume = array_merge( array_column( $fixtures, 0 ), array( 'Random note about nothing in particular' ) );
$start  = microtime( true );
$seen   = array();
for ( $n = 0; $n < 20000; $n++ ) {
    $text = $volume[ $n % count( $volume ) ];
    $seen[ $text ] = TZFD_Enquiry_Triage::classify( $text )['intent'];
}
$elapsed = microtime( true ) - $start;
tzfd_triage_check( '20,000 classifications under 10 seconds', $elapsed < 10.0, sprintf( '%.2fs', $elapsed ) );
tzfd_triage_check( 'high volume results are stable', count( $seen ) === count( array_unique( $volume ) ) );

// Local only: the classifier source must not reach any hosted, network or AI helper.
$source = (string) file_get_contents( $includes );
foreach ( array( 'wp_remote_', 'TZFD_Suite_Gateway', 'curl_', 'file_get_contents( \'http', 'fsockopen', 'TZFD_Smart_Tasks' ) as $needle ) {
    tzfd_triage_check( 'no hosted or network call matching ' . $needle, false === strpos( $source, $needle ) );
}

echo "Passed: $pass, Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
