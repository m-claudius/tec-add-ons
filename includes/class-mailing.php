<?php
namespace TEC_Addons;

if ( ! defined('ABSPATH') ) { exit; }

// Doppeldefinition vermeiden
if (class_exists(__NAMESPACE__.'\\Mailing', false)) { return; }

class Mailing {

    /* ================= Bootstrap ================= */
    public static function init(): void {
        // Admin actions (wie vorher)
        add_action('admin_post_tec_addons_test_weekly',   [__CLASS__, 'test_weekly']);
        add_action('admin_post_tec_addons_test_monthly',  [__CLASS__, 'test_monthly']);
        add_action('admin_post_tec_addons_send_weekly',   [__CLASS__, 'send_weekly_now']);
        add_action('admin_post_tec_addons_send_monthly',  [__CLASS__, 'send_monthly_now']);
        add_action('admin_post_tec_addons_reschedule',    [__CLASS__, 'reschedule_now']);

        // Cron hooks
        add_action('tec_addons_cron_weekly',              [__CLASS__, 'cron_weekly']);
        add_action('tec_addons_cron_monthly',             [__CLASS__, 'cron_monthly']);

        // Toggle hooks (Signatur: old_value, value, option)
        add_action('update_option_tec_addons_weekly_enabled',  [__CLASS__, 'on_toggle_weekly'], 10, 3);
        add_action('update_option_tec_addons_monthly_enabled', [__CLASS__, 'on_toggle_monthly'], 10, 3);

        // wp_mail Fehler loggen
        add_action('wp_mail_failed', [__CLASS__, 'on_mail_failed'], 10, 1);
        add_action('admin_notices', [__CLASS__, 'render_mailing_quick_actions']); // <— NEU

        // Tabellen anlegen (inkl. Back-Compat)
        self::maybe_install_tables();

        self::maybe_schedule_cron();
    }

    /* ================= Optionen/Flags ================= */
    protected static function weekly_enabled(): bool  { return get_option('tec_addons_weekly_enabled','yes')  === 'yes'; }
    protected static function monthly_enabled(): bool { return get_option('tec_addons_monthly_enabled','yes') === 'yes'; }

    /* ================= Zeit / Helper ================= */
    protected static function tz(): \DateTimeZone {
        if (function_exists('wp_timezone')) {
            $tz = wp_timezone();
            if ($tz instanceof \DateTimeZone) return $tz;
        }
        $tz_string = (string) get_option('timezone_string');
        if ($tz_string !== '') { try { return new \DateTimeZone($tz_string); } catch (\Throwable $e) {} }
        return new \DateTimeZone('UTC');
    }
    protected static function now(): \DateTimeImmutable { return new \DateTimeImmutable('now', self::tz()); }
    protected static function fmt(\DateTimeInterface $dt): string { return $dt->format('Y-m-d H:i:s'); }

    protected static function next_saturday_1am(?\DateTimeImmutable $ref = null): \DateTimeImmutable {
        $ref = $ref ?: self::now();
        // nächster Samstag 01:00 (immer in der Zukunft)
        $cand = $ref->setTime(1,0);
        while ((int)$cand->format('N') !== 6 || $cand <= $ref) { $cand = $cand->modify('+1 day'); }
        return $cand;
    }
    protected static function last_saturday_of_month_1am(?\DateTimeImmutable $ref = null): \DateTimeImmutable {
        $ref = $ref ?: self::now();
        $firstNext = (new \DateTimeImmutable($ref->format('Y-m-01').' 01:00:00', self::tz()))->modify('first day of next month');
        $last = $firstNext->modify('-1 day');
        while ((int)$last->format('N') !== 6) { $last = $last->modify('-1 day'); }
        return $last;
    }
    protected static function next_weekly_timestamp(): int  { return self::next_saturday_1am()->getTimestamp(); }
    protected static function next_monthly_timestamp(): int {
        $now = self::now(); $ref = self::last_saturday_of_month_1am($now);
        if ($ref <= $now) $ref = self::last_saturday_of_month_1am($now->modify('first day of next month'));
        return $ref->getTimestamp();
    }

    // Mail-Zeiträume (bestehendes Verhalten)
    protected static function window_weekly_live(?\DateTimeImmutable $ref = null): array {
        $anchor = self::next_saturday_1am($ref);
        $start  = $anchor->setTime(0,0,0);
        $end    = $start->modify('+9 days')->setTime(23,59,59);
        return [ self::fmt($start), self::fmt($end) ];
    }
    protected static function window_monthly_live(?\DateTimeImmutable $ref = null): array {
        $anchor = self::last_saturday_of_month_1am($ref ?: self::now());
        $start  = $anchor->setTime(0,0,0);
        $end    = $start->modify('+42 days')->setTime(23,59,59);
        return [ self::fmt($start), self::fmt($end) ];
    }
    protected static function window_weekly_test(): array  { return self::window_weekly_live( self::next_saturday_1am() ); }
    protected static function window_monthly_test(): array { return self::window_monthly_live( self::last_saturday_of_month_1am() ); }

