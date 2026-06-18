<?php
/**
 * Knowly_Sound_Design_API — REST endpoints for synth / audio-chain settings.
 *
 * GET  /knowly/v1/sound-design  Open.       React app fetches once per page load.
 * POST /knowly/v1/sound-design  Admin only. Mirrors the WP admin form save.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Sound_Design_API extends Knowly_API_Base {

    public function register_routes(): void {
        register_rest_route( KNOWLY_REST_NAMESPACE, '/sound-design', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'save_settings' ],
                'permission_callback' => [ $this, 'admin_permission' ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function get_settings( WP_REST_Request $request ): WP_REST_Response {
        return $this->success( self::current_settings() );
    }

    public function save_settings( WP_REST_Request $request ): WP_REST_Response {
        $saved = self::sanitize_and_save( $request->get_json_params() ?? [] );
        return $this->success( $saved );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function current_settings(): array {
        $stored = get_option( 'knowly_sound_design', [] );
        return array_replace_recursive( self::defaults(), is_array( $stored ) ? $stored : [] );
    }

    public static function defaults(): array {
        return [
            'enabled'         => true,
            'harmonicity'     => 5.1,
            'modulationIndex' => 20.0,
            'oscillatorType'  => 'sine',
            'modulationType'  => 'sine',
            'envelope' => [
                'attack'  => 0.001,
                'decay'   => 0.5,
                'sustain' => 0.0,
                'release' => 1.0,
            ],
            'modulationEnvelope' => [
                'attack'  => 0.001,
                'decay'   => 0.15,
                'sustain' => 0.0,
                'release' => 0.3,
            ],
            'volume'       => -10.0,
            'reverbDecay'  => 2.0,
            'reverbWet'    => 0.35,
            'noteDuration' => '4n',
            'arpDelay'     => 0.08,
        ];
    }

    public static function sanitize_and_save( array $p ): array {
        $d          = self::defaults();
        $wave_types = [ 'sine', 'triangle', 'square', 'sawtooth' ];
        $durations  = [ '32n', '16n', '8n', '4n', '2n', '1n' ];

        $env  = $p['envelope']            ?? [];
        $menv = $p['modulationEnvelope']  ?? [];
        $de   = $d['envelope'];
        $dme  = $d['modulationEnvelope'];

        $settings = [
            'enabled'         => (bool)  ( $p['enabled']         ?? $d['enabled'] ),
            'harmonicity'     => max( 0.5,  min( 10.0,  (float) ( $p['harmonicity']     ?? $d['harmonicity'] ) ) ),
            'modulationIndex' => max( 0.0,  min( 100.0, (float) ( $p['modulationIndex'] ?? $d['modulationIndex'] ) ) ),
            'oscillatorType'  => in_array( $p['oscillatorType'] ?? '', $wave_types, true ) ? $p['oscillatorType'] : $d['oscillatorType'],
            'modulationType'  => in_array( $p['modulationType'] ?? '', $wave_types, true ) ? $p['modulationType'] : $d['modulationType'],
            'envelope' => [
                'attack'  => max( 0.001, min( 2.0,  (float) ( $env['attack']  ?? $de['attack'] ) ) ),
                'decay'   => max( 0.01,  min( 5.0,  (float) ( $env['decay']   ?? $de['decay'] ) ) ),
                'sustain' => max( 0.0,   min( 1.0,  (float) ( $env['sustain'] ?? $de['sustain'] ) ) ),
                'release' => max( 0.01,  min( 10.0, (float) ( $env['release'] ?? $de['release'] ) ) ),
            ],
            'modulationEnvelope' => [
                'attack'  => max( 0.001, min( 2.0,  (float) ( $menv['attack']  ?? $dme['attack'] ) ) ),
                'decay'   => max( 0.01,  min( 5.0,  (float) ( $menv['decay']   ?? $dme['decay'] ) ) ),
                'sustain' => max( 0.0,   min( 1.0,  (float) ( $menv['sustain'] ?? $dme['sustain'] ) ) ),
                'release' => max( 0.01,  min( 10.0, (float) ( $menv['release'] ?? $dme['release'] ) ) ),
            ],
            'volume'       => max( -40.0, min( 0.0,  (float) ( $p['volume']       ?? $d['volume'] ) ) ),
            'reverbDecay'  => max( 0.1,   min( 10.0, (float) ( $p['reverbDecay']  ?? $d['reverbDecay'] ) ) ),
            'reverbWet'    => max( 0.0,   min( 1.0,  (float) ( $p['reverbWet']    ?? $d['reverbWet'] ) ) ),
            'noteDuration' => in_array( $p['noteDuration'] ?? '', $durations, true ) ? $p['noteDuration'] : $d['noteDuration'],
            'arpDelay'     => max( 0.0,   min( 0.3,  (float) ( $p['arpDelay']     ?? $d['arpDelay'] ) ) ),
        ];

        update_option( 'knowly_sound_design', $settings );
        return $settings;
    }
}
