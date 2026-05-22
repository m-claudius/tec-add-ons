<?php
namespace TEC_Addons\Mailing;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Sender', false)) { return; }

class Sender {

    public static function current_admin_email(): string {
        $u = wp_get_current_user();
        return ($u && $u->user_email) ? $u->user_email : (string) get_option('admin_email');
    }

    public static function on_mail_failed($wp_error): void {
        update_option('tec_addons_last_mail_error', is_wp_error($wp_error) ? $wp_error->get_error_message() : (string) $wp_error);
    }

    public static function send_mail(string $to, string $subject_label, string $html, array $headers_extra = []): bool {
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

    public static function send_mail_personal($row, string $subject_label, string $kind, string $start_mysql, string $end_mysql, string $run_key): bool {
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
        $html = Renderer::build_mail_html($subject_label, $kind, $start_mysql, $end_mysql, $subscriber);
        if ( strpos($html, 'Keine Veranstaltungen im Zeitraum gefunden') !== false ) {
            $subscriber['sources'] = [];
            $html = Renderer::build_mail_html($subject_label, $kind, $start_mysql, $end_mysql, $subscriber);
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

    public static function send_to_active(string $frequency, string $start_mysql, string $end_mysql, string $subject_label, string $kind, string $run_key): array {
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

    public static function run(string $type, string $trigger, bool $force = false): void {
        global $wpdb;
        $log_table  = $wpdb->prefix.'tec_addons_mail_log';

        $lock_key = ($type === 'weekly') ? 'weekly' : 'monthly';
        if ( ! Schedule::acquire_lock($lock_key, 7200) ) { return; } // 2h Lock

        try {
            // Basis-Schlüssel nach Rhythmus
            $base_key = Schedule::run_key_for($type);

            // Bei force einen frischen, eindeutigen Run-Key erzeugen
            $run_key = $force ? ($base_key . '-' . gmdate('YmdHis') . 'Z') : $base_key;

            // Zeitraum bestimmen
            if ($type === 'weekly') { [$start,$end] = Schedule::window_weekly_live( Schedule::now() ); }
            else                    { [$start,$end] = Schedule::window_monthly_live( Schedule::now() ); }

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
            Schedule::release_lock($lock_key);
        }
    }

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
