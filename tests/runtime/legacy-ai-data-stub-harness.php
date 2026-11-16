<?php
// Stub harness for TZFD_Legacy_AI_Data (issue #17). Not shipped in the plugin. Run: php tests/runtime/legacy-ai-data-stub-harness.php
// It stubs WordPress and wpdb, so it proves the gating, redaction and delete logic only. It does not prove WordPress integration.
define('ABSPATH', '/tmp/wp/');
define('TZFD_VERSION', '3.0.128-test');
define('ARRAY_A', 'ARRAY_A');

final class StopRedirect extends RuntimeException {}
final class StopDie extends RuntimeException {}

$GLOBALS['opts'] = array();
$GLOBALS['hooks'] = array();
$GLOBALS['caps'] = true;
$GLOBALS['tables'] = array('wp_tz_ai_conversations' => array());

function like_to_regex($pattern) {
    $re = '';
    $n = strlen($pattern);
    for ($i = 0; $i < $n; $i++) {
        $c = $pattern[$i];
        if ($c === '\\' && $i + 1 < $n) { $re .= preg_quote($pattern[++$i], '/'); continue; }
        if ($c === '%') { $re .= '.*'; continue; }
        if ($c === '_') { $re .= '.'; continue; }
        $re .= preg_quote($c, '/');
    }
    return '/^' . $re . '$/s';
}

