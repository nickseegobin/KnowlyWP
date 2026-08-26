<?php
/**
 * Knowly_Polly_Service — AWS Polly TTS → S3 → wp_knowly_quests.
 *
 * Per-paragraph flow (primary):
 *  1. Load quest content JSON from wp_knowly_quests.
 *  2. Extract explanation[para_idx] from sections[section_idx].
 *  3. POST to Polly StartSpeechSynthesisTask → receive TaskId.
 *  4. Poll GetSpeechSynthesisTask until completed (≤ 60 s).
 *  5. Resolve OutputUri → public HTTPS URL.
 *  6. Write URL back into content JSON at sections[section_idx].explanation_audio[para_idx].
 *
 * Audio URLs are stored inside the existing content JSON blob — no schema changes.
 * The REST API returns them transparently since it returns the full content object.
 *
 * Required WP options:
 *   knowly_aws_access_key   IAM access key ID
 *   knowly_aws_secret_key   IAM secret access key
 *   knowly_aws_region       e.g. us-east-1
 *   knowly_aws_s3_bucket    S3 bucket where Polly writes the MP3
 *
 * Optional WP options:
 *   knowly_aws_s3_prefix    Key prefix inside the bucket (default: knowly/audio)
 *   knowly_aws_cdn_url      CDN base URL — replaces the S3 HTTPS URL when set
 *   knowly_polly_voice_id   Polly voice ID (default: Joanna)
 *
 * IAM permissions required:
 *   polly:StartSpeechSynthesisTask, polly:GetSpeechSynthesisTask, polly:SynthesizeSpeech
 *   s3:PutObject, s3:GetBucketLocation on the target bucket
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Polly_Service {

    private const DEFAULT_VOICE  = 'Joanna';
    private const DEFAULT_ENGINE = 'neural';
    private const POLL_INTERVAL  = 2;   // seconds between status checks
    private const POLL_MAX       = 30;  // max poll iterations (≤ 60 s total)

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return per-paragraph audio overview for the Audio Manager admin UI.
     *
     * @param  string $quest_id
     * @return array|WP_Error  Array of section objects, each with a paras[] array.
     */
    public static function get_overview( string $quest_id ): array|WP_Error {
        $content = self::load_content( $quest_id );
        if ( is_wp_error( $content ) ) return $content;

        $sections  = $content['sections'] ?? [];
        $overview  = [];

        foreach ( $sections as $s_idx => $section ) {
            $explanations = $section['explanation']       ?? [];
            $audio_urls   = $section['explanation_audio'] ?? [];
            $marks_data   = $section['explanation_marks'] ?? [];
            $paras        = [];

            foreach ( $explanations as $p_idx => $text ) {
                $clean      = trim( strip_tags( $text ) );
                $char_count = strlen( $clean );
                $audio_url  = $audio_urls[ $p_idx ] ?? null;
                $marks      = $marks_data[ $p_idx ]  ?? null;

                $paras[] = [
                    'para_idx'   => $p_idx,
                    'preview'    => mb_substr( $clean, 0, 90 ) . ( mb_strlen( $clean ) > 90 ? '…' : '' ),
                    'char_count' => $char_count,
                    'cost'       => round( $char_count * 0.000016, 4 ),
                    'audio_url'  => $audio_url ?: null,
                    'has_audio'  => ! empty( $audio_url ),
                    'has_marks'  => ! empty( $marks ),
                    'marks_count'=> is_array( $marks ) ? count( $marks ) : 0,
                ];
            }

            $clips_ready = count( array_filter( $paras, fn( $p ) => $p['has_audio'] ) );

            $overview[] = [
                'section_idx' => $s_idx,
                'title'       => $section['title'] ?? ( 'Section ' . ( $s_idx + 1 ) ),
                'paras'       => $paras,
                'total_paras' => count( $paras ),
                'clips_ready' => $clips_ready,
                'total_chars' => array_sum( array_column( $paras, 'char_count' ) ),
            ];
        }

        return $overview;
    }

    /**
     * Generate audio for one explanation paragraph and write the URL back into
     * content JSON at sections[section_idx].explanation_audio[para_idx].
     *
     * @param  string $quest_id
     * @param  int    $section_idx  0-based index into content.sections[]
     * @param  int    $para_idx     0-based index into section.explanation[]
     * @return array|WP_Error       { quest_id, section_idx, para_idx, audio_url }
     */
    public static function generate_para( string $quest_id, int $section_idx, int $para_idx ): array|WP_Error {
        $cfg = self::get_config();
        if ( is_wp_error( $cfg ) ) return $cfg;

        $content = self::load_content( $quest_id, true );
        if ( is_wp_error( $content ) ) return $content;

        $text = $content['sections'][ $section_idx ]['explanation'][ $para_idx ] ?? '';
        $text = trim( strip_tags( $text ) );
        // Strip Lottie inline tags and [break] so Polly doesn't read them aloud
        $text = preg_replace( '/\[(start|next|m\d+(?:-loop)?|break)\]/i', '', $text );
        $text = preg_replace( '/\s{2,}/', ' ', trim( $text ) );

        if ( strlen( $text ) < 5 ) {
            return new WP_Error( 'knowly_no_content', "Section {$section_idx} paragraph {$para_idx} is empty.", [ 'status' => 422 ] );
        }

        // S3 key: prefix/quest_id/s{n}_p{m}  — Polly appends .{task_id}.mp3
        $s3_prefix = rtrim( $cfg['prefix'], '/' )
            . '/' . sanitize_file_name( $quest_id )
            . '/s' . $section_idx . '_p' . $para_idx;

        $task_id = self::dispatch_task( $text, $s3_prefix, $cfg );
        if ( is_wp_error( $task_id ) ) return $task_id;

        // Fetch word marks synchronously while the async MP3 task is running.
        $marks = self::fetch_marks( $text, $cfg );

        $output_uri = self::poll_task( $task_id, $cfg );
        if ( is_wp_error( $output_uri ) ) return $output_uri;

        $audio_url = self::resolve_url( $output_uri, $cfg );

        // Reload content fresh before writing — reduces the parallel-generation race window
        // from "entire Polly task duration" to "microseconds between load and save".
        $content = self::load_content( $quest_id );
        if ( is_wp_error( $content ) ) return $content;

        if ( ! isset( $content['sections'][ $section_idx ]['explanation_audio'] ) ) {
            $content['sections'][ $section_idx ]['explanation_audio'] = [];
        }
        $content['sections'][ $section_idx ]['explanation_audio'][ $para_idx ] = $audio_url;

        if ( ! is_wp_error( $marks ) && ! empty( $marks ) ) {
            if ( ! isset( $content['sections'][ $section_idx ]['explanation_marks'] ) ) {
                $content['sections'][ $section_idx ]['explanation_marks'] = [];
            }
            $content['sections'][ $section_idx ]['explanation_marks'][ $para_idx ] = $marks;
        }

        $save = self::save_content( $quest_id, $content );
        if ( is_wp_error( $save ) ) return $save;

        Knowly_Debug::log( 'polly.generate_para', 'Para audio + marks generated', [
            'quest_id'    => $quest_id,
            'section_idx' => $section_idx,
            'para_idx'    => $para_idx,
            'audio_url'   => $audio_url,
            'marks_count' => is_array( $marks ) ? count( $marks ) : 0,
        ], null, 'info' );

        return [
            'quest_id'    => $quest_id,
            'section_idx' => $section_idx,
            'para_idx'    => $para_idx,
            'audio_url'   => $audio_url,
            'marks_count' => is_array( $marks ) ? count( $marks ) : 0,
        ];
    }

    /**
     * Delete audio for one explanation paragraph (null out the slot).
     *
     * @param  string $quest_id
     * @param  int    $section_idx
     * @param  int    $para_idx
     * @return true|WP_Error
     */
    public static function delete_para( string $quest_id, int $section_idx, int $para_idx ): bool|WP_Error {
        $content = self::load_content( $quest_id );
        if ( is_wp_error( $content ) ) return $content;

        if ( isset( $content['sections'][ $section_idx ]['explanation_audio'][ $para_idx ] ) ) {
            $content['sections'][ $section_idx ]['explanation_audio'][ $para_idx ] = null;
            $save = self::save_content( $quest_id, $content );
            if ( is_wp_error( $save ) ) return $save;
        }

        return true;
    }

    /**
     * Migration path: generate word marks for a paragraph that already has an MP3.
     * Skips the async Polly task entirely — only calls SynthesizeSpeech for marks.
     *
     * @param  string $quest_id
     * @param  int    $section_idx
     * @param  int    $para_idx
     * @return array|WP_Error  { quest_id, section_idx, para_idx, marks_count }
     */
    public static function generate_marks_only( string $quest_id, int $section_idx, int $para_idx ): array|WP_Error {
        $cfg = self::get_config();
        if ( is_wp_error( $cfg ) ) return $cfg;

        $content = self::load_content( $quest_id );
        if ( is_wp_error( $content ) ) return $content;

        $text = $content['sections'][ $section_idx ]['explanation'][ $para_idx ] ?? '';
        $text = trim( strip_tags( $text ) );
        // Strip Lottie inline tags and [break] so word-timing marks align with clean text
        $text = preg_replace( '/\[(start|next|m\d+(?:-loop)?|break)\]/i', '', $text );
        $text = preg_replace( '/\s{2,}/', ' ', trim( $text ) );

        if ( strlen( $text ) < 5 ) {
            return new WP_Error( 'knowly_no_content', "Section {$section_idx} paragraph {$para_idx} is empty.", [ 'status' => 422 ] );
        }

        $marks = self::fetch_marks( $text, $cfg );
        if ( is_wp_error( $marks ) ) return $marks;
        if ( empty( $marks ) ) {
            return new WP_Error( 'knowly_no_marks', 'Polly returned no word marks for this text.', [ 'status' => 502 ] );
        }

        $content = self::load_content( $quest_id );
        if ( is_wp_error( $content ) ) return $content;

        if ( ! isset( $content['sections'][ $section_idx ]['explanation_marks'] ) ) {
            $content['sections'][ $section_idx ]['explanation_marks'] = [];
        }
        $content['sections'][ $section_idx ]['explanation_marks'][ $para_idx ] = $marks;

        $save = self::save_content( $quest_id, $content );
        if ( is_wp_error( $save ) ) return $save;

        Knowly_Debug::log( 'polly.generate_marks_only', 'Para marks generated (migration)', [
            'quest_id'    => $quest_id,
            'section_idx' => $section_idx,
            'para_idx'    => $para_idx,
            'marks_count' => count( $marks ),
        ], null, 'info' );

        return [
            'quest_id'    => $quest_id,
            'section_idx' => $section_idx,
            'para_idx'    => $para_idx,
            'marks_count' => count( $marks ),
        ];
    }

    /**
     * Return the character count of the training-only text for a decoded content array.
     * Used by the pool admin to show total Polly cost estimates per quest.
     */
    public static function training_char_count( array $content ): int {
        return strlen( self::extract_text( $content ) );
    }

    // ── Content helpers ───────────────────────────────────────────────────────

    /**
     * Load and decode the content JSON for a quest.
     *
     * @param  bool $approved_only  When true, requires status = 'approved'.
     */
    private static function load_content( string $quest_id, bool $approved_only = false ): array|WP_Error {
        global $wpdb;

        $where = $approved_only
            ? "WHERE quest_id = %s AND variant = 'student' AND status = 'approved'"
            : "WHERE quest_id = %s AND variant = 'student'";

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT content FROM {$wpdb->prefix}knowly_quests {$where}",
                $quest_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Quest not found.', [ 'status' => 404 ] );
        }

        $content = ! empty( $row['content'] ) ? json_decode( $row['content'], true ) : null;

        if ( ! $content ) {
            return new WP_Error( 'knowly_no_content', 'Quest has no content.', [ 'status' => 422 ] );
        }

        return $content;
    }

    /**
     * Write an updated content array back to wp_knowly_quests.
     */
    private static function save_content( string $quest_id, array $content ): true|WP_Error {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'knowly_quests',
            [
                'content'    => wp_json_encode( $content ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'quest_id' => $quest_id, 'variant' => 'student' ],
            [ '%s', '%s' ],
            [ '%s', '%s' ]
        );

        if ( $result === false ) {
            return new WP_Error( 'knowly_db_error', 'Failed to save content: ' . $wpdb->last_error, [ 'status' => 500 ] );
        }

        return true;
    }

    // ── Config ────────────────────────────────────────────────────────────────

    private static function get_config(): array|WP_Error {
        $access_key = get_option( 'knowly_aws_access_key', '' );
        $secret_key = get_option( 'knowly_aws_secret_key', '' );
        $region     = get_option( 'knowly_aws_region', 'us-east-1' );
        $bucket     = get_option( 'knowly_aws_s3_bucket', '' );

        if ( ! $access_key || ! $secret_key || ! $bucket ) {
            return new WP_Error(
                'knowly_aws_not_configured',
                'AWS credentials or S3 bucket are not configured. Visit Settings → AWS / Polly.',
                [ 'status' => 503 ]
            );
        }

        $prefix = rtrim( get_option( 'knowly_aws_s3_prefix', 'knowly/audio' ), '/' );

        return [
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'region'     => $region,
            'bucket'     => $bucket,
            'prefix'     => $prefix,
            'cdn_url'    => rtrim( get_option( 'knowly_aws_cdn_url', '' ), '/' ),
            'voice_id'   => get_option( 'knowly_polly_voice_id', self::DEFAULT_VOICE ),
        ];
    }

    // ── Text extraction ───────────────────────────────────────────────────────

    /**
     * Extract training-only text (titles + explanations + worked examples) for
     * the legacy single-quest audio method. Excludes knowledge_check entirely.
     * Capped at 100 000 characters (Polly StartSpeechSynthesisTask limit).
     */
    private static function extract_text( array $content ): string {
        $parts    = [];
        $sections = $content['sections'] ?? null;

        if ( is_array( $sections ) ) {
            foreach ( $sections as $section ) {
                if ( ! empty( $section['title'] ) && is_string( $section['title'] ) ) {
                    $clean = trim( strip_tags( $section['title'] ) );
                    if ( strlen( $clean ) > 2 ) $parts[] = $clean;
                }

                foreach ( (array) ( $section['explanation'] ?? [] ) as $item ) {
                    if ( is_string( $item ) ) {
                        $clean = trim( strip_tags( $item ) );
                        if ( strlen( $clean ) > 2 ) $parts[] = $clean;
                    }
                }

                foreach ( (array) ( $section['worked_examples'] ?? [] ) as $example ) {
                    foreach ( [ 'context', 'problem' ] as $key ) {
                        if ( ! empty( $example[ $key ] ) && is_string( $example[ $key ] ) ) {
                            $clean = trim( strip_tags( $example[ $key ] ) );
                            if ( strlen( $clean ) > 2 ) $parts[] = $clean;
                        }
                    }
                    foreach ( (array) ( $example['solution'] ?? [] ) as $step ) {
                        if ( is_string( $step ) ) {
                            $clean = trim( strip_tags( $step ) );
                            if ( strlen( $clean ) > 2 ) $parts[] = $clean;
                        }
                    }
                }
            }
        } else {
            self::walk( $content, $parts );
        }

        $text = implode( ' ', array_filter( array_map( 'trim', $parts ) ) );
        return substr( $text, 0, 100000 );
    }

    private static function walk( mixed $node, array &$parts ): void {
        if ( is_string( $node ) ) {
            $clean = trim( strip_tags( $node ) );
            if ( strlen( $clean ) > 2 ) $parts[] = $clean;
            return;
        }
        if ( ! is_array( $node ) ) return;

        $text_keys = [ 'title', 'heading', 'body', 'text', 'content', 'description', 'answer', 'label' ];

        foreach ( $text_keys as $key ) {
            if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
                $clean = trim( strip_tags( $node[ $key ] ) );
                if ( strlen( $clean ) > 2 ) $parts[] = $clean;
            }
        }

        foreach ( $node as $key => $value ) {
            if ( in_array( $key, $text_keys, true ) ) continue;
            self::walk( $value, $parts );
        }
    }

    // ── Polly API ─────────────────────────────────────────────────────────────

    /**
     * Core Polly dispatch — POST /v1/synthesisTasks with an explicit S3 key prefix.
     * Returns the Polly TaskId string.
     */
    private static function dispatch_task( string $text, string $s3_key_prefix, array $cfg ): string|WP_Error {
        $signer  = new Knowly_AWS_Signer( $cfg['access_key'], $cfg['secret_key'], $cfg['region'], 'polly' );
        $url     = "https://polly.{$cfg['region']}.amazonaws.com/v1/synthesisTasks";

        $payload = wp_json_encode( [
            'OutputFormat'       => 'mp3',
            'OutputS3BucketName' => $cfg['bucket'],
            'OutputS3KeyPrefix'  => $s3_key_prefix,
            'Text'               => $text,
            'TextType'           => 'text',
            'VoiceId'            => $cfg['voice_id'],
            'Engine'             => self::DEFAULT_ENGINE,
        ] );

        $headers  = $signer->get_signed_headers( 'POST', $url, [ 'Content-Type' => 'application/json' ], $payload );
        $response = wp_remote_post( $url, [ 'timeout' => 20, 'headers' => $headers, 'body' => $payload ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'knowly_polly_error', 'Polly unreachable: ' . $response->get_error_message(), [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = $body['message'] ?? $body['error'] ?? "Polly returned HTTP {$code}";
            Knowly_Debug::log( 'polly.dispatch_task', 'Polly start failed', [ 'code' => $code, 'body' => $body ], null, 'error' );
            return new WP_Error( 'knowly_polly_error', $msg, [ 'status' => 502 ] );
        }

        $task_id = $body['SynthesisTask']['TaskId'] ?? '';
        if ( ! $task_id ) {
            return new WP_Error( 'knowly_polly_error', 'Polly did not return a TaskId.', [ 'status' => 502 ] );
        }

        return $task_id;
    }

    /**
     * Synchronous SynthesizeSpeech call — returns word-level timestamps for $text.
     *
     * Uses the synchronous /v1/speech endpoint (not the async task API).
     * Response body is newline-delimited JSON; each line is one mark object.
     * We return only { time, value } — the millisecond offset and the word string.
     *
     * @return array[]|WP_Error  Array of { time: int (ms), value: string }
     */
    private static function fetch_marks( string $text, array $cfg ): array|WP_Error {
        $signer = new Knowly_AWS_Signer( $cfg['access_key'], $cfg['secret_key'], $cfg['region'], 'polly' );
        $url    = "https://polly.{$cfg['region']}.amazonaws.com/v1/speech";

        $payload = wp_json_encode( [
            'OutputFormat'   => 'json',
            'SpeechMarkTypes'=> [ 'word' ],
            'Text'           => $text,
            'TextType'       => 'text',
            'VoiceId'        => $cfg['voice_id'],
            'Engine'         => self::DEFAULT_ENGINE,
        ] );

        $headers  = $signer->get_signed_headers( 'POST', $url, [ 'Content-Type' => 'application/json' ], $payload );
        $response = wp_remote_post( $url, [ 'timeout' => 20, 'headers' => $headers, 'body' => $payload ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'knowly_polly_marks_error', 'Polly marks unreachable: ' . $response->get_error_message(), [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['message'] ?? "Polly marks returned HTTP {$code}";
            return new WP_Error( 'knowly_polly_marks_error', $msg, [ 'status' => 502 ] );
        }

        $marks = [];
        foreach ( explode( "\n", trim( wp_remote_retrieve_body( $response ) ) ) as $line ) {
            $line = trim( $line );
            if ( ! $line ) continue;
            $obj = json_decode( $line, true );
            if ( isset( $obj['time'], $obj['value'] ) ) {
                $marks[] = [ 'time' => (int) $obj['time'], 'value' => (string) $obj['value'] ];
            }
        }

        return $marks;
    }

    /**
     * Legacy helper used by the quest-level generate() method.
     */
    private static function start_task( string $text, string $quest_id, array $cfg ): string|WP_Error {
        $s3_prefix = $cfg['prefix']
            ? $cfg['prefix'] . '/' . sanitize_file_name( $quest_id )
            : sanitize_file_name( $quest_id );

        return self::dispatch_task( $text, $s3_prefix, $cfg );
    }

    /**
     * Poll GET /v1/synthesisTasks/{id} until completed.
     * Returns OutputUri (s3://…) on success.
     */
    private static function poll_task( string $task_id, array $cfg ): string|WP_Error {
        $signer = new Knowly_AWS_Signer( $cfg['access_key'], $cfg['secret_key'], $cfg['region'], 'polly' );
        $url    = "https://polly.{$cfg['region']}.amazonaws.com/v1/synthesisTasks/" . rawurlencode( $task_id );

        for ( $i = 0; $i < self::POLL_MAX; $i++ ) {
            if ( $i > 0 ) sleep( self::POLL_INTERVAL );

            $headers  = $signer->get_signed_headers( 'GET', $url, [], '' );
            $response = wp_remote_get( $url, [ 'timeout' => 10, 'headers' => $headers ] );

            if ( is_wp_error( $response ) ) continue;

            $body   = json_decode( wp_remote_retrieve_body( $response ), true );
            $task   = $body['SynthesisTask'] ?? [];
            $status = $task['TaskStatus']    ?? '';

            if ( $status === 'completed' ) {
                $output_uri = $task['OutputUri'] ?? '';
                if ( ! $output_uri ) {
                    return new WP_Error( 'knowly_polly_error', 'Polly task completed but OutputUri is missing.', [ 'status' => 502 ] );
                }
                return $output_uri;
            }

            if ( $status === 'failed' ) {
                return new WP_Error(
                    'knowly_polly_error',
                    'Polly task failed: ' . ( $task['TaskStatusReason'] ?? 'unknown reason' ),
                    [ 'status' => 502 ]
                );
            }
            // inProgress | scheduled — keep polling
        }

        return new WP_Error(
            'knowly_polly_timeout',
            'Polly task did not complete within the timeout. Try again in a moment.',
            [ 'status' => 504 ]
        );
    }

    // ── URL resolution ────────────────────────────────────────────────────────

    /**
     * Convert an s3://bucket/key URI to a public HTTPS URL.
     */
    private static function resolve_url( string $output_uri, array $cfg ): string {
        if ( ! str_starts_with( $output_uri, 's3://' ) ) {
            return $output_uri;
        }

        $without_scheme = substr( $output_uri, 5 );
        $slash_pos      = strpos( $without_scheme, '/' );
        $bucket         = $slash_pos !== false ? substr( $without_scheme, 0, $slash_pos ) : $without_scheme;
        $key            = $slash_pos !== false ? substr( $without_scheme, $slash_pos + 1 ) : '';

        if ( $cfg['cdn_url'] ) {
            return $cfg['cdn_url'] . '/' . $key;
        }

        return "https://{$bucket}.s3.{$cfg['region']}.amazonaws.com/{$key}";
    }

    // ── Legacy: single quest-level audio (retained for backwards compat) ──────

    /**
     * @deprecated  Use generate_para() instead. Generates one monolithic audio
     *              clip for the entire quest — kept for spec test coverage only.
     */
    public static function generate( string $quest_id ): array|WP_Error {
        $cfg = self::get_config();
        if ( is_wp_error( $cfg ) ) return $cfg;

        $content = self::load_content( $quest_id, true );
        if ( is_wp_error( $content ) ) return $content;

        $text = self::extract_text( $content );
        if ( strlen( trim( $text ) ) < 10 ) {
            return new WP_Error( 'knowly_no_content', 'Could not extract enough text from quest content.', [ 'status' => 422 ] );
        }

        $task_id = self::start_task( $text, $quest_id, $cfg );
        if ( is_wp_error( $task_id ) ) return $task_id;

        $output_uri = self::poll_task( $task_id, $cfg );
        if ( is_wp_error( $output_uri ) ) return $output_uri;

        $audio_url = self::resolve_url( $output_uri, $cfg );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'knowly_quests',
            [ 'audio_url' => $audio_url, 'audio_generated_at' => current_time( 'mysql' ) ],
            [ 'quest_id' => $quest_id, 'variant' => 'student' ],
            [ '%s', '%s' ],
            [ '%s', '%s' ]
        );

        Knowly_Debug::log( 'polly.generate', 'Legacy quest audio generated', [
            'quest_id'  => $quest_id,
            'audio_url' => $audio_url,
        ], null, 'info' );

        return [ 'quest_id' => $quest_id, 'audio_url' => $audio_url ];
    }
}
