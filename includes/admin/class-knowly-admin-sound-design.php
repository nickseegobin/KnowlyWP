<?php
/**
 * Knowly_Admin_Sound_Design — Admin settings page for the Tone.js synth.
 *
 * Exposes every FM synth + audio-chain parameter so you can experiment
 * with the chord sound without touching code. After saving, reload the
 * React app page for changes to take effect.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Sound_Design {

    public static function boot(): void {
        // nothing to hook beyond menu registration (done in Knowly_Admin)
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        if ( isset( $_POST['knowly_sd_nonce'] ) && wp_verify_nonce( $_POST['knowly_sd_nonce'], 'knowly_save_sound_design' ) ) {
            self::save();
            echo '<div class="notice notice-success"><p>Sound design settings saved. Reload the React app to hear changes.</p></div>';
        }

        $s = Knowly_Sound_Design_API::current_settings();
        $e = $s['envelope'];
        $m = $s['modulationEnvelope'];

        $wave_options = [ 'sine' => 'Sine', 'triangle' => 'Triangle', 'square' => 'Square', 'sawtooth' => 'Sawtooth' ];
        $dur_options  = [
            '32n' => '32nd (~0.06 s)',
            '16n' => '16th (~0.12 s)',
            '8n'  => 'Eighth (~0.25 s)',
            '4n'  => 'Quarter (~0.5 s)',
            '2n'  => 'Half (~1.0 s)',
            '1n'  => 'Whole (~2.0 s)',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Sound Design</h1>
            <p style="color:#777;margin-bottom:1.5em;">
                Tune the Tone.js FM synth that plays a chord at the end of each narration clip.
                Save here, then <strong>reload the React app page</strong> to hear the result.
                The React app fetches these settings once on page load.
            </p>

            <form method="post" action="">
                <?php wp_nonce_field( 'knowly_save_sound_design', 'knowly_sd_nonce' ); ?>

                <!-- ── General ──────────────────────────────────────────── -->
                <div class="knowly-settings-section">
                    <h2>General</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="knowly_sd_enabled">Enabled</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="knowly_sd_enabled" name="knowly_sd[enabled]" value="1"
                                           <?= checked( $s['enabled'], true, false ) ?> />
                                    Play chord progression during explanation slides
                                </label>
                                <p class="description">Uncheck to silence the synth without changing any other settings.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Synth Core ───────────────────────────────────────── -->
                <div class="knowly-settings-section">
                    <h2>FM Synth — Core</h2>
                    <p class="description" style="margin-bottom:12px;">
                        FM synthesis works by having a <em>modulator</em> oscillator warp the pitch of a <em>carrier</em> oscillator.
                        The ratio and depth of that warping determine the tonal character — tonal/bell-like vs. metallic/harsh.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><label for="knowly_sd_harmonicity">Harmonicity</label></th>
                            <td>
                                <?php self::slider_row( 'harmonicity', $s['harmonicity'], 0.5, 10.0, 0.1 ); ?>
                                <p class="description">Carrier ÷ modulator frequency ratio. Integers (2, 3, 5) = tonal; fractional (5.1, 3.01) = metallic shimmer. Steel pan target: ~5.1</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_sd_modulationIndex">Modulation Index</label></th>
                            <td>
                                <?php self::slider_row( 'modulationIndex', $s['modulationIndex'], 0, 100, 1 ); ?>
                                <p class="description">Depth of FM modulation — controls how many harmonics are created. Low (1–5) = soft/pure. High (20–50) = bright/metallic. Very high (70+) = harsh/noisy.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_sd_oscillatorType">Carrier Waveform</label></th>
                            <td>
                                <select id="knowly_sd_oscillatorType" name="knowly_sd[oscillatorType]">
                                    <?php foreach ( $wave_options as $val => $label ) : ?>
                                        <option value="<?= esc_attr( $val ) ?>" <?= selected( $s['oscillatorType'], $val, false ) ?>><?= esc_html( $label ) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Waveform of the main (carrier) oscillator. Sine = pure/bell. Triangle = warm/mellow. Square/Sawtooth = bright/buzzy.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_sd_modulationType">Modulator Waveform</label></th>
                            <td>
                                <select id="knowly_sd_modulationType" name="knowly_sd[modulationType]">
                                    <?php foreach ( $wave_options as $val => $label ) : ?>
                                        <option value="<?= esc_attr( $val ) ?>" <?= selected( $s['modulationType'], $val, false ) ?>><?= esc_html( $label ) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Waveform of the FM modulator. Sine = smooth harmonics. Square = gritty/electric. Mix with carrier waveform for character.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Volume Envelope ──────────────────────────────────── -->
                <div class="knowly-settings-section">
                    <h2>Volume Envelope (ADSR)</h2>
                    <p class="description" style="margin-bottom:12px;">
                        Controls how the note's <em>volume</em> changes over time. For a bell/steel-pan strike: near-zero attack, short decay, no sustain, longish release.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><label>Attack</label></th>
                            <td>
                                <?php self::slider_row( 'envelope[attack]', $e['attack'], 0.001, 2.0, 0.001 ); ?>
                                <p class="description">Time (seconds) to reach peak volume after the note is triggered. 0.001 = instant strike.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Decay</label></th>
                            <td>
                                <?php self::slider_row( 'envelope[decay]', $e['decay'], 0.01, 5.0, 0.01 ); ?>
                                <p class="description">Time to fall from peak to the sustain level. Short = sharp percussive hit. Long = drawn-out swell.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Sustain</label></th>
                            <td>
                                <?php self::slider_row( 'envelope[sustain]', $e['sustain'], 0.0, 1.0, 0.01 ); ?>
                                <p class="description">Volume level held while the note is active (0.0–1.0). 0 = no sustain (bell/marimba character). 1 = organ-like.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Release</label></th>
                            <td>
                                <?php self::slider_row( 'envelope[release]', $e['release'], 0.01, 10.0, 0.01 ); ?>
                                <p class="description">Time to fade to silence after the note ends. Longer = more ring/resonance.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Modulation Envelope ───────────────────────────────── -->
                <div class="knowly-settings-section">
                    <h2>Modulation Envelope (ADSR)</h2>
                    <p class="description" style="margin-bottom:12px;">
                        Controls how the <em>FM depth</em> changes over time — not the volume, but the timbral character. Fast decay here means the initial strike is bright/metallic and then softens quickly.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><label>Attack</label></th>
                            <td>
                                <?php self::slider_row( 'modulationEnvelope[attack]', $m['attack'], 0.001, 2.0, 0.001 ); ?>
                                <p class="description">Time until FM modulation reaches full depth. Instant = bright strike. Slower = swell-in shimmer.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Decay</label></th>
                            <td>
                                <?php self::slider_row( 'modulationEnvelope[decay]', $m['decay'], 0.01, 5.0, 0.01 ); ?>
                                <p class="description">How quickly the FM brightness decays after the initial strike. Keep short (0.1–0.3) for steel pan.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Sustain</label></th>
                            <td>
                                <?php self::slider_row( 'modulationEnvelope[sustain]', $m['sustain'], 0.0, 1.0, 0.01 ); ?>
                                <p class="description">Sustained FM depth level. 0 = modulation fully fades (pure tone tail). Higher = continuous metallic shimmer.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Release</label></th>
                            <td>
                                <?php self::slider_row( 'modulationEnvelope[release]', $m['release'], 0.01, 10.0, 0.01 ); ?>
                                <p class="description">Time for FM to fade after note ends. Keep shorter than the volume release for a natural decay.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Audio Chain ───────────────────────────────────────── -->
                <div class="knowly-settings-section">
                    <h2>Audio Chain</h2>
                    <table class="form-table">
                        <tr>
                            <th><label>Volume (dB)</label></th>
                            <td>
                                <?php self::slider_row( 'volume', $s['volume'], -40, 0, 1 ); ?>
                                <p class="description">Master output level. −10 dB is a good starting point — loud enough to notice, quiet enough not to startle.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Reverb Decay (s)</label></th>
                            <td>
                                <?php self::slider_row( 'reverbDecay', $s['reverbDecay'], 0.1, 10.0, 0.1 ); ?>
                                <p class="description">Length of the reverb tail in seconds. Longer = more cavernous/ethereal. 1.5–3.0 works well for a warm, spacious feel.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Reverb Wet Mix</label></th>
                            <td>
                                <?php self::slider_row( 'reverbWet', $s['reverbWet'], 0.0, 1.0, 0.05 ); ?>
                                <p class="description">Dry / wet blend (0 = no reverb, 1 = fully reverberant). 0.3–0.5 adds space without losing definition.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_sd_noteDuration">Note Duration</label></th>
                            <td>
                                <select id="knowly_sd_noteDuration" name="knowly_sd[noteDuration]">
                                    <?php foreach ( $dur_options as $val => $label ) : ?>
                                        <option value="<?= esc_attr( $val ) ?>" <?= selected( $s['noteDuration'], $val, false ) ?>><?= esc_html( "$val — $label" ) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">How long the synth holds the note before releasing. Shorter = quick ping. Longer = held chord. The envelope release then fades it naturally.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Arp Delay (s)</label></th>
                            <td>
                                <?php self::slider_row( 'arpDelay', $s['arpDelay'], 0.0, 0.3, 0.01 ); ?>
                                <p class="description">Time between each note in the arpeggio. 0 = all notes at once (chord). 0.06–0.10 = fast strum. 0.15–0.25 = slow roll.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Preview & Reset ──────────────────────────────────── -->
                <div class="knowly-settings-section" style="padding-bottom:0;">
                    <h2>Preview &amp; Reset</h2>
                    <p class="description" style="margin-bottom:12px;">
                        <strong>Preview</strong> plays Am → Bdim → C using the <em>current form values</em> — no need to save first.<br>
                        <strong>Reset to Defaults</strong> restores all sliders to the recommended starting point so you have a known baseline to experiment from.
                    </p>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <button type="button" id="knowly-sd-preview" class="button button-primary" style="font-size:14px;height:36px;padding:0 16px;" onclick="knowlyPreviewSound()">
                            ▶&nbsp; Preview Sound
                        </button>
                        <button type="button" class="button button-secondary" style="height:36px;padding:0 14px;" onclick="knowlyResetDefaults()">
                            ↺&nbsp; Reset to Defaults
                        </button>
                        <span id="knowly-sd-preview-status" style="color:#777;font-size:13px;"></span>
                    </div>
                </div>

                <div style="margin-top:2em;">
                    <?php submit_button( 'Save Sound Design' ); ?>
                </div>
            </form>

            <!-- Current settings as JSON (handy for debugging) -->
            <div class="knowly-settings-section">
                <h2>Current Settings (JSON)</h2>
                <p class="description" style="margin-bottom:8px;">The exact payload the React app receives from <code>GET /knowly/v1/sound-design</code>.</p>
                <textarea readonly rows="18" class="large-text" style="font-family:monospace;font-size:12px;"><?= esc_textarea( wp_json_encode( $s, JSON_PRETTY_PRINT ) ) ?></textarea>
            </div>
        </div>

        <style>
            .knowly-sd-row { display:flex; align-items:center; gap:10px; }
            .knowly-sd-row input[type=range]  { flex:1; max-width:280px; }
            .knowly-sd-row input[type=number] { width:80px; }
        </style>

        <!-- Tone.js — loaded only on this admin page for the preview button -->
        <script src="https://cdn.jsdelivr.net/npm/tone@15.1.22/build/Tone.js"></script>
        <script>
        // PHP defaults injected once — used by Reset button
        var KNOWLY_SD_DEFAULTS = <?= wp_json_encode( Knowly_Sound_Design_API::defaults() ) ?>;

        // ── Slider ↔ number sync ───────────────────────────────────────────────
        (function () {
            document.querySelectorAll('.knowly-sd-row').forEach(function (row) {
                var range  = row.querySelector('input[type=range]');
                var number = row.querySelector('input[type=number]');
                if (!range || !number) return;
                range.addEventListener('input',  function () { number.value = range.value; });
                number.addEventListener('input', function () {
                    var v = parseFloat(number.value);
                    if (!isNaN(v)) range.value = v;
                });
            });
        })();

        // ── Reset to defaults ─────────────────────────────────────────────────
        function knowlyResetDefaults() {
            var d = KNOWLY_SD_DEFAULTS;

            function setVal(attrName, value) {
                var num = document.querySelector('[name="' + attrName + '"]');
                if (!num) return;
                num.value = value;
                var row = num.closest('.knowly-sd-row');
                if (row) {
                    var range = row.querySelector('input[type=range]');
                    if (range) range.value = value;
                }
            }
            function setSel(attrName, value) {
                var el = document.querySelector('[name="' + attrName + '"]');
                if (el) el.value = value;
            }

            setVal('knowly_sd[harmonicity]',                      d.harmonicity);
            setVal('knowly_sd[modulationIndex]',                  d.modulationIndex);
            setSel('knowly_sd[oscillatorType]',                   d.oscillatorType);
            setSel('knowly_sd[modulationType]',                   d.modulationType);
            setVal('knowly_sd[envelope][attack]',                 d.envelope.attack);
            setVal('knowly_sd[envelope][decay]',                  d.envelope.decay);
            setVal('knowly_sd[envelope][sustain]',                d.envelope.sustain);
            setVal('knowly_sd[envelope][release]',                d.envelope.release);
            setVal('knowly_sd[modulationEnvelope][attack]',       d.modulationEnvelope.attack);
            setVal('knowly_sd[modulationEnvelope][decay]',        d.modulationEnvelope.decay);
            setVal('knowly_sd[modulationEnvelope][sustain]',      d.modulationEnvelope.sustain);
            setVal('knowly_sd[modulationEnvelope][release]',      d.modulationEnvelope.release);
            setVal('knowly_sd[volume]',                           d.volume);
            setVal('knowly_sd[reverbDecay]',                      d.reverbDecay);
            setVal('knowly_sd[reverbWet]',                        d.reverbWet);
            setSel('knowly_sd[noteDuration]',                     d.noteDuration);
            setVal('knowly_sd[arpDelay]',                         d.arpDelay);

            var status = document.getElementById('knowly-sd-preview-status');
            if (status) {
                status.textContent = 'Sliders reset to defaults';
                setTimeout(function () { status.textContent = ''; }, 2000);
            }
        }

        // ── Preview ────────────────────────────────────────────────────────────
        async function knowlyPreviewSound() {
            var btn    = document.getElementById('knowly-sd-preview');
            var status = document.getElementById('knowly-sd-preview-status');
            if (btn.disabled) return;

            // Guard: Tone.js CDN may be blocked by CSP or network
            if (typeof Tone === 'undefined') {
                status.textContent = '✗ Tone.js failed to load — check browser console for CSP errors';
                return;
            }

            btn.disabled  = true;
            btn.innerHTML = '⏳&nbsp; Playing…';
            status.textContent = '';

            try {
                status.textContent = 'Starting audio context…';
                await Tone.start();

                // Read current form values by full attribute name
                function fv(attrName) {
                    var el = document.querySelector('[name="' + attrName + '"]');
                    return el ? el.value : null;
                }
                function fnum(attrName, fallback) {
                    var v = parseFloat(fv(attrName));
                    return isNaN(v) ? fallback : v;
                }

                var harmonicity     = fnum('knowly_sd[harmonicity]',     5.1);
                var modulationIndex = fnum('knowly_sd[modulationIndex]', 20);
                var oscillatorType  = fv('knowly_sd[oscillatorType]')    || 'sine';
                var modulationType  = fv('knowly_sd[modulationType]')    || 'sine';
                var envAttack       = fnum('knowly_sd[envelope][attack]',              0.001);
                var envDecay        = fnum('knowly_sd[envelope][decay]',               0.5);
                var envSustain      = fnum('knowly_sd[envelope][sustain]',             0);
                var envRelease      = fnum('knowly_sd[envelope][release]',             1.0);
                var modAttack       = fnum('knowly_sd[modulationEnvelope][attack]',    0.001);
                var modDecay        = fnum('knowly_sd[modulationEnvelope][decay]',     0.15);
                var modSustain      = fnum('knowly_sd[modulationEnvelope][sustain]',   0);
                var modRelease      = fnum('knowly_sd[modulationEnvelope][release]',   0.3);
                var volume          = fnum('knowly_sd[volume]',      -10);
                var reverbDecay     = fnum('knowly_sd[reverbDecay]', 2.0);
                var reverbWet       = fnum('knowly_sd[reverbWet]',   0.35);
                var noteDuration    = fv('knowly_sd[noteDuration]')  || '4n';
                var arpDelay        = fnum('knowly_sd[arpDelay]', 0.08);

                // Note duration → seconds (at Tone.js default 120 BPM)
                var durMap = { '32n':0.063, '16n':0.125, '8n':0.25, '4n':0.5, '2n':1.0, '1n':2.0 };
                var durSec = durMap[noteDuration] || 0.5;

                // Build audio chain
                // Reverb.ready must be awaited — without it the IR is empty and the output is silent
                status.textContent = 'Generating reverb…';
                var reverb = new Tone.Reverb({ decay: reverbDecay, wet: reverbWet });
                await reverb.ready;
                var vol = new Tone.Volume(volume);
                vol.connect(reverb);
                reverb.toDestination();

                var synth = new Tone.PolySynth(Tone.FMSynth);
                synth.set({
                    harmonicity:        harmonicity,
                    modulationIndex:    modulationIndex,
                    oscillator:         { type: oscillatorType },
                    envelope:           { attack: envAttack, decay: envDecay, sustain: envSustain, release: envRelease },
                    modulation:         { type: modulationType },
                    modulationEnvelope: { attack: modAttack,  decay: modDecay,  sustain: modSustain, release: modRelease },
                });
                synth.connect(vol);

                // Am → Bdim → C  (tension → peak → resolution — same arc as the quest player)
                // Each chord is arpeggiated with arpDelay seconds between notes.
                var gap = durSec + 0.25;
                var chords = [['A3','C4','E4'], ['B3','D4','F4'], ['C4','E4','G4']];
                var now = Tone.now();
                chords.forEach(function (notes, ci) {
                    var chordStart = now + ci * gap;
                    notes.forEach(function (note, ni) {
                        synth.triggerAttackRelease(note, noteDuration, chordStart + ni * arpDelay);
                    });
                });

                status.textContent = 'Am → Bdim → C';

                // Clean up after the full ring-out
                var totalMs = (gap * 2 + durSec + Math.max(envRelease, modRelease) + 1.5) * 1000;
                setTimeout(function () {
                    try { synth.dispose(); vol.dispose(); reverb.dispose(); } catch(e) {}
                    btn.disabled  = false;
                    btn.innerHTML = '▶&nbsp; Preview Sound';
                    status.textContent = '';
                }, totalMs);

            } catch (err) {
                console.error('Knowly sound preview error:', err);
                btn.disabled  = false;
                btn.innerHTML = '▶&nbsp; Preview Sound';
                status.textContent = 'Error — see console';
            }
        }
        </script>
        <?php
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    private static function save(): void {
        $raw = $_POST['knowly_sd'] ?? [];
        if ( ! is_array( $raw ) ) return;

        // Flatten nested POST keys into the shape sanitize_and_save expects
        $p = [
            'enabled'         => ! empty( $raw['enabled'] ),
            'harmonicity'     => $raw['harmonicity']     ?? null,
            'modulationIndex' => $raw['modulationIndex'] ?? null,
            'oscillatorType'  => $raw['oscillatorType']  ?? null,
            'modulationType'  => $raw['modulationType']  ?? null,
            'envelope' => [
                'attack'  => $raw['envelope']['attack']  ?? null,
                'decay'   => $raw['envelope']['decay']   ?? null,
                'sustain' => $raw['envelope']['sustain'] ?? null,
                'release' => $raw['envelope']['release'] ?? null,
            ],
            'modulationEnvelope' => [
                'attack'  => $raw['modulationEnvelope']['attack']  ?? null,
                'decay'   => $raw['modulationEnvelope']['decay']   ?? null,
                'sustain' => $raw['modulationEnvelope']['sustain'] ?? null,
                'release' => $raw['modulationEnvelope']['release'] ?? null,
            ],
            'volume'       => $raw['volume']       ?? null,
            'reverbDecay'  => $raw['reverbDecay']  ?? null,
            'reverbWet'    => $raw['reverbWet']    ?? null,
            'noteDuration' => $raw['noteDuration'] ?? null,
            'arpDelay'     => $raw['arpDelay']     ?? null,
        ];

        Knowly_Sound_Design_API::sanitize_and_save( $p );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function slider_row( string $name, float $value, float $min, float $max, float $step ): void {
        $field_id = 'knowly_sd_' . str_replace( [ '[', ']' ], [ '_', '' ], $name );

        // "envelope[attack]" → "knowly_sd[envelope][attack]"  (proper PHP nested array notation)
        if ( strpos( $name, '[' ) !== false ) {
            $parts      = explode( '[', rtrim( $name, ']' ) );
            $field_name = 'knowly_sd[' . implode( '][', $parts ) . ']';
        } else {
            $field_name = 'knowly_sd[' . $name . ']';
        }
        printf(
            '<div class="knowly-sd-row">
                <input type="range"  id="%1$s" min="%3$s" max="%4$s" step="%5$s" value="%2$s" />
                <input type="number" name="%6$s" min="%3$s" max="%4$s" step="%5$s" value="%2$s" />
            </div>',
            esc_attr( $field_id ),
            esc_attr( $value ),
            esc_attr( $min ),
            esc_attr( $max ),
            esc_attr( $step ),
            esc_attr( $field_name )
        );
    }
}
