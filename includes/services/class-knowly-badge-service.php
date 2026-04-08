<?php
/**
 * Knowly_Badge_Service — Badge award and retrieval.
 *
 * Badges are a WordPress concern — they live as a custom post type (knowly_badge)
 * and are stored in child user meta as knowly_earned_badges.
 *
 * Badge CPT posts are managed in WP Admin. Each badge has post meta:
 *   _knowly_quest_id — the quest_id this badge belongs to (unique per badge)
 *
 * Earned badges stored as user meta knowly_earned_badges:
 *   [ { "badge_id": 123, "quest_id": "quest-...", "awarded_at": "2026-04-07T..." }, ... ]
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Badge_Service {

    const META_KEY = 'knowly_earned_badges';

    // ── Award ─────────────────────────────────────────────────────────────────

    /**
     * Write a badge to a child's user meta.
     *
     * Finds the badge post whose _knowly_quest_id matches the given quest_id.
     * If no badge post exists for this Quest, the award is silently skipped —
     * the Quest is still complete, just without a badge icon.
     *
     * Idempotent — calling this twice for the same quest_id is a no-op.
     *
     * @param  int    $child_id
     * @param  string $quest_id
     * @return array|WP_Error  { badge_id, quest_id, awarded_at } or WP_Error if meta write fails.
     */
    public static function award( int $child_id, string $quest_id ): array|WP_Error {
        // Find the badge post for this quest
        $badge_id = self::find_badge_for_quest( $quest_id );

        if ( ! $badge_id ) {
            Knowly_Debug::log( 'badge.award', 'No badge post found for quest — skipping badge write', [
                'child_id' => $child_id,
                'quest_id' => $quest_id,
            ], $child_id, 'info' );
            return new WP_Error( 'knowly_badge_not_found', 'No badge configured for this Quest.', [ 'status' => 404 ] );
        }

        // Check if already awarded (idempotent)
        $earned = self::get_earned_raw( $child_id );
        foreach ( $earned as $entry ) {
            if ( ( $entry['quest_id'] ?? '' ) === $quest_id ) {
                return $entry; // Already awarded
            }
        }

        // Write to user meta
        $new_entry = [
            'badge_id'   => $badge_id,
            'quest_id'   => $quest_id,
            'awarded_at' => ( new DateTime() )->format( DATE_ATOM ),
        ];

        $earned[] = $new_entry;
        $updated  = update_user_meta( $child_id, self::META_KEY, wp_json_encode( $earned ) );

        if ( $updated === false ) {
            return new WP_Error( 'knowly_meta_error', 'Failed to write badge to user meta.', [ 'status' => 500 ] );
        }

        Knowly_Debug::log( 'badge.award', 'Badge awarded', [
            'child_id' => $child_id,
            'quest_id' => $quest_id,
            'badge_id' => $badge_id,
        ], $child_id, 'info' );

        return $new_entry;
    }

    // ── Get Earned ────────────────────────────────────────────────────────────

    /**
     * Return all earned badges for a child, with badge metadata resolved.
     *
     * @param  int $child_id
     * @return array  Array of { badge_id, quest_id, awarded_at, name, description, icon_url }
     */
    public static function get_earned( int $child_id ): array {
        $raw = self::get_earned_raw( $child_id );

        return array_values( array_filter( array_map( function ( array $entry ): ?array {
            $badge_id = (int) ( $entry['badge_id'] ?? 0 );
            if ( ! $badge_id ) return null;

            $post = get_post( $badge_id );
            if ( ! $post || $post->post_status !== 'publish' ) return null;

            return [
                'badge_id'    => $badge_id,
                'quest_id'    => $entry['quest_id'] ?? '',
                'awarded_at'  => $entry['awarded_at'] ?? '',
                'name'        => $post->post_title,
                'description' => get_the_excerpt( $post ),
                'icon_url'    => get_the_post_thumbnail_url( $post, 'thumbnail' ) ?: null,
            ];
        }, $raw ) ) );
    }

    // ── CPT Registration ──────────────────────────────────────────────────────

    /**
     * Register the knowly_badge custom post type.
     * Called on the init hook from Knowly_Core::boot().
     */
    public static function register_post_type(): void {
        register_post_type( 'knowly_badge', [
            'label'               => 'Badges',
            'labels'              => [
                'name'          => 'Badges',
                'singular_name' => 'Badge',
                'add_new_item'  => 'Add New Badge',
                'edit_item'     => 'Edit Badge',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'supports'            => [ 'title', 'excerpt', 'thumbnail' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function find_badge_for_quest( string $quest_id ): int {
        $posts = get_posts( [
            'post_type'      => 'knowly_badge',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_knowly_quest_id',
                    'value' => $quest_id,
                ],
            ],
        ] );

        return ! empty( $posts ) ? (int) $posts[0]->ID : 0;
    }

    private static function get_earned_raw( int $child_id ): array {
        $raw = get_user_meta( $child_id, self::META_KEY, true );
        if ( ! $raw ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
