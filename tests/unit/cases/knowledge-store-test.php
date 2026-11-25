<?php
/** Versioned, tenant-scoped FAQ registry: writes, versions, drafts, roles, tenant isolation, conflicts. */

$manager = static function () { return true; };
$visitor = static function () { return false; };

$mem   = new TZFD_Test_Memory_Storage();
$store = new TZFD_Knowledge_Store( $mem, 7, $manager );

t_eq( 0, $store->version(), 'empty registry is version 0' );

$r1 = $store->save_entry( array(
    'question' => 'Do you charge a call out fee?',
    'answer'   => 'Yes, the call out fee is listed on our price page.',
    'keywords' => array( 'call out', 'fee' ),
    'status'   => 'approved',
) );
t_ok( $r1['ok'], 'manager can save an approved entry' );
t_eq( 1, $r1['version'], 'first write is version 1' );
$id1 = $r1['id'];

// Drafts are stored but never shown on the public path.
$r2 = $store->save_entry( array( 'question' => 'Do you do weekends?', 'answer' => 'Draft answer text.', 'status' => 'draft' ) );
t_ok( $r2['ok'], 'draft saves' );
t_eq( 1, count( $store->entries( false ) ), 'public view hides drafts' );
t_eq( 2, count( $store->entries( true ) ), 'manager view includes drafts' );

// Visitors (no manage rights) cannot read drafts or write.
$public = new TZFD_Knowledge_Store( $mem, 7, $visitor );
t_eq( 1, count( $public->entries( true ) ), 'visitor cannot see drafts even when asking' );
$denied = $public->save_entry( array( 'question' => 'Injected?', 'answer' => 'Nope.', 'status' => 'approved' ) );
t_eq( 'forbidden', $denied['code'], 'visitor write is refused' );
t_eq( 2, $store->version(), 'refused write does not bump the version' );

// Company 0 is refused for every operation.
$none = new TZFD_Knowledge_Store( $mem, 0, $manager );
t_eq( 0, count( $none->entries( true ) ), 'company 0 sees nothing' );
t_eq( 'forbidden', $none->save_entry( array( 'question' => 'Anything?', 'answer' => 'Yes.' ) )['code'], 'company 0 writes are refused' );

// Tenant isolation: company 8 cannot see company 7's entries.
$other = new TZFD_Knowledge_Store( $mem, 8, $manager );
t_eq( 0, count( $other->entries( true ) ), 'another tenant sees no FAQ entries' );
t_eq( 0, $other->version(), 'another tenant starts at version 0' );

// Edit with the correct expected version succeeds and bumps the version.
$edit = $store->save_entry( array( 'id' => $id1, 'question' => 'Do you charge a call out fee?', 'answer' => 'The call out fee is $80.', 'status' => 'approved' ), 2 );
t_ok( $edit['ok'], 'edit with current version succeeds' );
t_eq( 3, $edit['version'], 'edit bumps version' );
t_eq( 'The call out fee is $80.', $store->entries()[ $id1 ]['answer'], 'edited answer is stored' );

// A stale writer (still holding version 2) is refused.
$stale = $store->save_entry( array( 'id' => $id1, 'question' => 'Do you charge a call out fee?', 'answer' => 'Old text.', 'status' => 'approved' ), 2 );
t_eq( 'conflict', $stale['code'], 'stale expected version gets a conflict' );
t_eq( 'The call out fee is $80.', $store->entries()[ $id1 ]['answer'], 'conflicting write leaves the stored answer alone' );

// Delete removes the entry and bumps the version, so cached answers are unreachable.
$del = $store->delete_entry( $id1, 3 );
t_ok( $del['ok'], 'delete with current version succeeds' );
t_eq( 4, $del['version'], 'delete bumps version' );
t_eq( false, isset( $store->entries( true )[ $id1 ] ), 'deleted entry is gone' );
t_eq( 'not_found', $store->delete_entry( $id1 )['code'], 'deleting twice reports not_found' );

// Storage failure is reported, not hidden.
$mem->fail_writes = true;
$fail = $store->save_entry( array( 'question' => 'Will this save?', 'answer' => 'Only if storage works.', 'status' => 'approved' ) );
t_eq( 'storage', $fail['code'], 'storage failure is reported' );
$mem->fail_writes = false;

