<?php
namespace TEC_Addons;

if ( ! defined('ABSPATH') ) { exit; }

class Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_tec_addons_clear_last_mail_error', [__CLASS__, 'clear_last_mail_error']);
        add_action('admin_notices', [__CLASS__, 'maybe_show_cron_notice']);

        // Abonnenten-Aktionen
        add_action('admin_post_tec_addons_delete_subscriber', [__CLASS__, 'delete_subscriber']);
    }

    /* -------------------- Menü -------------------- */
    public static function menu() {
        add_menu_page(
            __('TEC add ons','tec-add-ons'),
            __('TEC add ons','tec-add-ons'),
            'manage_options',
            'tec-add-ons',
            [__CLASS__, 'render_welcome'],
            'dashicons-calendar-alt',
            56
        );

        add_submenu_page('tec-add-ons', __('Mailing','tec-add-ons'), __('Mailing','tec-add-ons'), 'manage_options', 'tec-add-ons-mailing', [__CLASS__, 'render_mailing']);
        add_submenu_page('tec-add-ons', __('Filterleiste','tec-add-ons'), __('Filterleiste','tec-add-ons'), 'manage_options', 'tec-add-ons-filter', [__CLASS__, 'render_filter']);
        add_submenu_page('tec-add-ons', __('Featured / Sortierung','tec-add-ons'), __('Featured / Sortierung','tec-add-ons'), 'manage_options', 'tec-add-ons-featured', [__CLASS__, 'render_featured']);
        add_submenu_page('tec-add-ons', __('Anzeige','tec-add-ons'), __('Anzeige','tec-add-ons'), 'manage_options', 'tec-add-ons-display', [__CLASS__, 'render_display']);
        add_submenu_page('tec-add-ons', __('Abonnenten','tec-add-ons'), __('Abonnenten','tec-add-ons'), 'manage_options', 'tec-add-ons-subscribers', [__CLASS__, 'render_subscribers']);
        add_submenu_page('tec-add-ons', __('Statistik','tec-add-ons'), __('Statistik','tec-add-ons'), 'manage_options', 'tec-add-ons-stats', [__CLASS__, 'render_stats']);
    }

    public static function render_welcome() {
        echo '<div class="wrap"><h1>TEC add ons</h1><p>Wähle links ein Untermenü.</p></div>';
    }

    /* -------------------- Settings -------------------- */
    public static function register_settings() {
        // MAILING
        register_setting('tec_addons_mailing', 'tec_addons_weekly_enabled');
        register_setting('tec_addons_mailing', 'tec_addons_monthly_enabled');
        register_setting('tec_addons_mailing', 'tec_addons_sender_name');
        register_setting('tec_addons_mailing', 'tec_addons_sender_email');
        register_setting('tec_addons_mailing', 'tec_addons_reply_to');
        register_setting('tec_addons_mailing', 'tec_addons_mail_logo_id');
        register_setting('tec_addons_mailing', 'tec_addons_mail_logo_url');
        register_setting('tec_addons_mailing', 'tec_addons_intro_weekly');
        register_setting('tec_addons_mailing', 'tec_addons_intro_monthly');
        register_setting('tec_addons_mailing', 'tec_addons_preheader_weekly');
        register_setting('tec_addons_mailing', 'tec_addons_preheader_monthly');
        register_setting('tec_addons_mailing', 'tec_addons_own_source_name');
        register_setting('tec_addons_mailing', 'tec_addons_own_bg_color');
        register_setting('tec_addons_mailing', 'tec_addons_batch_size');
        register_setting('tec_addons_mailing', 'tec_addons_batch_sleep');

        // FILTER BAR
        register_setting('tec_addons_filter', 'tec_addons_enable_filter_autoinject');
        register_setting('tec_addons_filter', 'tec_addons_filter_title');
        register_setting('tec_addons_filter', 'tec_addons_filter_show_counts');
        register_setting('tec_addons_filter', 'tec_addons_filter_bg');
        register_setting('tec_addons_filter', 'tec_addons_pinned_category_name');
        register_setting('tec_addons_filter', 'tec_addons_pinned_category_slug');

        // FEATURED FIRST
        register_setting('tec_addons_featured', 'tec_addons_enable_featured_first');

        // ANZEIGE
        register_setting('tec_addons_display', 'tec_addons_hide_zero_cost');

        // SUBSCRIBE (Anmeldung)
        register_setting('tec_addons_subscribe', 'tec_addons_confirm_url');
        register_setting('tec_addons_subscribe', 'tec_addons_mailhook_secret');
        register_setting('tec_addons_subscribe', 'tec_addons_sender_name');   // shared
        register_setting('tec_addons_subscribe', 'tec_addons_sender_email'); // shared
        register_setting('tec_addons_subscribe', 'tec_addons_reply_to');     // shared
        register_setting('tec_addons_subscribe', 'tec_addons_sources_custom'); // zusätzliche Quellen (CSV)
    }

    /* -------------------- Notices -------------------- */
    public static function maybe_show_cron_notice() {
        if ( defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ) {
            echo '<div class="notice notice-error"><p><strong>WP-Cron ist deaktiviert.</strong> Geplante Newsletter laufen so nicht automatisch. Bitte externen Cron aufrufen (z. B. <code>wp-cron.php</code>) oder WP-Cron aktivieren.</p></div>';
        }
    }

    /* -------------------- Mailing -------------------- */
    public static function render_mailing() {
        if ( ! current_user_can('manage_options') ) { return; }

        $tz     = wp_timezone_string();
        $w_next = wp_next_scheduled('tec_addons_cron_weekly');
        $m_next = wp_next_scheduled('tec_addons_cron_monthly');

        $logo_id  = (int) get_option('tec_addons_mail_logo_id', 0);
        $logo_url = (string) get_option('tec_addons_mail_logo_url', '');
        $preview  = '';

        if ($logo_id) {
            $img = wp_get_attachment_image_src($logo_id, 'medium');
            if ($img && is_array($img)) $preview = $img[0];
        } elseif ($logo_url) {
            $preview = $logo_url;
        }

        echo '<div class="wrap"><h1>Mailing</h1>';

        $last_err_raw = (string) get_option('tec_addons_last_mail_error', '');
        if ( trim($last_err_raw) !== '' ) {
            $data  = json_decode($last_err_raw, true);
            $time  = is_array($data) ? ($data['time']  ?? '') : '';
            $error = is_array($data) ? ($data['error'] ?? __('Mailversand fehlgeschlagen','tec-add-ons')) : __('Mailversand fehlgeschlagen','tec-add-ons');

            echo '<div class="notice notice-error" style="padding-bottom:10px;">';
            echo '<p><strong>Letzter Mailfehler:</strong> '.esc_html($error).($time?' <em>('.esc_html($time).')</em>':'').'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="tec_addons_clear_last_mail_error">';
            wp_nonce_field('tec_addons_clear_last_mail_error');
            echo '<button type="submit" class="button button-secondary">Fehleranzeige löschen</button></form>';
            echo '</div>';
        }

        echo '<h2 class="title">Zeitpläne</h2><ul>';
        echo '<li>Wöchentlich: '. ($w_next ? esc_html( wp_date('d.m.Y H:i:s', $w_next, wp_timezone()) ).' ('.$tz.')' : '<em>nicht geplant</em>') .'</li>';
        echo '<li>Monatlich: '.  ($m_next ? esc_html( wp_date('d.m.Y H:i:s', $m_next, wp_timezone()) ).' ('.$tz.')' : '<em>nicht geplant</em>') .'</li>';
        echo '</ul>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:10px 0;">';
        echo '<input type="hidden" name="action" value="tec_addons_reschedule">';
        wp_nonce_field('tec_addons_reschedule');
        echo '<button type="submit" class="button button-secondary">Zeitpläne jetzt neu einplanen</button>';
        echo '</form>';

        echo '<hr><h2>Einstellungen</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('tec_addons_mailing');

        echo '<table class="form-table" role="presentation">';

        // Flags
        echo '<tr><th scope="row">Automatischer Versand</th><td>';
        $weekly = get_option('tec_addons_weekly_enabled','yes')==='yes';
        $monthly= get_option('tec_addons_monthly_enabled','yes')==='yes';
        echo '<label><input type="checkbox" name="tec_addons_weekly_enabled" value="yes" '.checked($weekly,true,false).'> Wöchentlich aktiv</label><br>';
        echo '<label><input type="checkbox" name="tec_addons_monthly_enabled" value="yes" '.checked($monthly,true,false).'> Monatlich aktiv</label>';
        echo '</td></tr>';

        // Absender
        echo '<tr><th scope="row"><label for="tec_addons_sender_name">Absender-Name</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_sender_name" name="tec_addons_sender_name" type="text" value="'.esc_attr( get_option('tec_addons_sender_name','Kulturstiftung Seevetal') ).'"></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_sender_email">Absender-E-Mail</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_sender_email" name="tec_addons_sender_email" type="email" value="'.esc_attr( get_option('tec_addons_sender_email','') ).'"></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_reply_to">Reply-To</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_reply_to" name="tec_addons_reply_to" type="email" value="'.esc_attr( get_option('tec_addons_reply_to','') ).'"></td></tr>';

        // Branding
        echo '<tr><th scope="row">Logo</th><td>';
        echo '<label for="tec_addons_mail_logo_id">Medien-ID:&nbsp;</label>';
        echo '<input class="small-text" id="tec_addons_mail_logo_id" name="tec_addons_mail_logo_id" type="number" value="'.esc_attr( get_option('tec_addons_mail_logo_id','') ).'">';
        echo '<br><label for="tec_addons_mail_logo_url">Logo-URL:&nbsp;</label>';
        echo '<input class="regular-text" id="tec_addons_mail_logo_url" name="tec_addons_mail_logo_url" type="url" value="'.esc_attr( get_option('tec_addons_mail_logo_url','') ).'">';
        if ($preview) { echo '<p style="margin-top:8px;"><img src="'.esc_url($preview).'" alt="" style="max-height:48px"></p>'; }
        echo '</td></tr>';

        // Intros + Preheader
        echo '<tr><th scope="row"><label for="tec_addons_intro_weekly">Intro-Text (Woche)</label></th><td>';
        echo '<textarea class="large-text" rows="4" id="tec_addons_intro_weekly" name="tec_addons_intro_weekly">'.esc_textarea( get_option('tec_addons_intro_weekly','') ).'</textarea>';
        echo '<p class="description">HTML erlaubt (wird im Mail-Body gerendert).</p></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_intro_monthly">Intro-Text (Monat)</label></th><td>';
        echo '<textarea class="large-text" rows="4" id="tec_addons_intro_monthly" name="tec_addons_intro_monthly">'.esc_textarea( get_option('tec_addons_intro_monthly','') ).'</textarea></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_preheader_weekly">Preheader (Woche)</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_preheader_weekly" name="tec_addons_preheader_weekly" type="text" value="'.esc_attr( get_option('tec_addons_preheader_weekly','') ).'">';
        echo '<p class="description">Vorschautext (plain). Leer → aus Intro abgeleitet.</p></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_preheader_monthly">Preheader (Monat)</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_preheader_monthly" name="tec_addons_preheader_monthly" type="text" value="'.esc_attr( get_option('tec_addons_preheader_monthly','') ).'"></td></tr>';

        // Eigene Quelle + Farbe
        echo '<tr><th scope="row"><label for="tec_addons_own_source_name">„Eigene“ Quelle (Name)</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_own_source_name" name="tec_addons_own_source_name" type="text" value="'.esc_attr( get_option('tec_addons_own_source_name','Kulturstiftung Seevetal') ).'">';
        echo '<p class="description">Events mit dieser <em>Quelle</em> oder „Hervorgehobene Veranstaltungen“ werden grün hinterlegt.</p></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_own_bg_color">Hervorhebungsfarbe</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_own_bg_color" name="tec_addons_own_bg_color" type="text" value="'.esc_attr( get_option('tec_addons_own_bg_color','#e6ffe6') ).'" placeholder="#e6ffe6">';
        echo '</td></tr>';

        // Throttling
        echo '<tr><th scope="row">Versand-Drosselung</th><td>';
        echo '<label for="tec_addons_batch_size">Batchgröße:&nbsp;</label><input class="small-text" id="tec_addons_batch_size" name="tec_addons_batch_size" type="number" min="1" value="'.esc_attr( get_option('tec_addons_batch_size',10) ).'"> ';
        echo '<label for="tec_addons_batch_sleep">Pause (Sek.):&nbsp;</label><input class="small-text" id="tec_addons_batch_sleep" name="tec_addons_batch_sleep" type="number" min="0" value="'.esc_attr( get_option('tec_addons_batch_sleep',60) ).'">';
        echo '<p class="description">Für smtp.strato.de z. B. 10 / 60.</p></td></tr>';

        echo '</table>';
        submit_button(__('Einstellungen speichern','tec-add-ons'));
        echo '</form>';

        echo '<hr><h2>Versand auslösen</h2>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
        echo '<input type="hidden" name="action" value="tec_addons_test_weekly">';
        wp_nonce_field('tec_addons_test_weekly');
        echo '<button type="submit" class="button">Test-Wochenmail (an Admin)</button></form>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
        echo '<input type="hidden" name="action" value="tec_addons_test_monthly">';
        wp_nonce_field('tec_addons_test_monthly');
        echo '<button type="submit" class="button">Test-Monatsmail (an Admin)</button></form>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
        echo '<input type="hidden" name="action" value="tec_addons_send_weekly">';
        wp_nonce_field('tec_addons_send_weekly');
        echo '<button type="submit" class="button button-primary">Wochenmail jetzt senden</button></form>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
        echo '<input type="hidden" name="action" value="tec_addons_send_monthly">';
        wp_nonce_field('tec_addons_send_monthly');
        echo '<button type="submit" class="button button-primary">Monatsmail jetzt senden</button></form>';

        echo '<hr><h2>Versand-Protokoll</h2>';
        if ( class_exists('\TEC_Addons\Mailing') ) { \TEC_Addons\Mailing::render_log_table(50); }

        echo '</div>';
    }

    /* -------------------- FILTERLEISTE -------------------- */
    public static function render_filter() {
        if ( ! current_user_can('manage_options') ) { return; }
        echo '<div class="wrap"><h1>Filterleiste</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('tec_addons_filter');

        echo '<table class="form-table" role="presentation">';
        $auto = get_option('tec_addons_enable_filter_autoinject','yes')==='yes';
        echo '<tr><th scope="row">Automatisch einblenden</th><td>';
        echo '<label><input type="checkbox" name="tec_addons_enable_filter_autoinject" value="yes" '.checked($auto,true,false).'> Filterleiste automatisch oberhalb der TEC-Listenansicht einfügen</label>';
        echo '<p class="description">Alternativ Shortcode verwenden: <code>[tec_addons_filter]</code></p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_filter_title">Titel</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_filter_title" name="tec_addons_filter_title" type="text" value="'.esc_attr( get_option('tec_addons_filter_title', __('Kategorien','tec-add-ons')) ).'">';
        echo '</td></tr>';

        $cnt = get_option('tec_addons_filter_show_counts','yes')==='yes';
        echo '<tr><th scope="row">Zähler anzeigen</th><td>';
        echo '<label><input type="checkbox" name="tec_addons_filter_show_counts" value="yes" '.checked($cnt,true,false).'> Anzahl künftiger Veranstaltungen je Kategorie anzeigen</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_filter_bg">Hintergrundfarbe</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_filter_bg" name="tec_addons_filter_bg" type="text" placeholder="transparent" value="'.esc_attr( get_option('tec_addons_filter_bg','transparent') ).'">';
        echo '</td></tr>';

        echo '<tr><th scope="row">Fixierte Kategorie (oben)</th><td>';
        echo '<label for="tec_addons_pinned_category_name">Name:&nbsp;</label><input class="regular-text" id="tec_addons_pinned_category_name" name="tec_addons_pinned_category_name" type="text" value="'.esc_attr( get_option('tec_addons_pinned_category_name','eigene Veranstaltungen') ).'"> ';
        echo '<label for="tec_addons_pinned_category_slug">Slug:&nbsp;</label><input class="regular-text" id="tec_addons_pinned_category_slug" name="tec_addons_pinned_category_slug" type="text" value="'.esc_attr( get_option('tec_addons_pinned_category_slug','eigene-veranstaltungen') ).'">';
        echo '<p class="description">Diese Kategorie erscheint in der Filterleiste oben und ist standardmäßig aktiviert.</p>';
        echo '</td></tr>';

        echo '</table>';
        submit_button(__('Einstellungen speichern','tec-add-ons'));
        echo '</form>';

        echo '<hr><h2>Shortcode</h2><p><code>[tec_addons_filter]</code></p>';
        echo '</div>';
    }

    /* -------------------- FEATURED FIRST -------------------- */
    public static function render_featured() {
        if ( ! current_user_can('manage_options') ) { return; }
        echo '<div class="wrap"><h1>Featured / Sortierung</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('tec_addons_featured');

        $on = get_option('tec_addons_enable_featured_first','yes')==='yes';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Featured zuerst</th><td>';
        echo '<label><input type="checkbox" name="tec_addons_enable_featured_first" value="yes" '.checked($on,true,false).'> „Hervorgehobene Veranstaltungen“ immer zuerst listen</label>';
        echo '<p class="description">Wir werten das TEC-Flag und gängige Meta-Keys wie <code>_tribe_is_featured</code> aus.</p>';
        echo '</td></tr>';
        echo '</table>';

        submit_button(__('Einstellungen speichern','tec-add-ons'));
        echo '</form>';
        echo '</div>';
    }

    /* -------------------- ANZEIGE -------------------- */
    public static function render_display() {
        if ( ! current_user_can('manage_options') ) { return; }
        echo '<div class="wrap"><h1>Anzeige</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('tec_addons_display');

        $on = get_option('tec_addons_hide_zero_cost','yes')==='yes';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Preisangabe</th><td>';
        echo '<label><input type="checkbox" name="tec_addons_hide_zero_cost" value="yes" '.checked($on,true,false).'> „Kostenlos“ nicht anzeigen</label>';
        echo '<p class="description">The Events Calendar schreibt „Kostenlos“ in den Kopf der Veranstaltungsseite, sobald der Preis auf null hinausläuft – auch bei Reservierungen ohne Preis. Echte Beträge bleiben in jedem Fall stehen.</p>';
        echo '</td></tr>';
        echo '</table>';

        submit_button(__('Einstellungen speichern','tec-add-ons'));
        echo '</form>';
        echo '</div>';
    }

    /* -------------------- ABONNENTEN -------------------- */
    public static function render_subscribers() {
        if ( ! current_user_can('manage_options') ) { return; }
        echo '<div class="wrap"><h1>Abonnenten</h1>';

        // Einstellungen (Bestätigungsseite, Hook, zusätzliche Quellen)
        echo '<h2>Einstellungen</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('tec_addons_subscribe');
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="tec_addons_confirm_url">Bestätigungsseite (URL)</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_confirm_url" name="tec_addons_confirm_url" type="url" value="'.esc_attr( get_option('tec_addons_confirm_url','') ).'">';
        echo '<p class="description">Wird nach erfolgreicher Bestätigung angezeigt.</p></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_mailhook_secret">Mailhook Secret</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_mailhook_secret" name="tec_addons_mailhook_secret" type="text" value="'.esc_attr( get_option('tec_addons_mailhook_secret','') ).'">';
        echo '<p class="description">Für die (optionale) IMAP/Reply-Auswertung via REST.</p></td></tr>';

        echo '<tr><th scope="row"><label for="tec_addons_sources_custom">Zusätzliche Quellen (CSV)</label></th><td>';
        echo '<input class="regular-text" id="tec_addons_sources_custom" name="tec_addons_sources_custom" type="text" value="'.esc_attr( get_option('tec_addons_sources_custom','Empore Buchholz, Musik in alten Heidekirchen, Kulturverein Winsen') ).'">';
        echo '<p class="description">Diese werden im Anmeldeformular zusätzlich zu den TEC-Quellen angeboten.</p></td></tr>';

        echo '</table>';
        submit_button(__('Einstellungen speichern','tec-add-ons'));
        echo '</form>';

        // Liste der Abonnenten
        echo '<hr><h2>Liste</h2>';

        global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';
        if ( $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table ) {
            if ( class_exists('\TEC_Addons\Subscribe') && method_exists('\TEC_Addons\Subscribe','maybe_install_tables') ) {
                \TEC_Addons\Subscribe::maybe_install_tables();
            }
        }
        $rows = $wpdb->get_results("SELECT id,email,name,frequency,status,created_at,updated_at FROM {$table} ORDER BY id DESC LIMIT 200");

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>E-Mail</th><th>Name</th><th>Häufigkeit</th><th>Status</th><th>Erstellt</th><th>Aktualisiert</th><th>Aktionen</th></tr></thead><tbody>';
        if ($rows) {
            foreach ($rows as $r) {
                $del_url = wp_nonce_url( admin_url('admin-post.php?action=tec_addons_delete_subscriber&id='.(int)$r->id), 'tec_addons_delete_subscriber_'.$r->id );
                echo '<tr>';
                echo '<td>'.(int)$r->id.'</td>';
                echo '<td>'.esc_html($r->email).'</td>';
                echo '<td>'.esc_html($r->name).'</td>';
                echo '<td>'.esc_html($r->frequency).'</td>';
                echo '<td>'.esc_html($r->status).'</td>';
                echo '<td>'.esc_html($r->created_at).'</td>';
                echo '<td>'.esc_html($r->updated_at).'</td>';
                echo '<td><a class="button-link delete" href="'.esc_url($del_url).'" onclick="return confirm(\'Wirklich löschen?\');">Löschen</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="8"><em>Keine Abonnenten gefunden.</em></td></tr>';
        }
        echo '</tbody></table>';

        echo '<p class="description">Shortcode für die Anmeldung: <code>[tec_addons_subscribe]</code></p>';
        echo '</div>';
    }

    public static function delete_subscriber() {
        if ( ! current_user_can('manage_options') ) { wp_die('no'); }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('tec_addons_delete_subscriber_'.$id);
        if ($id>0){
            global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';
            $wpdb->delete($table, ['id'=>$id], ['%d']);
        }
        wp_safe_redirect( admin_url('admin.php?page=tec-add-ons-subscribers') ); exit;
    }

    /* -------------------- Statistik -------------------- */
    public static function render_stats() {
        echo '<div class="wrap"><h1>Statistik</h1>';
        if ( class_exists('\TEC_Addons\Stats') && method_exists('\TEC_Addons\Stats','render_admin') ) {
            \TEC_Addons\Stats::render_admin();
        } else {
            echo '<h2>Mailversand – letzte 50 Läufe</h2>';
            if ( class_exists('\TEC_Addons\Mailing') ) {
                \TEC_Addons\Mailing::render_log_table(50);
            } else {
                echo '<p>—</p>';
            }
        }
        echo '</div>';
    }

    /* -------------------- Fehleranzeige löschen -------------------- */
    public static function clear_last_mail_error() {
        if ( ! current_user_can('manage_options') ) { wp_die('no'); }
        check_admin_referer('tec_addons_clear_last_mail_error');
        delete_option('tec_addons_last_mail_error');
        update_option('tec_addons_last_mail_error', '', false);
        wp_safe_redirect( admin_url('admin.php?page=tec-add-ons-mailing') ); exit;
    }
}
