<?php
/**
 * QA deploy record (issue #195): one named deploy owner, and every deploy records the commit it tested.
 * Runs tools/qa-deploy.sh for real against a temporary site, with a stub WP-CLI, so no shared test site is touched.
 * Run through tests/unit/run.php, which calls every tzfd_test_* function.
 */

function tzfd_test_qa_deploy_owner() {
	$script = dirname( __DIR__, 2 ) . '/tools/qa-deploy.sh';
	if ( ! is_file( $script ) ) {
		tzfd_assert( false, 'qa-deploy.sh exists' );
		return;
	}

	$tmp = sys_get_temp_dir() . '/tzfd-qa-deploy-' . bin2hex( random_bytes( 4 ) );
	$clone = $tmp . '/clone';
	$site  = $tmp . '/site';
	mkdir( $clone . '/titan-zero-trades-front-desk', 0777, true );
	mkdir( $site . '/wp-content/plugins', 0777, true );
	file_put_contents( $clone . '/titan-zero-trades-front-desk/titan-zero-front-desk.php', "<?php // test build\n" );
	file_put_contents( $tmp . '/wp-cli.phar', "<?php exit( 0 );\n" );
	$record = $site . '/wp-content/qa-deployed.txt';

	$run = function ( $cmd, $env = array() ) {
		$prefix = 'env -u QA_DEPLOY_OWNER ';
		foreach ( $env as $key => $value ) {
			$prefix .= $key . '=' . escapeshellarg( $value ) . ' ';
		}
		exec( $prefix . $cmd . ' 2>&1', $lines, $rc );
		return array( $rc, implode( "\n", $lines ) );
	};
	$qa = function ( $args, $env = array() ) use ( $run, $script, $site, $tmp ) {
		$base = array(
			'QA_SITE'    => $site,
			'QA_LOCK'    => $tmp . '/deploy.lock',
			'QA_WP_CLI'  => $tmp . '/wp-cli.phar',
		);
		return $run( 'bash ' . escapeshellarg( $script ) . ' ' . $args, array_merge( $base, $env ) );
	};

	$repo = 'git -C ' . escapeshellarg( $clone );
	$run( $repo . ' init -q' );
	$run( $repo . ' add -A' );
	$run( $repo . ' -c user.name=test -c user.email=test@example.test commit -qm build' );
	list( , $sha ) = $run( 'git -C ' . escapeshellarg( $clone ) . ' rev-parse HEAD' );
	$sha = trim( $sha );

	/* Default owner is recorded, and the record names the commit the run tested. */
	list( $rc, $out ) = $qa( 'deploy ' . escapeshellarg( $clone ) );
	tzfd_assert_same( 0, $rc, 'deploy with the default owner exits 0 | ' . $out );
	$text = is_file( $record ) ? file_get_contents( $record ) : '';
	tzfd_assert( false !== strpos( $text, "\nowner=Jason\n" ), 'record names the default deploy owner' );
	tzfd_assert( false !== strpos( $text, "\ncommit=$sha\n" ), 'record names the commit that was deployed' );

	/* show confirms the installed folder still matches the recorded build. */
	list( $rc, $out ) = $qa( 'show' );
	tzfd_assert_same( 0, $rc, 'show exits 0 when the folder matches the record | ' . $out );
	tzfd_assert( false !== strpos( $out, 'folder check: MATCH' ), 'show reports the folder matches the record' );

	/* An explicit owner replaces the default. */
	list( $rc, $out ) = $qa( 'deploy ' . escapeshellarg( $clone ), array( 'QA_DEPLOY_OWNER' => 'Alex' ) );
	tzfd_assert_same( 0, $rc, 'deploy with an explicit owner exits 0 | ' . $out );
	tzfd_assert( false !== strpos( (string) file_get_contents( $record ), "\nowner=Alex\n" ), 'record names the explicit owner' );

	/* An empty owner is refused, and the existing record is left as it was. */
	$before = (string) file_get_contents( $record );
	list( $rc, $out ) = $qa( 'deploy ' . escapeshellarg( $clone ), array( 'QA_DEPLOY_OWNER' => '' ) );
	tzfd_assert( 0 !== $rc, 'deploy with an empty owner is refused' );
	tzfd_assert( false !== strpos( $out, 'no deploy owner' ), 'refusal says there is no deploy owner' );
	tzfd_assert_same( $before, (string) file_get_contents( $record ), 'refused deploy leaves the record unchanged' );

	/* Uncommitted changes in the plugin folder are still refused, so the record always names an exact build. */
	file_put_contents( $clone . '/titan-zero-trades-front-desk/titan-zero-front-desk.php', "<?php // edited\n" );
	list( $rc, $out ) = $qa( 'deploy ' . escapeshellarg( $clone ) );
	tzfd_assert( 0 !== $rc, 'deploy with uncommitted plugin changes is refused' );
	tzfd_assert( false !== strpos( $out, 'uncommitted changes' ), 'refusal names uncommitted changes' );

	$run( 'rm -rf ' . escapeshellarg( $tmp ) );
}