    /* ================= Locking & Run-Key (Duplikatschutz) ================= */
    protected static function acquire_lock(string $key, int $ttl = 7200): bool {
        $lock = 'tec_addons_lock_'.$key;
        if ( get_transient($lock) ) return false;
        set_transient($lock, 1, $ttl);
        return true;
    }
    protected static function release_lock(string $key): void { delete_transient('tec_addons_lock_'.$key); }

    protected static function run_key_for(string $type): string {
        if ($type === 'weekly') {
            $ref = self::next_saturday_1am()->setTime(1,0,0);
            return 'weekly-'.$ref->format('Ymd-His');
        }
        $ref = self::last_saturday_of_month_1am()->setTime(1,0,0);
        return 'monthly-'.$ref->format('Ymd-His');
    }

    public static function on_mail_failed($wp_error): void {
        update_option('tec_addons_last_mail_error', is_wp_error($wp_error) ? $wp_error->get_error_message() : (string) $wp_error);
    }

    /* ================= DB-Setup ================= */
    public static function maybe_install_tables(): void {
        global $wpdb;
        $log_table  = $wpdb->prefix.'tec_addons_mail_log';
        $sent_table = $wpdb->prefix.'tec_addons_mail_sent';

        $log_exists  = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $log_table) )  === $log_table;
        $sent_exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $sent_table) ) === $sent_table;
        if ( $log_exists && $sent_exists ) return;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE {$log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_key VARCHAR(64) NOT NULL,
            run_type VARCHAR(16) NOT NULL,
            triggered_by VARCHAR(16) NOT NULL,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            recipients INT NOT NULL DEFAULT 0,
            success_cnt INT NOT NULL DEFAULT 0,
            error_cnt INT NOT NULL DEFAULT 0,
            errors LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_run_key (run_key)
        ) {$charset};";

        $sql2 = "CREATE TABLE {$sent_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_key VARCHAR(64) NOT NULL,
            email VARCHAR(190) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'sent',
            error TEXT NULL,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_run_email (run_key,email),
            KEY idx_email (email)
        ) {$charset};";

        dbDelta($sql1);
        dbDelta($sql2);
    }
    // Back-Compat für deinen Bootstrap:
    public static function maybe_install_log_table(): void  { self::maybe_install_tables(); }
    public static function maybe_install_sent_table(): void { self::maybe_install_tables(); }

    /* ================= Datenquellen ================= */
    protected static function get_active_subscribers(string $frequency): array {
        global $wpdb; 
        $table=$wpdb->prefix.'tec_addons_subscribers';
        $rows = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table} WHERE status=%s AND frequency=%s", 'active', $frequency) );
        return is_array($rows) ? $rows : [];
    }

    /* ================= Rendering & Mail ================= */
    protected static function current_admin_email(): string {
        $u = wp_get_current_user(); 
        return ($u && $u->user_email) ? $u->user_email : (string) get_option('admin_email');
    }

    protected static function logo_url(): string {
        // 1) Option: Attach-ID
        $id = (int) get_option('tec_addons_mail_logo_id', 0);
        if ($id) {
            $src = wp_get_attachment_image_src($id, 'full');
            if (is_array($src) && !empty($src[0])) return (string)$src[0];
        }
        // 2) Option: Direkte URL
        $url = (string) get_option('tec_addons_mail_logo_url','');
        if ($url !== '') return $url;
        // 3) Theme Custom Logo
        $custom_logo = (int) get_theme_mod('custom_logo');
        if ($custom_logo) {
            $img = wp_get_attachment_image_src($custom_logo, 'full');
            if (is_array($img) && !empty($img[0])) return (string)$img[0];
        }
        return '';
    }

    protected static function greeting(array $subscriber): string {
        $name = isset($subscriber['name']) ? trim((string)$subscriber['name']) : '';
        if ($name !== '') return 'Hallo '.esc_html($name);
        return 'Hallo';
    }

    // Liefert HTML (bereits sanitisiert) für Weekly/Monthly-Intro
    protected static function intro_text(string $kind): string {
        // 1) Häufig verwendete Options-Keys (alle geprüft, erster Treffer gewinnt)
        $keys_weekly  = [
            'tec_addons_weekly_intro_html',
            'tec_addons_weekly_intro_text',
            'tec_addons_weekly_intro',
            'tec_addons_mail_intro_weekly',
            'tec_addons_intro_weekly',
            'tec_addons_intro_text_weekly',
        ];
        $keys_monthly = [
            'tec_addons_monthly_intro_html',
            'tec_addons_monthly_intro_text',
            'tec_addons_monthly_intro',
            'tec_addons_mail_intro_monthly',
            'tec_addons_intro_monthly',
            'tec_addons_intro_text_monthly',
        ];
        $keys = ($kind === 'monthly') ? $keys_monthly : $keys_weekly;

        foreach ($keys as $k) {
            $v = get_option($k, '');
            if (is_string($v) && trim($v) !== '') {
                // erlaubt einfaches HTML + Absätze
                $v = wpautop($v);
                return apply_filters('tec_addons_mailing_intro_text', wp_kses_post($v), $kind, $k);
            }
        }

        // 2) Optional: Page/Beitrag per ID in Option (kompatibel zu Alt-Versionen)
        $pid = (int) get_option('tec_addons_'.$kind.'_intro_post_id', 0);
        if ($pid > 0) {
            $content = get_post_field('post_content', $pid);
            if (is_string($content) && trim($content) !== '') {
                $content = do_shortcode($content);
                $content = wpautop($content);
                return apply_filters('tec_addons_mailing_intro_text', wp_kses_post($content), $kind, 'post:'.$pid);
            }
        }

        // 3) Fallback
        $default = ($kind === 'monthly') ? __('Monatsbegrüßung','tec-add-ons') : __('Wochenbegrüßung','tec-add-ons');
        return apply_filters('tec_addons_mailing_intro_text', esc_html($default), $kind, 'default');
    }


    protected static function get_quelle_parent_id(): int {
        if (!taxonomy_exists('tribe_events_cat')) return 0;
        $t = get_term_by('name','Quelle','tribe_events_cat');
        return ($t && !is_wp_error($t)) ? (int)$t->term_id : 0;
    }
    protected static function map_sources_to_term_ids(array $sources): array {
        $sources = array_values(array_filter(array_map('trim',$sources)));
        $out = ['event_source'=>[], 'tribe_quelle'=>[]];
        if (empty($sources)) return $out;

        if (taxonomy_exists('event_source')) {
            $terms = get_terms(['taxonomy'=>'event_source','hide_empty'=>false,'fields'=>'ids','name__in'=>$sources]);
            if (!is_wp_error($terms) && !empty($terms)) $out['event_source'] = array_map('intval',$terms);
        }
        if (taxonomy_exists('tribe_events_cat')) {
            $pid = self::get_quelle_parent_id();
            if ($pid) {
                $kids = get_terms(['taxonomy'=>'tribe_events_cat','hide_empty'=>false,'fields'=>'ids','name__in'=>$sources,'parent'=>$pid]);
                if (!is_wp_error($kids) && !empty($kids)) $out['tribe_quelle'] = array_map('intval',$kids);
            }
        }
        return $out;
    }

    protected static function fetch_events(string $start_mysql, string $end_mysql, array $filter): array {
        $tax_query = ['relation'=>'AND'];
        if (!empty($filter['event_source'])) {
            $tax_query[] = [
                'taxonomy' => 'event_source',
                'field'    => 'term_id',
                'terms'    => $filter['event_source'],
                'include_children' => true,
                'operator' => 'IN',
            ];
        }
        if (!empty($filter['tribe_quelle'])) {
            $tax_query[] = [
                'taxonomy' => 'tribe_events_cat',
                'field'    => 'term_id',
                'terms'    => $filter['tribe_quelle'],
                'include_children' => true,
                'operator' => 'IN',
            ];
        }

        $args = [
            'post_type'      => 'tribe_events',
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_EventStartDate',
            'order'          => 'ASC',
            'posts_per_page' => 200,
            'meta_query'     => [
                [
                    'key'     => '_EventStartDate',
                    'value'   => [$start_mysql, $end_mysql],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ]
            ],
        ];
        if (count($tax_query) > 1) $args['tax_query'] = $tax_query;

        $q = new \WP_Query($args);
        $ids = [];
        if ($q->have_posts()){
            while ($q->have_posts()){ $q->the_post(); $ids[] = get_the_ID(); }
            wp_reset_postdata();
        }
        return $ids;
    }

    protected static function event_source_names(int $post_id): array {
        // 1) event_source
        if (taxonomy_exists('event_source')) {
            $terms = get_the_terms($post_id, 'event_source');
            if (!is_wp_error($terms) && $terms) return array_values(array_map(fn($t)=>$t->name,$terms));
        }
        // 2) tribe_events_cat unter "Quelle"
        if (taxonomy_exists('tribe_events_cat')) {
            $terms = get_the_terms($post_id, 'tribe_events_cat');
            if (!is_wp_error($terms) && $terms) {
                $out=[];
                foreach($terms as $t){
                    $p = $t->parent ? get_term($t->parent,'tribe_events_cat') : null;
                    if ($p && !is_wp_error($p) && $p->name==='Quelle') $out[]=$t->name;
                }
                if ($out) return $out;
            }
        }
        return [];
    }
    protected static function is_own_event(int $post_id): bool {
        // heuristisch: keine Quelle gesetzt -> eigenes Ereignis
        return empty(self::event_source_names($post_id));
    }
    protected static function own_source_name(): string {
        return (string) get_option('blogname','');
    }

    protected static function get_event_image_url(int $post_id): string {
        $img = get_the_post_thumbnail_url($post_id, 'large');
        return ($img && filter_var($img, FILTER_VALIDATE_URL)) ? $img : '';
    }
    protected static function get_event_venue_text(int $post_id): string {
        $venue = function_exists('tribe_get_venue') ? trim((string) tribe_get_venue($post_id)) : '';
        $city  = function_exists('tribe_get_city')  ? trim((string) tribe_get_city($post_id))  : '';
        if ($venue==='' ) {
            $venue_id = (int) get_post_meta($post_id, '_EventVenueID', true);
            if ($venue_id) {
                $maybe = get_the_title($venue_id);
                if ($maybe) $venue = $maybe;
                $meta_city = get_post_meta($venue_id, '_VenueCity', true);
                if ($city==='' && $meta_city) $city = $meta_city;
            }
        }
        if ($venue!=='' && $city!=='') return $venue.' – '.$city;
        if ($venue!=='') return $venue;
        return '';
    }
    protected static function get_event_excerpt(int $post_id): string {
        $ex = get_the_excerpt($post_id);
        if ($ex && trim($ex)!=='') return wp_strip_all_tags($ex);
        $content = wp_strip_all_tags( (string) get_post_field('post_content',$post_id) );
        $content = trim(preg_replace('/\s+/',' ', $content));
        if ($content==='') return '';
        $words = preg_split('/\s+/', $content);
        if (count($words)>50) $content = implode(' ', array_slice($words,0,50)).'…';
        return $content;
    }
    protected static function format_dt_de(?string $mysql_datetime): string {
        if (!$mysql_datetime) return '';
        $ts = mysql2date('U', $mysql_datetime);
        if (!$ts) return '';
        return date_i18n('D., d.m.Y H:i', $ts);
    }

    protected static function render_event_item(int $post_id): string {
        $title = get_the_title($post_id);

        // Link: bevorzugt externe Event-Website (_EventURL), sonst Permalink
        $ext = trim((string) get_post_meta($post_id, '_EventURL', true));
        $url = $ext && filter_var($ext, FILTER_VALIDATE_URL) ? $ext : get_permalink($post_id);

        $start = get_post_meta($post_id,'_EventStartDate',true);
        $when  = $start ? self::format_dt_de($start) : '';

        $place = self::get_event_venue_text($post_id);

        $sources     = self::event_source_names($post_id);
        $source_line = $sources ? implode(', ', $sources) : '';
        if ($source_line==='' && self::is_own_event($post_id)) { $source_line = self::own_source_name(); }

        $excerpt = self::get_event_excerpt($post_id);
        $img     = self::get_event_image_url($post_id);

        $img_html = '';
        if ($img) {
            $img_html = '<tr><td><a href="'.esc_url($url).'" style="text-decoration:none;border:0;">'
                      . '<img src="'.esc_url($img).'" alt="" style="display:block;width:100%;height:auto;border:0;"></a></td></tr>';
        }

        $meta_lines = [];
        if ($place!=='') $meta_lines[] = esc_html($place);
        if ($when!=='')  $meta_lines[] = esc_html($when);
        if ($source_line!=='') $meta_lines[] = esc_html($source_line);
        $meta_html = '<div>'.implode('</div><div>', $meta_lines).'</div>';

        $html  = '<tr><td style="padding:0;">';
        $html .= $img_html;
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">';
        $html .= '<tr><td style="padding:14px 16px 6px 16px;font:700 18px/1.3 Arial,Helvetica,sans-serif;color:#111;">'
              .  '<a href="'.esc_url($url).'" style="color:#111;text-decoration:none;"><span style="border-bottom:2px solid #f0c02e">'
              .  esc_html($title).'</span></a></td></tr>';
        $html .= '<tr><td style="padding:0 16px 10px 16px;font:13px/1.4 Arial,Helvetica,sans-serif;color:#166534;">'.$meta_html.'</td></tr>';
        if ($excerpt!=='') {
            $html .= '<tr><td style="padding:0 16px 14px 16px;font:14px/1.5 Arial,Helvetica,sans-serif;color:#222;">'.esc_html($excerpt).'</td></tr>';
        }
        $html .= '<tr><td style="padding:0 16px 22px 16px;">'
              .  '<a href="'.esc_url($url).'" style="display:inline-block;background:#111;color:#fff;text-decoration:none;'
              .  'padding:10px 14px;border-radius:4px;font:600 14px Arial,Helvetica,sans-serif;">'
              .  esc_html__('Mehr erfahren','tec-add-ons').'</a></td></tr>';
        $html .= '</table></td></tr>';

        return $html;
    }

    protected static function build_mail_html(string $subject_label, string $kind, string $start_mysql, string $end_mysql, ?array $subscriber = null): string {
        // Quellenfilter mappen
        $filter = ['event_source'=>[], 'tribe_quelle'=>[]];
        if ($subscriber && array_key_exists('sources',$subscriber) && is_array($subscriber['sources'])) {
            $filter = self::map_sources_to_term_ids($subscriber['sources']);
        }

        // Filter neutralisieren, wenn „alles“ ausgewählt wurde
        $all_es = [];
        if ( taxonomy_exists('event_source') ) {
            $all_es = get_terms(['taxonomy'=>'event_source','hide_empty'=>false,'fields'=>'ids']);
        }
        $all_qc = [];
        if ( taxonomy_exists('tribe_events_cat') ) {
            $pid = self::get_quelle_parent_id();
            if ($pid) { $all_qc = get_terms(['taxonomy'=>'tribe_events_cat','hide_empty'=>false,'fields'=>'ids','parent'=>$pid]); }
        }
        $es_all_selected = !empty($filter['event_source']) && !array_diff((array)$all_es,(array)$filter['event_source']);
        $qc_all_selected = !empty($filter['tribe_quelle']) && !array_diff((array)$all_qc,(array)$filter['tribe_quelle']);
        if ( empty($filter['event_source']) && empty($filter['tribe_quelle']) || ($es_all_selected && $qc_all_selected) ) {
            $filter = ['event_source'=>[], 'tribe_quelle'=>[]];
        }

        $post_ids = self::fetch_events($start_mysql, $end_mysql, $filter);

        $list_rows = '';
        if ($post_ids) { foreach($post_ids as $pid){ $list_rows .= self::render_event_item($pid); } }
        else { $list_rows = '<tr><td style="padding:18px 16px;font:14px Arial,Helvetica,sans-serif;color:#555;">'.esc_html__('Keine Veranstaltungen im Zeitraum gefunden.','tec-add-ons').'</td></tr>'; }

        $brand  = (string) get_option('tec_addons_sender_name','Kulturstiftung Seevetal');
        if ($brand==='') $brand = (string) get_option('blogname','Kulturstiftung Seevetal');
        $logo   = self::logo_url();
        $hello  = $subscriber ? self::greeting($subscriber) : 'Hallo';
        $intro  = self::intro_text($kind);

        $period = esc_html($start_mysql).' — '.esc_html($end_mysql);

        // Header
        $logo_html = $logo ? '<img src="'.esc_url($logo).'" alt="'.esc_attr($brand).'" height="48" style="height:48px;width:auto;display:block;margin:0 auto;border:0;">' : '';

        $html  = '<!doctype html><html><body style="margin:0;padding:0;background:#f7f7f7;font-family:Arial,Helvetica,sans-serif;">';
        $html .= '<center><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;">';
        $html .= '<tr><td style="padding:0;">'
              .  '<div style="background:#f0c02e;color:#111;text-align:center;padding:18px 16px;">'.$logo_html
              .  '<div style="font:700 22px Arial,Helvetica,sans-serif;letter-spacing:.3px;text-transform:uppercase;margin-top:8px;">'
              .  esc_html($brand)
              .  '</div>'
              .  '<div style="font:700 12px Arial,Helvetica,sans-serif;letter-spacing:.4px;text-transform:uppercase;margin-top:2px;">'
              .  esc_html($subject_label)
              .  '</div>'
              .  '</div>'
              .  '</td></tr>';

        // Begrüßung + Zeitraum
         $intro_html = self::intro_text($kind); // <- kommt jetzt als HTML
        $html .= '<tr><td style="padding:18px 22px;border-bottom:1px solid #eee;">'
              .  '<div style="font:600 16px Arial,Helvetica,sans-serif;margin-bottom:6px;">'.esc_html($hello).'</div>'
              .  '<div style="font:14px Arial,Helvetica,sans-serif;color:#444;margin-bottom:6px;">'.$intro_html.'</div>'
              .  '<div style="font:12px Arial,Helvetica,sans-serif;color:#666;"><strong>'.esc_html__('Zeitraum','tec-add-ons').':</strong> '.$period.'</div>'
              .  '</td></tr>';

        // Events
        $html .= $list_rows;

        // Footer
        $home   = home_url('/');
        $footer_host = esc_html(parse_url($home, PHP_URL_HOST));
        $html .= '<tr><td style="padding:14px 22px;border-top:1px solid #eee;font:12px Arial,Helvetica,sans-serif;color:#666;">'
              .  '<a href="'.esc_url($home).'" style="text-decoration:none;color:#666;">'.$footer_host.'</a>';

        if ($subscriber && isset($subscriber['email'], $subscriber['token'])){
            $unsub = add_query_arg(['tec-unsubscribe'=>$subscriber['token'],'email'=>rawurlencode($subscriber['email'])], home_url('/'));
            $html .= ' • '.esc_html__('Abmelden','tec-add-ons').': <a href="'.esc_url($unsub).'" style="color:#666;">'.esc_html($subscriber['email']).'</a>';
        }
        $html .= '</td></tr></table></center></body></html>';

        return $html;
    }

    protected static function send_mail(string $to, string $subject_label, string $html, array $headers_extra = []): bool {
        $from_name  = trim((string) get_option('tec_addons_sender_name',''));
        if ($from_name==='') $from_name = get_bloginfo('name');

        $host = parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';

        $from_email = trim((string) get_option('tec_addons_sender_email',''));
        if (!is_email($from_email)) $from_email = 'no-reply@'.$host;

        $reply_to   = trim((string) get_option('tec_addons_reply_to',''));
        if (!is_email($reply_to)) $reply_to = $from_email;

        $headers = array_merge([
            'Content-Type: text/html; charset=UTF-8',
            'From: '.$from_name.' <'.$from_email.'>',
            'Reply-To: '.$reply_to,
        ], $headers_extra);

        $subject_final = $from_name.' – '.$subject_label;

        return wp_mail($to, $subject_final, $html, $headers);
    }

    protected static function send_mail_personal($row, string $subject_label, string $kind, string $start_mysql, string $end_mysql, string $run_key): bool {
        global $wpdb; 
        $sent_table = $wpdb->prefix.'tec_addons_mail_sent';

        $email = is_object($row) ? (string)$row->email : ( (is_array($row) && isset($row['email'])) ? (string)$row['email'] : '' );
        if ($email==='') return false;

        // DB-Dedupe: UNIQUE(run_key,email)
        $ins = $wpdb->insert($sent_table, [
            'run_key' => $run_key,
            'email'   => $email,
            'status'  => 'sent',
            'sent_at' => current_time('mysql'),
        ], ['%s','%s','%s','%s']);
        if ($ins === false) return false;

        $subscriber = [
            'email'   => $email,
            'name'    => is_object($row) ? (string)$row->name : ( (is_array($row) && isset($row['name'])) ? (string)$row['name'] : '' ),
            'sources' => is_object($row) ? maybe_unserialize($row->sources ?? []) : ( (is_array($row) && isset($row['sources'])) ? maybe_unserialize($row['sources']) : [] ),
            'token'   => is_object($row) ? (string)($row->token ?? '') : ( (is_array($row) && isset($row['token'])) ? (string)$row['token'] : '' ),
        ];

        // HTML bauen, ggf. ohne Filter nochmal versuchen
        $html = self::build_mail_html($subject_label, $kind, $start_mysql, $end_mysql, $subscriber);
        if ( strpos($html, 'Keine Veranstaltungen im Zeitraum gefunden') !== false ) {
            $subscriber['sources'] = [];
            $html = self::build_mail_html($subject_label, $kind, $start_mysql, $end_mysql, $subscriber);
        }

        // Header: Message-ID + List-Unsubscribe
        $host = parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';
        $unsub_http = add_query_arg(['tec-unsubscribe'=>$subscriber['token'],'email'=>rawurlencode($subscriber['email'])], home_url('/'));
        $headers_extra = [
            'Message-ID: <'.$run_key.'-'.md5($email).'@'.$host.'>',
            'List-Unsubscribe: <'.$unsub_http.'>, <mailto:'.esc_attr( get_option('tec_addons_reply_to','') ).'?subject=unsubscribe>',
        ];

        $ok = self::send_mail($email, $subject_label, $html, $headers_extra);
        if (!$ok) {
            $wpdb->update($sent_table, ['status'=>'failed','error'=>get_option('tec_addons_last_mail_error','')], ['run_key'=>$run_key,'email'=>$email], ['%s','%s'], ['%s','%s']);
        }
        return (bool)$ok;
    }

    // REPLACE THIS WHOLE METHOD
    protected static function send_to_active(string $frequency, string $start_mysql, string $end_mysql, string $subject_label, string $kind, string $run_key): array {
        global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status=%s AND frequency=%s",
            'active', $frequency
        ) );

        $total = is_array($rows) ? count($rows) : 0;
        $ok=0; $errs=[];

        // Throttle-Optionen (optional)
        $batch_size  = (int) get_option('tec_addons_batch_size', 10);
        $batch_sleep = (int) get_option('tec_addons_batch_sleep', 60);
        $retry_sleep = 60;

        if ($rows){
            $i=0;
            foreach ($rows as $row){
                $sent = self::send_mail_personal($row, $subject_label, $kind, $start_mysql, $end_mysql, $run_key);
                if ($sent) { $ok++; }
                else {
                    $errs[] = is_object($row) ? (string)$row->email : '';
                    $last_err = (string) get_option('tec_addons_last_mail_error','');
                    if ( stripos($last_err,'rate limit') !== false ) { sleep($retry_sleep); }
                }
                $i++;
                if ($batch_size>0 && $i % $batch_size === 0) { sleep($batch_sleep); }
            }
        }
        return [$total,$ok,$errs];
    }


    /* ================= Public Actions ================= */
    public static function test_weekly(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_test_weekly', ['_tec_addons_test_weekly']);
        [$start,$end] = self::window_weekly_test();
        $html = self::build_mail_html(__('Wochenmail','tec-add-ons'), 'weekly', $start, $end, null);
        self::send_mail(self::current_admin_email(), '[TEST] '.__('Wochenmail','tec-add-ons'), $html);
        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ); exit;
    }
    public static function test_monthly(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_test_monthly', ['_tec_addons_test_monthly']);
        [$start,$end] = self::window_monthly_test();
        $html = self::build_mail_html(__('Monatsmail','tec-add-ons'), 'monthly', $start, $end, null);
        self::send_mail(self::current_admin_email(), '[TEST] '.__('Monatsmail','tec-add-ons'), $html);
        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ); exit;
    }

    public static function send_weekly_now(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_send_weekly', ['_tec_addons_send_weekly']);

        $force = (isset($_REQUEST['force']) && (string)$_REQUEST['force'] === '1');
        self::run('weekly', $force ? 'manual-force' : 'manual', $force);

        wp_safe_redirect( add_query_arg(['tec-sent'=>'weekly','forced'=>$force?1:0], wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ) );
        exit;
    }

    public static function send_monthly_now(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_send_monthly', ['_tec_addons_send_monthly']);

        $force = (isset($_REQUEST['force']) && (string)$_REQUEST['force'] === '1');
        self::run('monthly', $force ? 'manual-force' : 'manual', $force);

        wp_safe_redirect( add_query_arg(['tec-sent'=>'monthly','forced'=>$force?1:0], wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ) );
        exit;
    }


    /* ================= Cron ================= */
    public static function cron_weekly(): void {
        if ( ! self::weekly_enabled() ) return;
        self::run('weekly','cron');
        if ( self::weekly_enabled() ) wp_schedule_single_event( self::next_weekly_timestamp(), 'tec_addons_cron_weekly' );
    }
    public static function cron_monthly(): void {
        if ( ! self::monthly_enabled() ) return;
        self::run('monthly','cron');
        if ( self::monthly_enabled() ) wp_schedule_single_event( self::next_monthly_timestamp(), 'tec_addons_cron_monthly' );
    }

    public static function render_mailing_quick_actions(): void {
    if ( ! is_admin() ) return;
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($page !== 'tec-add-ons-mailing') return;

    $action_url = admin_url('admin-post.php');
    $nw  = wp_create_nonce('tec_addons_send_weekly');
    $nm  = wp_create_nonce('tec_addons_send_monthly');

    echo '<div class="notice notice-info" style="padding:12px 16px;margin-top:12px;">';
    echo '<p style="margin:.4em 0 .8em 0;"><strong>Direktversand</strong></p>';

    // Normaler Wochenlauf (benutzt aktuellen run_key)
    echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;margin-right:8px;">'
       . '<input type="hidden" name="action" value="tec_addons_send_weekly"/>'
       . '<input type="hidden" name="_tec_addons_send_weekly" value="'.esc_attr($nw).'"/>'
       . '<button class="button button-primary">Wochenmail jetzt senden</button>'
       . '</form>';

    // Neuer Wochenlauf (frischer run_key)
    echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;margin-right:24px;">'
       . '<input type="hidden" name="action" value="tec_addons_send_weekly"/>'
       . '<input type="hidden" name="_tec_addons_send_weekly" value="'.esc_attr($nw).'"/>'
       . '<input type="hidden" name="force" value="1"/>'
       . '<button class="button">Wochenmail jetzt senden (neuer Lauf)</button>'
       . '</form>';

    // Optional: Monatsmail
    echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;margin-right:8px;">'
       . '<input type="hidden" name="action" value="tec_addons_send_monthly"/>'
       . '<input type="hidden" name="_tec_addons_send_monthly" value="'.esc_attr($nm).'"/>'
       . '<button class="button">Monatsmail jetzt senden</button>'
       . '</form>';

    echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;">'
       . '<input type="hidden" name="action" value="tec_addons_send_monthly"/>'
       . '<input type="hidden" name="_tec_addons_send_monthly" value="'.esc_attr($nm).'"/>'
       . '<input type="hidden" name="force" value="1"/>'
       . '<button class="button">Monatsmail jetzt senden (neuer Lauf)</button>'
       . '</form>';

    echo '</div>';
}