class FakeWpdb {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($query, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[sdi]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            if ($m[0] === '%i') return '`' . $v . '`';
            if ($m[0] === '%d') return (string) (int) $v;
            return "'" . addslashes((string) $v) . "'";
        }, $query);
    }
    public function get_col($sql) {
        $GLOBALS['sql'][] = $sql;
        if (strpos($sql, 'FROM wp_options') !== false) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m);
            $pats = array_map('stripslashes', $m[1]);
            $out = array();
            foreach (array_keys($GLOBALS['opts']) as $name) {
                foreach ($pats as $p) { if (preg_match(like_to_regex($p), $name)) { $out[] = $name; break; } }
            }
            return $out;
        }
        if (strpos($sql, 'SELECT id FROM') === 0) {
            preg_match('/FROM `([^`]+)`/', $sql, $t);
            preg_match("/LOWER\(`([^`]+)`\) = '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m);
            $col = $m[1]; $val = strtolower(stripslashes($m[2]));
            $ids = array();
            foreach ($GLOBALS['tables'][$t[1]] ?? array() as $r) { if (strtolower($r[$col] ?? '') === $val) $ids[] = (int) $r['id']; }
            sort($ids);
            preg_match('/LIMIT (\d+)/', $sql, $l);
            return array_slice($ids, 0, (int) $l[1]);
        }
        if (strpos($sql, 'SHOW COLUMNS FROM') === 0) {
            preg_match('/FROM `([^`]+)`/', $sql, $t);
            $rows = $GLOBALS['tables'][$t[1]] ?? array();
            return $rows ? array_keys($rows[0]) : array();
        }
        return array();
    }
    public function get_var($sql) {
        if (strpos($sql, 'SHOW TABLES') === 0) {
            preg_match("/LIKE '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m);
            $p = stripslashes($m[1]);
            foreach (array_keys($GLOBALS['tables']) as $t) { if (preg_match(like_to_regex($p), $t)) return $t; }
            return null;
        }
        if (strpos($sql, 'COUNT(*)') !== false) {
            preg_match('/FROM `([^`]+)`/', $sql, $m);
            return count($GLOBALS['tables'][$m[1]] ?? array());
        }
        return null;
    }
    public function query($sql) {
        $GLOBALS['sql'][] = $sql;
        if (!preg_match('/^DELETE FROM `([^`]+)` WHERE id IN \(([\d,]+)\)$/', $sql, $m)) return false;
        $ids = array_map('intval', explode(',', $m[2]));
        $before = count($GLOBALS['tables'][$m[1]] ?? array());
        $GLOBALS['tables'][$m[1]] = array_values(array_filter($GLOBALS['tables'][$m[1]] ?? array(), function ($r) use ($ids) { return !in_array((int) $r['id'], $ids, true); }));
        return $before - count($GLOBALS['tables'][$m[1]]);
    }
    public function get_results($sql, $fmt) {
        preg_match("/WHERE LOWER\(`([^`]+)`\) = '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m);
        preg_match('/FROM `([^`]+)`/', $sql, $t);
        $col = $m[1]; $val = stripslashes($m[2]);
        preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $l);
        $rows = array_values(array_filter($GLOBALS['tables'][$t[1]] ?? array(), function ($r) use ($col, $val) { return strtolower($r[$col] ?? '') === $val; }));
        return array_slice($rows, (int) $l[2], (int) $l[1]);
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['sql'] = array();

function get_option($n, $d = false) { return array_key_exists($n, $GLOBALS['opts']) ? $GLOBALS['opts'][$n] : $d; }
function delete_option($n) { if (!array_key_exists($n, $GLOBALS['opts'])) return false; unset($GLOBALS['opts'][$n]); return true; }
function maybe_serialize($v) { return is_array($v) || is_object($v) ? serialize($v) : $v; }
function add_action($h, $cb) { $GLOBALS['hooks'][] = array($h, $cb); }
function add_filter($h, $cb) { $GLOBALS['hooks'][] = array($h, $cb); }
function add_management_page() {}
function current_user_can($c) { return $GLOBALS['caps']; }
function wp_die($m, $code = 0) { throw new StopDie((string) $m); }
function check_admin_referer($a) { return true; }
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_email($v) { return strtolower(trim($v)); }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_safe_redirect($u) { throw new StopRedirect($u); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function admin_url($p = '') { return 'http://x/wp-admin/' . $p; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s) { return $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function __($s) { return $s; }
function _e($s) {}
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function nocache_headers() {}
function wp_nonce_field() {}
function submit_button() {}
function sprintf_stub() {}
function wp_list() {}
function wp_kses_post($s) { return $s; }
function current_time($t) { return ''; }
function gmdate_stub() {}
function _n($single, $plural, $n, $d = '') { return 1 === (int) $n ? $single : $plural; }

require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-legacy-ai-data.php';

$fail = 0; $pass = 0;
function check($label, $cond) { global $fail, $pass; if ($cond) { $pass++; echo "PASS $label\n"; } else { $fail++; echo "FAIL $label\n"; } }

// Seed: legacy AI options, scoped copies, a credential-like option, unrelated settings, and chat rows.
$GLOBALS['opts'] = array(
    'tz_fd_ai_services' => 'Plumbing',
    'tz_fd_ai_greeting' => 'Hello',
    'tz_fd_ai_system_prompt' => 'prompt',
    'tzfd_c7_tz_fd_ai_services' => 'Plumbing',
    'tz_fd_ai_provider_api_key' => 'sk-REAL-SECRET-123',
    'tz_fd_dashboard_maxitems' => 25,
    'tzfd_customer_event_company_cursor_v1' => 4,
    'tz_fd_business_profile' => array('name' => 'Acme'),
    'other_plugin_option' => 'x',
);
$GLOBALS['tables']['wp_tz_ai_conversations'] = array(
    array('id' => 1, 'email' => 'Jane@Example.com', 'message' => 'Hi', 'api_key' => 'k'),
    array('id' => 2, 'email' => 'other@example.com', 'message' => 'Yo', 'api_key' => 'k'),
    array('id' => 3, 'email' => 'jane@example.com', 'message' => 'Again', 'api_key' => 'k'),
);

// 1. No automatic deletion hook. Only admin_menu and admin-post/privacy filters are registered.
TZFD_Legacy_AI_Data::register();
$hookNames = array_map(function ($h) { return $h[0]; }, $GLOBALS['hooks']);
check('register adds no admin_init hook', !in_array('admin_init', $hookNames, true));
check('register adds no upgrade/activation hook', count(array_intersect($hookNames, array('upgrader_process_complete', 'init', 'plugins_loaded'))) === 0);

// 2. Option discovery: AI and scoped AI rows only, never unrelated options.
$names = TZFD_Legacy_AI_Data::option_names();
check('discovers global legacy AI options', in_array('tz_fd_ai_services', $names, true));
check('discovers per-company scoped AI options', in_array('tzfd_c7_tz_fd_ai_services', $names, true));
check('does not list unrelated options', !in_array('tz_fd_dashboard_maxitems', $names, true) && !in_array('other_plugin_option', $names, true));

// 3. Audit exposes names, sizes and counts, never values.
$audit = TZFD_Legacy_AI_Data::audit();
$json = json_encode($audit);
check('audit reports chat row count', $audit['chat_table']['rows'] === 3 && $audit['chat_records_retained'] === true);
check('audit never includes a stored value', strpos($json, 'sk-REAL-SECRET-123') === false && strpos($json, 'Plumbing') === false);
$secretRow = array_values(array_filter($audit['options'], function ($r) { return $r['name'] === 'tz_fd_ai_provider_api_key'; }));
check('credential-like option is flagged secret', isset($secretRow[0]) && $secretRow[0]['secret'] === true);

// 4. Export withholds credential values and lists them by name only.
$payload = TZFD_Legacy_AI_Data::export_payload();
$exportJson = json_encode($payload);
check('export never contains a credential value', strpos($exportJson, 'sk-REAL-SECRET-123') === false);
check('export lists withheld credential name', in_array('tz_fd_ai_provider_api_key', $payload['withheld'], true));
check('export keeps non-secret AI values for backup', $payload['options']['tz_fd_ai_services'] === 'Plumbing');
check('export does not contain unrelated options', !array_key_exists('tz_fd_dashboard_maxitems', $payload['options']));

// 5. Confirmation gating (pure check).
check('phrase alone is refused', TZFD_Legacy_AI_Data::confirmation_is_complete('DELETE LEGACY AI OPTIONS', false) === false);
check('backup alone is refused', TZFD_Legacy_AI_Data::confirmation_is_complete('delete', true) === false);
check('wrong-case phrase is refused', TZFD_Legacy_AI_Data::confirmation_is_complete('delete legacy ai options', true) === false);
check('exact phrase plus backup is accepted', TZFD_Legacy_AI_Data::confirmation_is_complete('DELETE LEGACY AI OPTIONS', true) === true);

// 6. Handler refuses without confirmation and deletes nothing.
$_POST = array('confirm_phrase' => 'nope');
$before = count($GLOBALS['opts']);
try { TZFD_Legacy_AI_Data::handle_delete(); check('unconfirmed delete redirects', false); }
catch (StopRedirect $r) { check('unconfirmed delete redirects with refused notice', strpos($r->getMessage(), 'refused') !== false); }
check('unconfirmed delete removes nothing', count($GLOBALS['opts']) === $before);

// 7. Non-administrator cannot export or delete.
$GLOBALS['caps'] = false;
$denied = 0;
try { TZFD_Legacy_AI_Data::handle_export(); } catch (StopDie $d) { $denied++; }
$_POST = array('confirm_phrase' => 'DELETE LEGACY AI OPTIONS', 'backup_confirmed' => '1');
try { TZFD_Legacy_AI_Data::handle_delete(); } catch (StopDie $d) { $denied++; }
check('non-admin export and delete are both refused', $denied === 2);
check('non-admin refusal removes nothing', isset($GLOBALS['opts']['tz_fd_ai_services']));
$GLOBALS['caps'] = true;

// 8. Confirmed delete removes only legacy AI options, keeps chat rows and other settings.
$_POST = array('confirm_phrase' => 'DELETE LEGACY AI OPTIONS', 'backup_confirmed' => '1');
try { TZFD_Legacy_AI_Data::handle_delete(); } catch (StopRedirect $r) { check('confirmed delete redirects with deleted notice', strpos($r->getMessage(), 'deleted') !== false); }
check('legacy AI options are removed', !isset($GLOBALS['opts']['tz_fd_ai_services']) && !isset($GLOBALS['opts']['tzfd_c7_tz_fd_ai_services']) && !isset($GLOBALS['opts']['tz_fd_ai_provider_api_key']));
check('upgrade and booking-related settings are kept', isset($GLOBALS['opts']['tz_fd_dashboard_maxitems']) && isset($GLOBALS['opts']['tzfd_customer_event_company_cursor_v1']) && isset($GLOBALS['opts']['tz_fd_business_profile']));
check('unrelated plugin option is kept', isset($GLOBALS['opts']['other_plugin_option']));
check('chat history rows are kept after delete', count($GLOBALS['tables']['wp_tz_ai_conversations']) === 3);

// 9. Privacy exporter: exact match, case-insensitive, read-only, paged response.
$res = TZFD_Legacy_AI_Data::export_chats('JANE@example.com', 1);
check('exporter returns only the matching customer rows', count($res['data']) === 2 && $res['done'] === true);
check('exporter item ids are stable', $res['data'][0]['item_id'] === 'tzfd-legacy-ai-chat-1');
$keys = array_map(function ($f) { return $f['name']; }, $res['data'][0]['data']);
check('exporter drops credential-like columns', !in_array('api_key', $keys, true) && in_array('message', $keys, true));
$none = TZFD_Legacy_AI_Data::export_chats('nobody@example.com', 1);
check('exporter returns empty set for no match', $none['data'] === array() && $none['done'] === true);
$bad = TZFD_Legacy_AI_Data::export_chats("jane@example.com'; DROP TABLE x", 1);
check('exporter rejects a malformed address without querying', $bad['data'] === array());
$page2 = TZFD_Legacy_AI_Data::export_chats('jane@example.com', 2);
check('exporter page past the end is done', $page2['data'] === array() && $page2['done'] === true);
$wrap = TZFD_Legacy_AI_Data::register_exporter(array());
check('exporter is registered with WordPress privacy tools', isset($wrap['tzfd-legacy-ai-chats']));
$writes = array_filter($GLOBALS['sql'], function ($q) { return preg_match('/^\s*(DELETE|UPDATE|INSERT|DROP)/i', $q); });
check('no write statements were issued by the exporter or audit', count($writes) === 0);

// 10. Rollback: restore from an export adds only missing legacy options, never overwrites.
function add_option($n, $v, $d = '', $a = 'yes') { if (array_key_exists($n, $GLOBALS['opts'])) return false; $GLOBALS['opts'][$n] = $v; $GLOBALS['autoload'][$n] = $a; return true; }
function absint($v) { return abs((int) $v); }
$GLOBALS['autoload'] = array();
$GLOBALS['opts']['tz_fd_ai_greeting'] = 'Current greeting';
$export = array(
    'format' => 'tzfd-legacy-ai-export', 'format_version' => 1,
    'options' => array(
        'tz_fd_ai_services' => 'Export plumbing',
        'tz_fd_ai_greeting' => 'Old greeting',
        'tzfd_c7_tz_fd_ai_services' => 'Export scoped',
        'tz_fd_ai_provider_api_key' => 'sk-FROM-EXPORT',
        'tz_fd_dashboard_maxitems' => 99,
        'other_plugin_option' => 'x',
    ),
);
$before_chat = count($GLOBALS['tables']['wp_tz_ai_conversations']);
$r = TZFD_Legacy_AI_Data::restore_payload($export);
check('restore reports valid export', $r['valid'] === true);
check('restore adds missing legacy options', $r['added'] === 2 && $GLOBALS['opts']['tz_fd_ai_services'] === 'Export plumbing' && $GLOBALS['opts']['tzfd_c7_tz_fd_ai_services'] === 'Export scoped');
check('restore keeps existing value and never overwrites', $GLOBALS['opts']['tz_fd_ai_greeting'] === 'Current greeting' && $r['skipped'] === 1);
check('restore writes restored options with autoload off', $GLOBALS['autoload']['tz_fd_ai_services'] === 'no');
check('restore rejects credential-like and non-legacy names', !isset($GLOBALS['opts']['tz_fd_ai_provider_api_key']) && $r['rejected'] === 3);
check('restore does not touch unrelated settings', $GLOBALS['opts']['tz_fd_dashboard_maxitems'] === 25 && $GLOBALS['opts']['other_plugin_option'] === 'x');
check('restore does not touch chat rows', count($GLOBALS['tables']['wp_tz_ai_conversations']) === $before_chat);
$again = TZFD_Legacy_AI_Data::restore_payload($export);
check('second restore adds nothing new', $again['added'] === 0 && $GLOBALS['opts']['tz_fd_ai_services'] === 'Export plumbing');
$bad_format = TZFD_Legacy_AI_Data::restore_payload(array('format' => 'other', 'format_version' => 1, 'options' => array('tz_fd_ai_x' => 1)));
$bad_ver = TZFD_Legacy_AI_Data::restore_payload(array('format' => 'tzfd-legacy-ai-export', 'format_version' => 2, 'options' => array('tz_fd_ai_y' => 1)));
$bad_shape = TZFD_Legacy_AI_Data::restore_payload('not an array');
check('restore refuses wrong format, version or shape', !$bad_format['valid'] && !$bad_ver['valid'] && !$bad_shape['valid'] && !isset($GLOBALS['opts']['tz_fd_ai_x']) && !isset($GLOBALS['opts']['tz_fd_ai_y']));

// File path: size cap is enforced before reading; ownership check refuses non-uploaded files.
$tmp = tempnam(sys_get_temp_dir(), 'tzfdr');
file_put_contents($tmp, json_encode($export));
$GLOBALS['opts'] = array_diff_key($GLOBALS['opts'], array('tz_fd_ai_services' => 1));
$ff = TZFD_Legacy_AI_Data::restore_from_file($tmp, filesize($tmp));
check('file restore reads a valid export', $ff['valid'] === true && $ff['added'] === 1 && isset($GLOBALS['opts']['tz_fd_ai_services']));
$GLOBALS['opts'] = array_diff_key($GLOBALS['opts'], array('tz_fd_ai_services' => 1));
$big = TZFD_Legacy_AI_Data::restore_from_file($tmp, TZFD_Legacy_AI_Data::RESTORE_MAX_BYTES + 1);
check('oversized file is refused before reading', $big['valid'] === false && !isset($GLOBALS['opts']['tz_fd_ai_services']));
$junk = tempnam(sys_get_temp_dir(), 'tzfdr');
file_put_contents($junk, '{not json');
check('malformed JSON file restores nothing', TZFD_Legacy_AI_Data::restore_from_file($junk, filesize($junk))['valid'] === false);
unlink($junk);
// Handler: a file that was not uploaded over HTTP is refused (is_uploaded_file is false for it).
$_FILES = array('export_file' => array('error' => 0, 'tmp_name' => $tmp, 'size' => filesize($tmp)));
try { TZFD_Legacy_AI_Data::handle_restore(); } catch (StopRedirect $r2) { check('handler refuses a non-uploaded file', strpos($r2->getMessage(), 'restore_invalid') !== false); }
check('handler refusal restores nothing', !isset($GLOBALS['opts']['tz_fd_ai_services']));
$GLOBALS['caps'] = false;
try { TZFD_Legacy_AI_Data::handle_restore(); check('non-admin restore is refused', false); } catch (StopDie $d) { check('non-admin restore is refused', true); }
$GLOBALS['caps'] = true;
unlink($tmp);

// 11. On-request erase of legacy chat rows by exact email. No automatic deletion.
TZFD_Legacy_AI_Data::register();
$erasers = TZFD_Legacy_AI_Data::register_eraser(array());
check('eraser is registered with WordPress privacy tools', isset($erasers['tzfd-legacy-ai-chats']));
$hook_names = array_map(function ($h) { return $h[0]; }, $GLOBALS['hooks']);
check('no admin_init, activation or upgrade hook can delete data', !in_array('admin_init', $hook_names, true) && !in_array('upgrader_process_complete', $hook_names, true) && !in_array('plugins_loaded', $hook_names, true));
$opts_before = $GLOBALS['opts'];
$bad = TZFD_Legacy_AI_Data::erase_chats("jane@example.com' OR 1=1", 1);
check('erase rejects a malformed address and deletes nothing', $bad['items_removed'] === false && count($GLOBALS['tables']['wp_tz_ai_conversations']) === 3);
$none = TZFD_Legacy_AI_Data::erase_chats('nobody@example.com', 1);
check('erase with no match removes nothing and is done', $none['items_removed'] === false && $none['done'] === true && count($GLOBALS['tables']['wp_tz_ai_conversations']) === 3);
$gone = TZFD_Legacy_AI_Data::erase_chats('JANE@example.com', 1);
$remaining_ids = array_map(function ($r) { return $r['id']; }, $GLOBALS['tables']['wp_tz_ai_conversations']);
check('erase removes exact-email chat rows, case-insensitive', $gone['items_removed'] === true && $gone['done'] === true && $remaining_ids === array(2));
check('erase reports the number removed', strpos(implode(' ', $gone['messages']), '2 legacy AI chat records deleted') !== false);
check('erase does not touch other customers or options', $GLOBALS['opts'] === $opts_before);
$after_export = TZFD_Legacy_AI_Data::export_chats('jane@example.com', 1);
check('exporter returns nothing after erase', $after_export['data'] === array());
// Batching: more than PAGE_SIZE matching rows take two calls.
for ($i = 100; $i < 152; $i++) { $GLOBALS['tables']['wp_tz_ai_conversations'][] = array('id' => $i, 'email' => 'bulk@example.com', 'message' => 'm', 'api_key' => 'k'); }
$first = TZFD_Legacy_AI_Data::erase_chats('bulk@example.com', 1);
check('first batch removes one page and is not done', $first['items_removed'] === true && $first['done'] === false);
$second = TZFD_Legacy_AI_Data::erase_chats('bulk@example.com', 2);
check('second call removes the rest and is done', $second['done'] === true && count(array_filter($GLOBALS['tables']['wp_tz_ai_conversations'], function ($r) { return $r['email'] === 'bulk@example.com'; })) === 0);
check('batched erase keeps the other customer row', count($GLOBALS['tables']['wp_tz_ai_conversations']) === 1 && $GLOBALS['tables']['wp_tz_ai_conversations'][0]['email'] === 'other@example.com');
$GLOBALS['caps'] = true;

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
