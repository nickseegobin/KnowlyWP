<?php
/**
 * Knowly_Gem_Service — Blue gem wallet business logic.
 *
 * Model:
 *  - Parent buys gems (WooCommerce / Fygaro) → credited to parent wallet.
 *  - Parent allocates gems to a child → deducted from parent, credited to child.
 *  - Child spends gems on exams → deducted from child wallet.
 *  - Free-tier monthly refresh tops up parent wallets on the 1st of each month.
 *
 * Gem costs are stored as WP options (never hardcoded):
 *   knowly_gem_cost_{curriculum}_{difficulty}   — exam cost per curriculum+difficulty
 *   knowly_gem_free_monthly_{curriculum}         — monthly free grant per curriculum
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Gem_Service {

    // ── Option key helpers ────────────────────────────────────────────────────

    public static function cost_key( string $curriculum, string $difficulty ): string {
        return 'knowly_gem_cost_' . sanitize_key( $curriculum ) . '_' . sanitize_key( $difficulty );
    }

    public static function free_monthly_key( string $curriculum ): string {
        return 'knowly_gem_free_monthly_' . sanitize_key( $curriculum );
    }

    public static function quest_cost_key( string $curriculum, string $attempt_type ): string {
        return 'knowly_gem_cost_quest_' . sanitize_key( $attempt_type ) . '_' . sanitize_key( $curriculum );
    }

    // ── Cost lookup (reads WP options, falls back to safe defaults) ───────────

    public static function get_exam_cost( string $curriculum, string $difficulty ): int {
        $key     = self::cost_key( $curriculum, $difficulty );
        $stored  = get_option( $key );
        if ( $stored !== false && (int) $stored > 0 ) {
            return (int) $stored;
        }
        // Safe fallbacks — admin should always configure these in Gems Settings
        $fallbacks = [ 'easy' => 1, 'medium' => 2, 'hard' => 3 ];
        return $fallbacks[ $difficulty ] ?? 2;
    }

    public static function get_monthly_free( string $curriculum ): int {
        $key    = self::free_monthly_key( $curriculum );
        $stored = get_option( $key );
        if ( $stored !== false && (int) $stored >= 0 ) {
            return (int) $stored;
        }
        return 10; // Safe fallback
    }

    public static function get_quest_cost( string $curriculum, bool $is_retake = false ): int {
        $type   = $is_retake ? 'retake' : 'first';
        $key    = self::quest_cost_key( $curriculum, $type );
        $stored = get_option( $key );
        if ( $stored !== false && (int) $stored >= 0 ) {
            return (int) $stored;
        }
        return $is_retake ? 1 : 3;
    }

    public static function lesson_cost_key( string $curriculum ): string {
        return 'knowly_gem_cost_lesson_' . sanitize_key( $curriculum );
    }

    public static function get_lesson_cost( string $curriculum ): int {
        $key    = self::lesson_cost_key( $curriculum );
        $stored = get_option( $key );
        if ( $stored !== false && (int) $stored >= 0 ) {
            return (int) $stored;
        }
        return 3;
    }

    public static function signup_parent_key(): string {
        return 'knowly_gem_signup_parent';
    }

    public static function signup_teacher_key(): string {
        return 'knowly_gem_signup_teacher';
    }

    public static function teacher_monthly_key(): string {
        return 'knowly_gem_teacher_monthly';
    }

    public static function get_signup_parent(): int {
        $stored = get_option( self::signup_parent_key() );
        return $stored !== false ? max( 0, (int) $stored ) : 20;
    }

    public static function get_signup_teacher(): int {
        $stored = get_option( self::signup_teacher_key() );
        return $stored !== false ? max( 0, (int) $stored ) : 20;
    }

    public static function get_teacher_monthly(): int {
        $stored = get_option( self::teacher_monthly_key() );
        return $stored !== false ? max( 0, (int) $stored ) : 10;
    }

    // ── Balance ───────────────────────────────────────────────────────────────

    public static function get_balance( int $user_id ): int {
        return (int) get_user_meta( $user_id, 'knowly_gem_balance', true );
    }

    public static function has_enough( int $user_id, int $required = 1 ): bool {
        return self::get_balance( $user_id ) >= $required;
    }

    // ── Credit ────────────────────────────────────────────────────────────────

    /**
     * Credit gems to a user (parent or child).
     *
     * @param  int    $user_id      Target user.
     * @param  int    $amount       Gems to add (must be positive).
     * @param  string $type         Ledger type.
     * @param  string $curriculum   Curriculum context (for audit trail; may be empty).
     * @param  string $reference_id External reference (order ID, etc.).
     * @param  string $note         Human-readable note.
     * @return array{balance_before:int,balance_after:int}|WP_Error
     */
    public static function credit(
        int    $user_id,
        int    $amount,
        string $type         = 'admin_credit',
        string $curriculum   = '',
        string $reference_id = '',
        string $note         = ''
    ): array|WP_Error {
        if ( $amount <= 0 ) {
            return new WP_Error( 'knowly_invalid_amount', 'Credit amount must be greater than zero.', [ 'status' => 422 ] );
        }

        $balance_before = self::get_balance( $user_id );
        $balance_after  = $balance_before + $amount;

        update_user_meta( $user_id, 'knowly_gem_balance', $balance_after );

        self::write_ledger( $user_id, null, $type, $amount, $balance_after, $curriculum, $reference_id, $note );

        Knowly_Debug::log( 'gem.credit', 'Blue gems credited', [
            'user_id'        => $user_id,
            'amount'         => $amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'type'           => $type,
        ], $user_id, 'info' );

        return [ 'balance_before' => $balance_before, 'balance_after' => $balance_after ];
    }

    // ── Deduct ────────────────────────────────────────────────────────────────

    /**
     * Deduct gems from a user (atomic check-and-deduct).
     *
     * @param  int    $user_id      User whose balance to deduct from.
     * @param  int    $amount       Gems to deduct (positive).
     * @param  string $type         Ledger type.
     * @param  string $curriculum   Curriculum context.
     * @param  string $reference_id Session ID or other reference.
     * @param  string $note
     * @return array{balance_before:int,balance_after:int}|WP_Error
     */
    public static function deduct(
        int    $user_id,
        int    $amount,
        string $type         = 'spent',
        string $curriculum   = '',
        string $reference_id = '',
        string $note         = ''
    ): array|WP_Error {
        // Dev bypass
        if ( (bool) get_option( 'knowly_dev_bypass_gems', false ) ) {
            Knowly_Debug::log( 'gem.deduct', 'Dev bypass active — skipping deduction', [
                'user_id' => $user_id, 'amount' => $amount,
            ], $user_id, 'debug' );
            $balance = self::get_balance( $user_id );
            return [ 'balance_before' => $balance, 'balance_after' => $balance ];
        }

        $balance_before = self::get_balance( $user_id );

        if ( $balance_before < $amount ) {
            Knowly_Debug::log( 'gem.deduct', 'Insufficient gem balance', [
                'user_id'  => $user_id,
                'required' => $amount,
                'balance'  => $balance_before,
            ], $user_id, 'warning' );
            return new WP_Error( 'knowly_insufficient_gems', 'Not enough Blue Gems. Please purchase more to continue.', [
                'status'   => 402,
                'balance'  => $balance_before,
                'required' => $amount,
            ] );
        }

        $balance_after = $balance_before - $amount;
        update_user_meta( $user_id, 'knowly_gem_balance', $balance_after );

        self::write_ledger( $user_id, null, $type, -$amount, $balance_after, $curriculum, $reference_id, $note );

        Knowly_Debug::log( 'gem.deduct', 'Blue gems deducted', [
            'user_id'        => $user_id,
            'amount'         => $amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
        ], $user_id, 'info' );

        return [ 'balance_before' => $balance_before, 'balance_after' => $balance_after ];
    }

    // ── Allocate (parent → child) ─────────────────────────────────────────────

    /**
     * Transfer gems from a parent wallet to a child wallet.
     *
     * @param  int    $parent_id  Parent user ID.
     * @param  int    $child_id   Child user ID.
     * @param  int    $amount     Gems to allocate.
     * @return array{parent_balance:int,child_balance:int}|WP_Error
     */
    public static function allocate( int $parent_id, int $child_id, int $amount ): array|WP_Error {
        if ( $amount <= 0 ) {
            return new WP_Error( 'knowly_invalid_amount', 'Allocation amount must be greater than zero.', [ 'status' => 422 ] );
        }

        $parent_balance = self::get_balance( $parent_id );
        if ( $parent_balance < $amount ) {
            return new WP_Error( 'knowly_insufficient_gems', 'Not enough Blue Gems to allocate.', [
                'status'   => 402,
                'balance'  => $parent_balance,
                'required' => $amount,
            ] );
        }

        // Deduct from parent
        $new_parent_balance = $parent_balance - $amount;
        update_user_meta( $parent_id, 'knowly_gem_balance', $new_parent_balance );
        self::write_ledger( $parent_id, $child_id, 'parent_allocation', -$amount, $new_parent_balance, '', (string) $child_id, "Allocated to child {$child_id}" );

        // Credit to child
        $child_balance_before = self::get_balance( $child_id );
        $new_child_balance    = $child_balance_before + $amount;
        update_user_meta( $child_id, 'knowly_gem_balance', $new_child_balance );
        self::write_ledger( $child_id, $child_id, 'parent_allocation', $amount, $new_child_balance, '', (string) $parent_id, "Allocated by parent {$parent_id}" );

        Knowly_Debug::log( 'gem.allocate', 'Gems allocated parent → child', [
            'parent_id'         => $parent_id,
            'child_id'          => $child_id,
            'amount'            => $amount,
            'parent_balance'    => $new_parent_balance,
            'child_balance'     => $new_child_balance,
        ], $parent_id, 'info' );

        return [
            'parent_balance' => $new_parent_balance,
            'child_balance'  => $new_child_balance,
        ];
    }

    // ── Registration Grant ────────────────────────────────────────────────────

    /**
     * Initialise a new parent's gem wallet with the monthly free allotment.
     * Uses the default curriculum for grant calculation.
     */
    public static function grant_on_registration( int $parent_id ): void {
        if ( get_user_meta( $parent_id, 'knowly_gem_balance', true ) !== '' ) {
            return; // Already initialised
        }

        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $amount     = self::get_signup_parent();

        update_user_meta( $parent_id, 'knowly_gem_balance', $amount );
        update_user_meta( $parent_id, 'knowly_gem_refresh_date', date( 'Y-m-01' ) );

        self::write_ledger( $parent_id, null, 'signup_grant', $amount, $amount, $curriculum, '', 'Welcome — signup gem grant' );

        Knowly_Debug::log( 'gem.registration', 'Parent signup gems granted', [
            'parent_id'  => $parent_id,
            'amount'     => $amount,
            'curriculum' => $curriculum,
        ], $parent_id, 'info' );
    }

    public static function grant_on_registration_teacher( int $teacher_id ): void {
        if ( get_user_meta( $teacher_id, 'knowly_gem_balance', true ) !== '' ) {
            return; // Already initialised
        }

        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $amount     = self::get_signup_teacher();

        update_user_meta( $teacher_id, 'knowly_gem_balance', $amount );
        update_user_meta( $teacher_id, 'knowly_gem_refresh_date', date( 'Y-m-01' ) );

        self::write_ledger( $teacher_id, null, 'signup_grant', $amount, $amount, $curriculum, '', 'Welcome — teacher signup gem grant' );

        Knowly_Debug::log( 'gem.registration', 'Teacher signup gems granted', [
            'teacher_id' => $teacher_id,
            'amount'     => $amount,
            'curriculum' => $curriculum,
        ], $teacher_id, 'info' );
    }

    // ── Monthly Refresh ───────────────────────────────────────────────────────

    /**
     * Run the monthly free-tier gem refresh for all non-premium parents.
     * Hard overwrites balance to free-tier amount (mirrors token refresh behaviour).
     * Called by Knowly_Cron on the 1st of each month.
     *
     * @return int Number of accounts refreshed.
     */
    public static function run_monthly_refresh(): int {
        Knowly_Debug::log( 'gem.monthly_refresh', 'Monthly gem refresh started', [], null, 'info' );

        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $amount     = self::get_monthly_free( $curriculum );
        $this_month = date( 'Y-m-01' );
        $refreshed  = 0;

        $parents = get_users( [
            'role'       => 'knowly_parent',
            'fields'     => 'ID',
            'meta_query' => [
                [
                    'key'     => 'knowly_premium',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        foreach ( $parents as $parent_id ) {
            $last_refresh = get_user_meta( $parent_id, 'knowly_gem_refresh_date', true );
            if ( $last_refresh === $this_month ) {
                continue; // Already refreshed this month
            }

            update_user_meta( $parent_id, 'knowly_gem_balance', $amount );
            update_user_meta( $parent_id, 'knowly_gem_refresh_date', $this_month );

            self::write_ledger( $parent_id, null, 'monthly_refresh', $amount, $amount, $curriculum, $this_month, 'Monthly free gem reset' );

            $refreshed++;
        }

        // ── Teacher monthly refresh ───────────────────────────────────────────

        $teacher_amount = self::get_teacher_monthly();
        $teachers       = get_users( [
            'role'   => 'knowly_teacher',
            'fields' => 'ID',
        ] );

        foreach ( $teachers as $teacher_id ) {
            $last_refresh = get_user_meta( $teacher_id, 'knowly_gem_refresh_date', true );
            if ( $last_refresh === $this_month ) {
                continue;
            }

            update_user_meta( $teacher_id, 'knowly_gem_balance', $teacher_amount );
            update_user_meta( $teacher_id, 'knowly_gem_refresh_date', $this_month );

            self::write_ledger( $teacher_id, null, 'monthly_refresh', $teacher_amount, $teacher_amount, $curriculum, $this_month, 'Monthly teacher gem reset' );

            $refreshed++;
        }

        Knowly_Debug::log( 'gem.monthly_refresh', 'Monthly gem refresh complete', [
            'refreshed'       => $refreshed,
            'parent_amount'   => $amount,
            'teacher_amount'  => $teacher_amount,
            'curriculum'      => $curriculum,
            'month'           => $this_month,
        ], null, 'info' );

        return $refreshed;
    }

    // ── Ledger ────────────────────────────────────────────────────────────────

    public static function get_ledger( int $user_id, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_gem_transactions
                 WHERE user_id = %d
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function write_ledger(
        int     $user_id,
        ?int    $child_id,
        string  $type,
        int     $amount,
        int     $balance_after,
        string  $curriculum,
        string  $reference_id,
        string  $note
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'knowly_gem_transactions',
            [
                'user_id'      => $user_id,
                'child_id'     => $child_id,
                'type'         => $type,
                'amount'       => $amount,
                'balance_after' => $balance_after,
                'curriculum'   => $curriculum ?: null,
                'reference_id' => $reference_id ?: null,
                'note'         => $note ?: null,
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
    }
}