// Validation: bad input is refused with a reason and nothing is stored.
$before = $store->version();
t_eq( 'invalid', $store->save_entry( array( 'question' => '', 'answer' => 'x' ) )['code'], 'empty question refused' );
t_eq( 'invalid', $store->save_entry( array( 'question' => 'ok?', 'answer' => str_repeat( 'a', 2001 ) ) )['code'], 'answer of 2001 characters refused (#330 per-value cap)' );
t_eq( 'invalid', $store->save_entry( array( 'question' => str_repeat( 'q', 301 ), 'answer' => 'a' ) )['code'], 'question of 301 characters refused' );
t_eq( 'invalid', $store->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => array( str_repeat( 'k', 41 ) ) ) )['code'], 'keyword of 41 characters refused' );
// Owner decision #330: 8192 bytes per payload. Accented letters are 2 bytes each, so 2000 of them are 4000 bytes.
// Four-byte characters (emoji) make a 2000-character answer 8000 bytes, which with a 300-character question and
// 20 keywords of 40 characters goes over 8192 and is refused whole, never truncated.
$kw_full = array_fill( 0, 20, str_repeat( "\u{1F600}", 40 ) );
t_eq( 'invalid', $store->save_entry( array( 'question' => str_repeat( "\u{1F600}", 300 ), 'answer' => str_repeat( "\u{1F600}", 2000 ), 'keywords' => $kw_full ) )['code'], 'entry over 8192 bytes refused (#330 payload cap)' );
t_eq( 'invalid', $store->save_entry( array( 'question' => str_repeat( "\u{1F600}", 300 ), 'answer' => str_repeat( "\u{1F600}", 1800 ) ) )['code'], 'question and answer of emoji (8400 bytes, both under their caps) refused (#330 payload cap)' );
t_eq( 'invalid', $store->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'lang' => 'english' ) )['code'], 'bad language tag refused' );
t_eq( 'invalid', $store->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'status' => 'published' ) )['code'], 'unknown status refused' );
t_eq( $before, $store->version(), 'invalid writes do not bump the version' );
t_eq( 'saved', $store->save_entry( array( 'question' => 'ok?', 'answer' => str_repeat( 'a', 2000 ), 'status' => 'draft' ) )['code'], 'answer of exactly 2000 characters accepted (#330)' );
t_eq( 'saved', $store->save_entry( array( 'question' => 'ok?', 'answer' => str_repeat( "\u{00E9}", 2000 ), 'status' => 'draft' ) )['code'], 'answer of 2000 accented characters (4000 bytes) accepted: characters counted, payload under 8192' );
t_eq( 'saved', $store->save_entry( array( 'question' => str_repeat( "\u{1F600}", 300 ), 'answer' => str_repeat( "\u{1F600}", 380 ), 'keywords' => array( str_repeat( "\u{1F600}", 20 ) ), 'status' => 'draft' ) )['code'], 'entry exactly at the 8192-byte payload limit accepted' );
t_eq( 'saved', $store->save_entry( array( 'question' => 'ok?', 'answer' => str_repeat( 'a', 2000 ), 'keywords' => array_fill( 0, 20, str_repeat( 'k', 40 ) ), 'status' => 'draft' ) )['code'], 'ASCII entry at every per-field maximum accepted (under 8192 bytes)' );

// Markup and control characters are stripped from stored text.
$clean = TZFD_Knowledge_Store::sanitise( array( 'question' => "<script>x</script>Hi\x07 there", 'answer' => "<b>Yes</b>\x00", 'status' => 'approved' ) );
t_eq( 'xHi there', $clean['question'] ?? '', 'tags and control bytes are removed from the question' );
t_eq( 'Yes', $clean['answer'] ?? '', 'tags and control bytes are removed from the answer' );

// Language filtering on the public path.
$store->save_entry( array( 'question' => 'Quelles sont vos heures?', 'answer' => 'Nous ouvrons à 8h.', 'lang' => 'fr', 'status' => 'approved' ) );
t_eq( 1, count( $store->approved( 'fr' ) ), 'approved() filters by language' );

// Owner decision #330: business facts are cut at 2000 characters per value, and the cut value is what is stored and served.
t_eq( 2000, mb_strlen( TZFD_Business_Facts::clean( str_repeat( 'b', 2500 ) ) ), 'business fact value is cut at 2000 characters (#330)' );
t_eq( 2000, mb_strlen( TZFD_Business_Facts::clean( str_repeat( "\u{00E9}", 2500 ) ) ), 'business fact value counts characters, not bytes (#330)' );
t_eq( 1999, mb_strlen( TZFD_Business_Facts::clean( str_repeat( 'b', 1999 ) ) ), 'business fact value of 1999 characters is kept whole (#330)' );
