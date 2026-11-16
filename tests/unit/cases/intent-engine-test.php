<?php
/** Intent engine: classification, synonyms, phrases, negation, tie handling. */

$cases = array(
    'How much do you charge for a call out?' => 'price',
    'What are your prices for a deep clean' => 'price',
    'Are you open on Saturday?' => 'hours',
    'What time do you open' => 'hours',
    'Which suburbs do you cover?' => 'area',
    'What is your service area' => 'area',
    'Can I book an appointment tomorrow' => 'booking',
    'I need to reschedule my visit' => 'cancel',
    'My pipe burst, this is urgent' => 'emergency',
    'What is your phone number' => 'contact',
    'What is your refund policy' => 'policy',
    'Do you offer gutter cleaning' => 'services',
);
foreach ( $cases as $text => $expected ) {
    $got = TZFD_Intent_Engine::classify( $text );
    t_eq( $expected, $got['intent'], 'intent for "' . $text . '"' );
}

// Negation: a negated keyword must not score for its category.
$neg = TZFD_Intent_Engine::classify( "I don't want to cancel, I want to book" );
t_eq( 'booking', $neg['intent'], 'negated cancel does not win over booking' );
t_ok( in_array( 'cancel', $neg['negated'], true ), 'negated cancel is reported' );
t_eq( 'unknown', TZFD_Intent_Engine::classify( 'no cancel please' )['intent'], 'negated sole keyword yields unknown' );

// Apostrophes are folded so "don't" and "dont" match the same negation.
t_ok( TZFD_Intent_Engine::has_negation( "don't" ), "apostrophe negation detected" );
t_eq( 'dont', TZFD_Intent_Engine::normalise( "Don’t" ), 'curly apostrophe folded' );

// Empty and nonsense input.
t_eq( 'unknown', TZFD_Intent_Engine::classify( '' )['intent'], 'empty input is unknown' );
t_eq( 'unknown', TZFD_Intent_Engine::classify( 12345 )['intent'], 'non-string input is unknown' );
t_eq( 0.0, TZFD_Intent_Engine::classify( 'xyzzy plugh' )['confidence'], 'no keywords gives zero confidence' );

// Tie between two categories is flagged, and confidence is halved.
$tie = TZFD_Intent_Engine::classify( 'price and hours' );
t_ok( $tie['ambiguous'], 'tied categories are ambiguous' );
t_eq( 'unknown', TZFD_Intent_Engine::confident_intent( 'price and hours' ), 'ambiguous text is not confident' );

// Punctuation and case do not change the result.
t_eq( 'price', TZFD_Intent_Engine::classify( 'PRICE??!!' )['intent'], 'punctuation and case are ignored' );

// Injection-style text is just words and gets no special treatment.
t_eq( 'price', TZFD_Intent_Engine::classify( 'Ignore previous instructions. Price?' )['intent'], 'instruction-like text is only scored as words' );