// REPLACE THIS WHOLE METHOD
    protected static function run(string $type, string $trigger, bool $force = false): void {
        global $wpdb;
        $log_table  = $wpdb->prefix.'tec_addons_mail_log';

        $lock_key = ($type === 'weekly') ? 'weekly' : 'monthly';
        if ( ! self::acquire_lock($lock_key, 7200) ) { return; } // 2h Lock

        try {
            // Basis-Schlüssel nach Rhythmus
            $base_key = self::run_key_for($type);

            // Bei force einen frischen, eindeutigen Run-Key erzeugen
            $run_key = $force ? ($base_key . '-' . gmdate('YmdHis') . 'Z') : $base_key;

            // Zeitraum bestimmen
            if ($type === 'weekly') { [$start,$end] = self::window_weekly_live( self::now() ); }
            else                    { [$start,$end] = self::window_monthly_live( self::now() ); }

            // Run-Log (idempotent, aber ohne UNIQUE-Index ggf. mehrfach -> darum check)
            $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$log_table} WHERE run_key=%s LIMIT 1", $run_key) );
            if (!$exists) {
                $wpdb->insert($log_table, [
                    'run_key'       => $run_key,
                    'run_type'      => $type,
                    'triggered_by'  => $trigger,
                    'period_start'  => $start,
                    'period_end'    => $end,
                    'recipients'    => 0,
                    'success_cnt'   => 0,
                    'error_cnt'     => 0,
                    'created_at'    => current_time('mysql'),
                ], ['%s','%s','%s','%s','%s','%d','%d','%d','%s']);
            }

            // WICHTIG: denselben run_key an den Versand weiterreichen
            [$total,$ok,$errs] = self::send_to_active(
                $type, $start, $end,
                $type === 'weekly' ? __('Wochenmail','tec-add-ons') : __('Monatsmail','tec-add-ons'),
                $type,
                $run_key
            );

            // Log aktualisieren
            $wpdb->update($log_table, [
                'recipients'  => (int)$total,
                'success_cnt' => (int)$ok,
                'error_cnt'   => (int)max(0, $total - $ok),
                'errors'      => !empty($errs) ? implode(',', array_map('sanitize_email',$errs)) : null,
            ], ['run_key'=>$run_key], ['%d','%d','%d','%s'], ['%s']);

        } catch (\Throwable $e) {
            update_option('tec_addons_last_mail_error', 'run: '.$e->getMessage());
        } finally {
            self::release_lock($lock_key);
        }
    }


    /* ================= Planung / Reschedule ================= */
    public static function maybe_schedule_cron(): void {
        if ( self::weekly_enabled()  && ! wp_next_scheduled('tec_addons_cron_weekly') ) {
            wp_schedule_single_event( self::next_weekly_timestamp(), 'tec_addons_cron_weekly' );
        }
        if ( self::monthly_enabled() && ! wp_next_scheduled('tec_addons_cron_monthly') ) {
            wp_schedule_single_event( self::next_monthly_timestamp(), 'tec_addons_cron_monthly' );
        }
    }
    public static function clear_cron(): void {
        $ts = wp_next_scheduled('tec_addons_cron_weekly');  if ($ts) wp_unschedule_event($ts, 'tec_addons_cron_weekly');
        $ts = wp_next_scheduled('tec_addons_cron_monthly'); if ($ts) wp_unschedule_event($ts, 'tec_addons_cron_monthly');
    }
    public static function reschedule_now(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_reschedule', ['_tec_addons_reschedule']);
        self::clear_cron();
        self::maybe_schedule_cron();
        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ); exit;
    }
    public static function on_toggle_weekly($old, $new, $option): void  { self::clear_cron(); self::maybe_schedule_cron(); }
    public static function on_toggle_monthly($old, $new, $option): void { self::clear_cron(); self::maybe_schedule_cron(); }

    /* ================= UI Helper ================= */
    protected static function check_nonce_relaxed(string $action, array $fields): void {
        foreach ($fields as $f){
            if ( isset($_REQUEST[$f]) && wp_verify_nonce( sanitize_text_field( wp_unslash($_REQUEST[$f]) ), $action ) ) return;
        }
        if ( current_user_can('manage_options') ) return; // Admin darf testen
        wp_die(__('Ungültige Anfrage (Nonce).','tec-add-ons'));
    }

    /* ================= Admin Log Rendering (optional) ================= */
    public static function render_log_table(int $limit=50): void {
        global $wpdb; $table = $wpdb->prefix.'tec_addons_mail_log';
        $rows = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int)$limit) );
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Run</th><th>Typ</th><th>Quelle</th><th>Zeitraum</th><th>Empfänger</th><th>Erfolg</th><th>Fehler</th><th>Erstellt</th></tr></thead><tbody>';
        if ($rows){
            foreach($rows as $r){
                printf('<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s – %s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
                    (int)$r->id, esc_html($r->run_key), esc_html($r->run_type), esc_html($r->triggered_by),
                    esc_html($r->period_start), esc_html($r->period_end),
                    (int)$r->recipients, (int)$r->success_cnt, (int)$r->error_cnt, esc_html($r->created_at)
                );
            }
        } else {
            echo '<tr><td colspan="9"><em>'.esc_html__('Keine Einträge','tec-add-ons').'</em></td></tr>';
        }
        echo '</tbody></table>';
    }
}
