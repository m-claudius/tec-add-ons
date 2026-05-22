<?php
namespace TEC_Addons;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Subscribe {

    /* =======================
     * Bootstrap
     * ======================= */
    public static function init(){
        add_shortcode('tec_addons_subscribe',[__CLASS__,'shortcode']);

        // Form-Submit (Frontend + eingeloggte User)
        add_action('admin_post_nopriv_tec_addons_subscribe',[__CLASS__,'handle_submit']);
        add_action('admin_post_tec_addons_subscribe',[__CLASS__,'handle_submit']);

        // Bestätigen / Abmelden per Link
        add_action('template_redirect',[__CLASS__,'handle_confirm']);
        add_action('template_redirect',[__CLASS__,'handle_unsubscribe']);

        // Optionaler Mail-Fehler-Log
        add_action('wp_mail_failed',[__CLASS__,'on_mail_failed']);

        // REST für IMAP-Poller o.ä.
        add_action('rest_api_init',[__CLASS__,'register_rest']);
    }

    /* =======================
     * DB
     * ======================= */
    public static function maybe_install_tables(){
        global $wpdb; $table=$wpdb->prefix . 'tec_addons_subscribers';
        $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table));
        if($exists===$table) return;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $sql="CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL UNIQUE,
            name VARCHAR(190) NULL,
            sources TEXT NULL,
            categories TEXT NULL,
            frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            token VARCHAR(64) NOT NULL,
            consent_at DATETIME NULL,
            consent_ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status_idx (status),
            KEY freq_idx (frequency)
        ) $charset;";
        dbDelta($sql);
    }

    /* =======================
     * Quellen
     * ======================= */
    protected static function parse_custom_sources(){
        $raw=get_option('tec_addons_sources_custom','');
        if($raw===''){ $raw='Empore Buchholz, Musik in alten Heidekirchen, Kulturverein Winsen'; }
        $items=array_filter(array_map('trim', explode(',', $raw)));
        $out=[];
        foreach($items as $name){
            $slug=sanitize_title($name); if(!$slug) continue;
            $out[]=['slug'=>$slug,'name'=>$name,'locked'=>false];
        }
        return $out;
    }

    protected static function get_sources(){
        // Basis und „Seevetal“ fix ergänzen
        $sources=[
            ['slug'=>'kulturstiftung','name'=>'Kulturstiftung','locked'=>true],
            ['slug'=>'seevetal','name'=>'Seevetal','locked'=>false],
        ];
        $seen=['kulturstiftung'=>true,'seevetal'=>true];

        // Taxonomie "event_source"
        if(taxonomy_exists('event_source')){
            $terms=get_terms(['taxonomy'=>'event_source','hide_empty'=>false]);
            if(!is_wp_error($terms)){
                foreach($terms as $t){
                    $slug=sanitize_title($t->slug);
                    if(isset($seen[$slug])) continue;
                    $sources[]=['slug'=>$slug,'name'=>$t->name,'locked'=>false];
                    $seen[$slug]=true;
                }
            }
        }

        // Manuelle Quellen (Option)
        foreach(self::parse_custom_sources() as $s){
            if(isset($seen[$s['slug']])) continue;
            $sources[]=$s; $seen[$s['slug']]=true;
        }
        return $sources;
    }

    /* =======================
     * Shortcode (Formular)
     * ======================= */
    public static function shortcode($atts,$content=''){
        $atts=shortcode_atts(['title'=>__('Newsletter Anmeldung','tec-add-ons')],$atts,'tec_addons_subscribe');

        // Meldungen
        $msg='';
        if(isset($_GET['tec-submitted']) && $_GET['tec-submitted']==='1'){
            $msg='<div class="updated">'.esc_html__('Danke! Bitte bestätigen Sie Ihre E-Mail – wir haben Ihnen soeben einen Link geschickt.','tec-add-ons').'</div>';
        } elseif(isset($_GET['tec-submitted']) && $_GET['tec-submitted']==='0'){
            $msg='<div class="error">'.esc_html__('Es gab ein Problem bei der Anmeldung. Bitte erneut versuchen.','tec-add-ons').'</div>';
        }

        // Quellen aufbereiten: kulturstiftung ist immer enthalten (Hidden), Rest als Checkboxen
        $all_sources = self::get_sources();
        $other_sources=[];
        foreach($all_sources as $s){ if(($s['slug']??'')!=='kulturstiftung'){ $other_sources[]=$s; } }

        // Defaults: alle anderen Quellen angehakt; Häufigkeit = monthly
        $selected_sources = isset($_POST['sources'])
            ? array_map('strval',(array)$_POST['sources'])
            : array_map('strval', array_column($other_sources,'slug'));

        $selected_frequency = isset($_POST['frequency'])
            ? sanitize_text_field($_POST['frequency'])
            : 'monthly';

        $action=esc_url(admin_url('admin-post.php'));

        ob_start(); ?>
        <style>
            .tec-subscribe input[type=text],
            .tec-subscribe input[type=email],
            .tec-subscribe select,
            .tec-subscribe label,
            .tec-subscribe legend{color:#000 !important}
            .tec-subscribe input[type=checkbox]{
                appearance:checkbox;-webkit-appearance:checkbox;
                position:static !important;opacity:1 !important;
                display:inline-block !important;width:16px;height:16px;margin-right:6px
            }
        </style>

        <div class="tec-subscribe">
            <h3><?php echo esc_html($atts['title']); ?></h3>
            <?php if(!empty($msg)): ?><div class="tec-subscribe-msg"><?php echo wp_kses_post($msg); ?></div><?php endif; ?>

            <form method="post" action="<?php echo $action; ?>">
                <input type="hidden" name="action" value="tec_addons_subscribe"><?php wp_nonce_field('tec_subscribe','tec_subscribe_nonce'); ?>

                <p><label><?php esc_html_e('E-Mail','tec-add-ons'); ?> *</label><br>
                    <input type="email" name="email" required class="regular-text" value="<?php echo isset($_POST['email'])?esc_attr($_POST['email']):''; ?>"></p>

                <p><label><?php esc_html_e('Name','tec-add-ons'); ?></label><br>
                    <input type="text" name="name" class="regular-text" value="<?php echo isset($_POST['name'])?esc_attr($_POST['name']):''; ?>"></p>

                <fieldset>
                    <legend><?php esc_html_e('Quellen','tec-add-ons'); ?></legend>
                    <p style="margin:4px 0;color:#000;"><strong><?php esc_html_e('Kulturstiftung','tec-add-ons'); ?></strong> <?php esc_html_e('(immer)','tec-add-ons'); ?></p>
                    <input type="hidden" name="sources[]" value="kulturstiftung">
                    <?php foreach($other_sources as $s): $slug=(string)$s['slug']; ?>
                        <label style="display:block;margin:4px 0;color:#000;">
                            <input type="checkbox" name="sources[]" value="<?php echo esc_attr($slug); ?>"
                                   <?php echo in_array($slug,$selected_sources,true)?'checked':''; ?>>
                            <?php echo esc_html($s['name']); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <p><label><?php esc_html_e('Häufigkeit','tec-add-ons'); ?></label><br>
                    <select name="frequency">
                        <option value="weekly"  <?php selected($selected_frequency,'weekly');  ?>><?php esc_html_e('Wöchentlich','tec-add-ons'); ?></option>
                        <option value="monthly" <?php selected($selected_frequency,'monthly'); ?>><?php esc_html_e('Monatlich','tec-add-ons'); ?></option>
                    </select>
                </p>

                <p style="font-size:12px;color:#000;"><?php esc_html_e('Mit dem Absenden erkläre ich mich mit der Verarbeitung meiner Daten gemäß Datenschutzhinweisen einverstanden. Ich kann meine Einwilligung jederzeit widerrufen.','tec-add-ons'); ?></p>

                <p><button type="submit" name="tec_subscribe_submit"><?php esc_html_e('Anmelden','tec-add-ons'); ?></button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* =======================
     * Submit-Handler (Double-Opt-In)
     * ======================= */
    public static function handle_submit(){
        if ( ! isset($_POST['tec_subscribe_nonce']) || ! wp_verify_nonce($_POST['tec_subscribe_nonce'],'tec_subscribe') ){
            wp_die(__('Nicht erlaubt.','tec-add-ons'));
        }

        $referer = wp_get_referer() ?: home_url('/');
        $email   = sanitize_email($_POST['email'] ?? '');
        if (!$email || !is_email($email)){
            wp_safe_redirect( add_query_arg('tec-submitted','0',$referer) ); exit;
        }
        $name = sanitize_text_field($_POST['name'] ?? '');

        // Häufigkeit – Default: monthly
        $frequency = sanitize_text_field($_POST['frequency'] ?? '');
        if (!in_array($frequency,['weekly','monthly'],true)) { $frequency='monthly'; }

        // Quellen (Slugs) aus Formular
        $posted_sources = isset($_POST['sources']) ? array_map('strval',(array)$_POST['sources']) : [];
        if ( ! in_array('kulturstiftung',$posted_sources,true) ) { $posted_sources[]='kulturstiftung'; }

        // Alle verfügbaren Quellen
        $all = array_map('strval', array_column(self::get_sources(),'slug'));

        // Fallback: nur kulturstiftung oder leer => alle Quellen
        $only_ks = (count(array_diff($posted_sources,['kulturstiftung']))===0);
        $final_sources = $only_ks ? $all : array_values(array_intersect($all,$posted_sources));
        if ( ! in_array('kulturstiftung',$final_sources,true) ) { array_unshift($final_sources,'kulturstiftung'); }
        $final_sources = array_values(array_unique($final_sources));

        // DB upsert
        self::maybe_install_tables();
        global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';
        $token = wp_generate_password(22,false,false);
        $data = [
            'email'      => $email,
            'name'       => $name,
            'sources'    => maybe_serialize($final_sources),
            'categories' => maybe_serialize([]),
            'frequency'  => $frequency,
            'status'     => 'pending',
            'token'      => $token,
            'updated_at' => current_time('mysql'),
        ];
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE email=%s",$email));
        if($existing){
            $wpdb->update($table,$data,['id'=>$existing->id]);
        }else{
            $data['created_at']=current_time('mysql');
            $wpdb->insert($table,$data);
        }

        // Bestätigungs-Mail raus
        $ok = self::send_confirmation($email,$token,$name);
        wp_safe_redirect( add_query_arg('tec-submitted', $ok?'1':'0', $referer) ); exit;
    }

    protected static function send_confirmation(string $email,string $token,string $name=''): bool {
        // Ziel/Link
        $confirm_page = trim((string)get_option('tec_addons_confirm_url',''));
        $base = $confirm_page!=='' ? $confirm_page : home_url('/');
        $confirm_url = add_query_arg([
            'tec-subscribe-confirm'=>$token,
            'email'=>rawurlencode($email),
        ], $base);

        // Branding / Absender
        $brand      = get_option('tec_addons_sender_name','Kulturstiftung Seevetal');
        $from_email = get_option('tec_addons_sender_email','no-reply@your-domain.tld');
        $reply_to   = get_option('tec_addons_reply_to','');

        // Logo (optional)
        $logo='';
        $logo_id=(int)get_option('tec_addons_mail_logo_id',0);
        $logo_url=(string)get_option('tec_addons_mail_logo_url','');
        if($logo_id){
            $img=wp_get_attachment_image_src($logo_id,'full');
            if($img && isset($img[0])) $logo=$img[0];
        } elseif($logo_url){ $logo=$logo_url; }

        // HTML-Mail
        $greet = $name ? 'Hallo '.esc_html($name).',' : 'Hallo,';
        $btn   = '<a href="'.esc_url($confirm_url).'" style="display:inline-block;background:#111;color:#fff;text-decoration:none;font:600 14px Arial,Helvetica,sans-serif;padding:10px 14px;border-radius:4px;">Anmeldung bestätigen</a>';

        $html  = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#e5e7eb"><tr><td align="center">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="680" style="max-width:680px;background:#fff">';
        if ($logo) {
            $html .= '<tr><td style="text-align:center;background:#f0c02e;padding:14px 12px"><img src="'.esc_url($logo).'" alt="'.esc_attr($brand).'" height="40" style="height:40px;width:auto;border:0;display:inline-block"></td></tr>';
        }
        $html .= '<tr><td style="padding:18px 16px;font:400 14px/1.6 Arial,Helvetica,sans-serif;color:#111;">'
              .  '<p style="margin:0 0 10px 0">'.$greet.'</p>'
              .  '<p style="margin:0 0 10px 0">bitte bestätige deine Newsletter-Anmeldung für <strong>'.esc_html($brand).'</strong> mit einem Klick:</p>'
              .  '<p style="margin:12px 0">'.$btn.'</p>'
              .  '<p style="margin:18px 0 0 0;font-size:12px;color:#666">Falls der Button nicht funktioniert: '
              .  '<br><span style="word-break:break-all">'.esc_html($confirm_url).'</span></p>'
              .  '</td></tr>';
        $html .= '</table></td></tr></table>';

        $headers = ['Content-Type: text/html; charset=UTF-8', 'From: '.$brand.' <'.$from_email.'>'];
        if($reply_to){ $headers[]='Reply-To: '.$reply_to; }

        $sent = wp_mail($email, $brand.' – Bitte Anmeldung bestätigen', $html, $headers);

        if(!$sent){
            // kurzes Fehlerprotokoll
            $data=[
                'time'=>current_time('mysql'),
                'error'=>'Bestätigungs-Mail konnte nicht gesendet werden.',
                'data'=>['to'=>$email],
            ];
            update_option('tec_addons_last_mail_error', wp_json_encode($data, JSON_PRETTY_PRINT));
        }
        return (bool)$sent;
    }

    public static function on_mail_failed($wp_error){
        $data=[
            'time'=>current_time('mysql'),
            'error'=> is_wp_error($wp_error)? $wp_error->get_error_message() : 'Mailversand fehlgeschlagen',
            'data'=> is_wp_error($wp_error)? $wp_error->get_error_data()    : $wp_error,
        ];
        update_option('tec_addons_last_mail_error', wp_json_encode($data, JSON_PRETTY_PRINT));
    }

    /* =======================
     * Confirm / Unsubscribe
     * ======================= */
    public static function handle_confirm(){
        if(empty($_GET['tec-subscribe-confirm'])||empty($_GET['email'])) return;

        $token=sanitize_text_field(wp_unslash($_GET['tec-subscribe-confirm']));
        $email=sanitize_email(wp_unslash($_GET['email']));

        global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s AND token=%s",$email,$token));
        if(!$row) return;

        $wpdb->update($table,[
            'status'=>'active',
            'consent_at'=>current_time('mysql'),
            'consent_ip'=> isset($_SERVER['REMOTE_ADDR'])? $_SERVER['REMOTE_ADDR'] : '',
            'updated_at'=>current_time('mysql'),
        ],['id'=>$row->id]);

        // Zielseite
        $target = trim((string)get_option('tec_addons_confirm_url'));
        if(empty($target) || $target===home_url('/')){
            $p = get_page_by_path('newsletter-bestaetigung');
            $target = ($p && !is_wp_error($p)) ? get_permalink($p->ID) : home_url('/');
        }
        wp_safe_redirect($target); exit;
    }

    public static function handle_unsubscribe(){
        if(empty($_GET['tec-unsubscribe'])||empty($_GET['email'])) return;

        $token=sanitize_text_field(wp_unslash($_GET['tec-unsubscribe']));
        $email=sanitize_email(wp_unslash($_GET['email']));

        global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s AND token=%s",$email,$token));
        if(!$row) return;

        $wpdb->update($table,[
            'status'=>'unsubscribed',
            'updated_at'=>current_time('mysql'),
        ],['id'=>$row->id]);

        $target = trim((string)get_option('tec_addons_confirm_url'));
        if(empty($target) || $target===home_url('/')){
            $p = get_page_by_path('newsletter-bestaetigung');
            $target = ($p && !is_wp_error($p)) ? get_permalink($p->ID) : home_url('/');
        }
        wp_safe_redirect($target); exit;
    }

    /* =======================
     * REST (optional)
     * ======================= */
    public static function register_rest(){
        register_rest_route('tec-addons/v1','/mailhook',[
            'methods'=>'POST',
            'permission_callback'=>'__return_true',
            'callback'=>[__CLASS__,'rest_mailhook'],
        ]);
    }

    public static function rest_mailhook(\WP_REST_Request $req){
        $secret = $req->get_param('secret');
        if($secret!==get_option('tec_addons_mailhook_secret')){
            return new \WP_REST_Response(['ok'=>false,'error'=>'unauthorized'],401);
        }
        $action = sanitize_text_field($req->get_param('action'));

        if($action==='unsubscribe'){
            $email=sanitize_email($req->get_param('email'));
            if(!is_email($email)) return new \WP_REST_Response(['ok'=>false,'error'=>'invalid email'],400);
            global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';
            $wpdb->update($table,['status'=>'unsubscribed','updated_at'=>current_time('mysql')],['email'=>$email]);
            return ['ok'=>true,'status'=>'unsubscribed'];

        } elseif($action==='confirm'){
            $email=sanitize_email($req->get_param('email'));
            $token=sanitize_text_field($req->get_param('token'));
            if(!is_email($email) || empty($token)) return new \WP_REST_Response(['ok'=>false,'error'=>'invalid'],400);
            $_GET['tec-subscribe-confirm']=$token; $_GET['email']=$email;
            self::handle_confirm();
            return ['ok'=>true,'status'=>'confirmed'];
        }

        return new \WP_REST_Response(['ok'=>false,'error'=>'unknown action'],400);
    }
}
