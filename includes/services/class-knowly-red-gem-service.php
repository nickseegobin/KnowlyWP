<?php
/**
 * Knowly_Red_Gem_Service — Red gem wallet for teachers.
 *
 * Red gems are granted to teachers via a monthly stipend (admin-configurable).
 * Teachers spend red gems when creating assignments.
 * The monthly stipend reset hard-overwrites the balance on the 1st of each month.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Red_Gem_Service {

    // ── Balance ───────────────────────────────────────────────────────────────

    public static function get_balance( int $teacher_id ): int {
        return (int) get_user_meta( $teacher_id, 'knowly_red_gem_balance', true );
    }

    public static function has_enough( int $teacher_id, int $required = 1 ): bool {
        return self::get_balance( $teacher_id ) >= $required;
    }

    // ── Credit ────────────────────────────────────────────────────────────────

    /**
     * Credit red gems to a teacher.
     *
     * @param  int    $teacher_id   Teacher user ID.
     * @param  int    $amount       Gems to add (positive).
     * @param  string $type         Ledger type.
     * @param  string $reference_id External reference.
     * @param  string $note
     * @return array{balance_before:int,balance_after:int}|WP_Error
     */
    public static function credit(
        int    $teacher_id,
        int    $amount,
        string $type         = 'admin_credit',
        string $reference_id = '',
        string $note         = ''
    ): array|WP_Error {
        if ( $amount <= 0 ) {
            return new WP_Error( 'knowly_invalid_amount', 'Credit amount must be greater than zero.', [ 'status' => 422 ] );
        }

        $balance_before = self::get_balance( $teacher_id );
        $balance_after  = $balance_before + $amount;

        update_user_meta( $teacher_id, 'knowly_red_gem_balance', $balance_after );
        self::write_ledger( $teacher_id, $type, $amount, $balance_after, $reference_id, $note );

        Knowly_Debug::log( 'red_gem.credit', 'Red gems credited', [
            'teacher_id'     => $teacher_id,
            'amount'         => $amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'type'           => $type,
        ], $teacher_id, 'info' );

        return [ 'balance_before' => $balance_before, 'balance_after' => $balance_after ];
    }

    // ── Deduct ────────────────────────────────────────────────────────────────

    /**
     * Deduct red gems from a teacher (atomic check-and-deduct).
     *
     * @param  int    $teacher_id   Teacher user ID.
     * @param  int    $amount       Gems to deduct.
     * @param  string $type         Ledger type.
     * @param  string $reference_id Assignment ID or other reference.
     * @param  string $note
     * @return array{balance_before:int,balance_after:int}|WP_Error
     */
    public static function deduct(
        int    $teacher_id,
        int    $amount,
        string $type         = 'assignment_spent',
        string $reference_id = '',
        string $note         = ''
    ): array|WP_Error {
        $balance_before = self::get_balance( $teacher_id );

        if ( $balance_before < $amount ) {
            Knowly_Debug::log( 'red_gem.deduct', 'Insufficient red gem balance', [
                'teacher_id' => $teacher_id,
                'required'   => $amount,
                'balance'    => $balance_before,
            ], $teacher_id, 'warning' );
            return new WP_Error( 'knowly_insufficient_red_gems', 'Not enough Red Gems to create this assignment.', [
                'status'   => 402,
                'balance'  => $balance_before,
                'required' => $amount,
            ] );
        }

        $balance_after = $balance_before - $amount;
        update_user_meta( $teacher_id, 'knowly_red_gem_balance', $balance_after );
        self::write_ledger( $teacher_id, $type, -$amount, $balance_after, $reference_id, $note );

        Knowly_Debug::log( 'red_gem.deduct', 'Red gems deducted', [
            'teacher_id'     => $teacher_id,
            'amount'         => $amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
        ], $teacher_id, 'info' );

        return [ 'balance_before' => $balance_before, 'balance_after' => $balance_after ];
    }

    // ── Monthly Stipend Reset ─────────────────────────────────────────────────

    /**
     * Reset all approved teachers' red gem balance to the stipend amount.
     * Hard overwrite — unused gems do not roll over.
     * Called by Knowly_Cron on the 1st of each month.
     *
     * @return int Number of teachers reset.
     */
    public static function run_monthly_stipend_reset(): int {
        Knowly_Debug::log( 'red_gem.stipend_reset', 'Monthly red gem stipend reset started', [], null, 'info' );

        $default_stipend = (int) get_option( 'knowly_red_gem_stipend', 20 );
        $this_month      = date( 'Y-m-01' );
        $reset_count     = 0;

        $teachers = get_users( [
            'role'       => 'knowly_teacher',
            'fields'     => 'ID',
            'meta_query' => [
                [
                    'key'   => 'knowly_approval_status',
                    'value' => 'approved',
                ],
            ],
        ] );

        foreach ( $teachers as $teacher_id ) {
            $last_reset = get_user_meta( $teacher_id, 'knowly_red_gem_stipend_date', true );
            if ( $last_reset === $this_month ) {
                continue; // Already reset this month
            }

            // Use per-teacher stipend override if set, else global default
            $stipend = (int) get_user_meta( $teacher_id, 'knowly_red_gem_stipend', true );
            if ( $stipend <= 0 ) {
                $stipend = $default_stipend;
            }

            update_user_meta( $teacher_id, 'knowly_red_gem_balance', $stipend );
            update_user_meta( $teacher_id, 'knowly_red_gem_stipend_date', $this_month );

            self::write_ledger( $teacher_id, 'stipend_reset', $stipend, $stipend, $this_month, 'Monthly stipend reset' );

            $reset_count++;
        }

        Knowly_Debug::log( 'red_gem.stipend_reset', 'Monthly red gem stipend reset complete', [
            'reset_count'     => $reset_count,
            'default_stipend' => $default_stipend,
            'month'           => $this_month,
        ], null, 'info' );

        return $reset_count;
    }

    // ── Ledger ────────────────────────────────────────────────────────────────

    public static function get_ledger( int $teacher_id, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_red_gem_transactions
                 WHERE teacher_user_id = %d
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $teacher_id,
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function write_ledger(
        int    $teacher_id,
        string $type,
        int    $amount,
        int    $balance_after,
        string $reference_id,
        string $note
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'knowly_red_gem_transactions',
            [
                'teacher_user_id' => $teacher_id,
                'type'            => $type,
                'amount'          => $amount,
                'balance_after'   => $balance_after,
                'reference_id'    => $reference_id ?: null,
                'note'            => $note ?: null,
                'created_at'      => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );
    }
}
