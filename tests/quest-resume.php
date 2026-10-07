<?php
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
define('DB_NAME', 'test');
class WP_Error { public function __construct(public string $code, public string $message, public array $data = []) {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_option($k, $default = false) { return $default; }
function sanitize_key($v) { return $v; }
function current_time($format, $gmt = false) { return '2026-10-07 00:00:00'; }
class Knowly_Debug { static function log(...$args) {} }
class Knowly_Gem_Service {
    static int $balance = 10;
    static int $debits = 0;
    static bool $fail = false;
    static function get_balance($id) { return self::$balance; }
    static function has_enough($id, $cost) { return self::$balance >= $cost; }
    static function deduct($id, $cost, ...$args) {
        if (self::$fail) return new WP_Error('debit_failed', 'failed');
        self::$debits++; self::$balance -= $cost;
        return ['balance_after' => self::$balance];
    }
}
class FakeDB {
    public string $prefix = 'wp_'; public int $insert_id = 0;
    public array $sessions = []; public int $releases = 0; public bool $busy = false; public bool $task_column = true; public bool $throws = false;
    function prepare($sql, ...$args) { return [$sql, $args]; }
    function get_var($query) {
        [$sql, $args] = $query;
        if (str_contains($sql, 'GET_LOCK')) return $this->busy ? 0 : 1;
        if (str_contains($sql, 'RELEASE_LOCK')) { $this->releases++; return 1; }
        if (str_contains($sql, 'INFORMATION_SCHEMA')) return $this->task_column ? 1 : 0;
        return 0;
    }
    function get_results($query, $mode) {
        if ($this->throws) throw new RuntimeException('DB exception');
        [, $args] = $query;
        return array_values(array_filter($this->sessions, fn($s) => $s['child_id'] === $args[0] && $s['quest_id'] === $args[1] && $s['source'] === $args[2]));
    }
    function insert($table, $data, $formats) { $this->insert_id++; $this->sessions[] = $data; }
    function delete($table, $where, $formats) { $this->sessions = array_values(array_filter($this->sessions, fn($s) => $s['quest_session_id'] !== $where['quest_session_id'])); }
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
require __DIR__ . '/../includes/services/class-knowly-quest-service.php';
$wpdb = new FakeDB();
$a = Knowly_Quest_Service::start(52, 'quest-a');
$b = Knowly_Quest_Service::start(52, 'quest-a');
check($a['session_id'] === $b['session_id'] && $b['resumed'] && $b['gem_cost'] === 0, 'Resume must reuse session free');
check(Knowly_Gem_Service::$debits === 1 && count($wpdb->sessions) === 1, 'Retry must debit once');
Knowly_Gem_Service::$balance = 0;
check(!is_wp_error(Knowly_Quest_Service::start(52, 'quest-a')), 'Resume allowed with no gems');
check(is_wp_error(Knowly_Quest_Service::start(53, 'quest-a')), 'Other child cannot resume session');
$c = Knowly_Quest_Service::start(52, 'quest-a', 'assignment', 1);
$d = Knowly_Quest_Service::start(52, 'quest-a', 'assignment', 2);
check($c['session_id'] !== $d['session_id'] && $c['session_id'] !== $a['session_id'], 'Task/source boundaries');
$wpdb->busy = true;
check(is_wp_error(Knowly_Quest_Service::start(52, 'quest-a')), 'Lock failure must fail closed');
$wpdb->busy = false;
Knowly_Gem_Service::$balance = 10; Knowly_Gem_Service::$fail = true;
$count = count($wpdb->sessions);
check(is_wp_error(Knowly_Quest_Service::start(52, 'quest-failed')), 'Debit error propagated');
check(count($wpdb->sessions) === $count, 'Failed debit session deleted');
check($wpdb->releases === 7, 'Locks released on successful and failed starts');
$same = Knowly_Quest_Service::start(52, 'quest-a', 'assignment', 1);
check($same['session_id'] === $c['session_id'], 'Matching assignment resumes');
$wpdb->task_column = false;
check(is_wp_error(Knowly_Quest_Service::start(52, 'quest-a', 'assignment', 3)), 'Missing task column fails closed');
$wpdb->task_column = true;
check(is_wp_error(Knowly_Quest_Service::start(52, 'quest-a', 'assignment', null)), 'Missing task fails closed');
$releases = $wpdb->releases; $wpdb->throws = true;
try { Knowly_Quest_Service::start(52, 'quest-a'); throw new LogicException('Expected DB exception'); }
catch (RuntimeException $e) { check($wpdb->releases === $releases + 1, 'Raw exception releases lock'); }
echo "Quest resume regression checks passed.\n";
