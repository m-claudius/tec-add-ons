<?php
namespace TEC_Addons\Community;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Form', false)) { return; }

/**
 * Community-Frontend: Einreichungsformular (Shortcode), Submit-Handler,
 * Frontend-Bearbeitung bis Freigabe, Duplikatsuche, Quick-Publish, Login-Redirect.
 */
class Form {

    public static function shortcode_form($atts){
        $current_url = is_singular()
            ? get_permalink()
            : ( home_url(add_query_arg([],$_SERVER['REQUEST_URI'] ?? '/')) );

        if ( ! is_user_logged_in() ){
            $login_url = wp_login_url( $current_url );
            return '<div class="tec-comm tec-comm--login-needed" style="padding:1rem;border:1px solid #eee;border-radius:8px">'.
                '<strong>'.esc_html__('Bitte einloggen.','tec-add-ons').'</strong> '.
                '<a href="'.esc_url($login_url).'">'.esc_html__('Zum Login »','tec-add-ons').'</a>'.
                '</div>';
        }

        $user = wp_get_current_user();
        list($default_source_term,) = Mapping::get_source_for_user($user->ID);
        $sources = Sources::get_all_sources();
        $cats    = Sources::get_all_categories();
        $venues  = Sources::get_all_venues();
        $orgs    = Sources::get_all_organizers();

        $default_org = 0;
        if ( $default_source_term ) $default_org = Sources::find_organizer_by_name( $default_source_term->name );

        $ok_msg = '';
        if ( isset($_GET['tec-comm-ok']) && $_GET['tec-comm-ok']=='1' ){
            $ok_msg = '<div class="notice notice-success" style="padding:.6rem 1rem;margin-bottom:1rem;border-left:4px solid #46b450;background:#f6fff6">'.esc_html__('Danke! Ihre Veranstaltung wurde gespeichert und wird geprüft.','tec-add-ons').'</div>';
        }

        ob_start(); ?>
        <style>
        .tec-comm-form input[type=text],
        .tec-comm-form input[type=date],
        .tec-comm-form input[type=time],
        .tec-comm-form textarea,
        .tec-comm-form select { color:#000 !important; background:#fff !important; }
        .tec-comm-form label { color:#000; display:block; margin:.4rem 0; }
        .tec-comm-form .row { display:flex; gap:12px; flex-wrap:wrap; }
        .tec-comm-form .row > div { flex:1 1 220px; }
        .tec-comm-form .cats label { display:inline-flex; align-items:center; gap:6px; margin:0 12px 8px 0; cursor:pointer; }
        .tec-comm-form input[type=checkbox]{ appearance:auto !important; -webkit-appearance:checkbox !important; -moz-appearance:checkbox !important; opacity:1 !important; position:static !important; width:auto !important; height:auto !important; display:inline-block !important; }
        </style>

        <?php echo $ok_msg; ?>
        <form class="tec-comm-form" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="tec_addons_submit_event">
            <?php wp_nonce_field('tec_addons_submit_event','_tec_comm_nonce'); ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $current_url ); ?>">

            <p><label><?php esc_html_e('Titel','tec-add-ons'); ?> *<br>
                <input type="text" name="event_title" required style="width:100%"></label></p>

            <p><label><input type="checkbox" name="all_day" value="1"> <?php esc_html_e('Ganztägig','tec-add-ons'); ?></label></p>

            <div class="row">
                <div><label><?php esc_html_e('Start (Datum)','tec-add-ons'); ?> *<br>
                    <input type="date" name="start_date" required></label></div>
                <div><label><?php esc_html_e('Start (Uhrzeit)','tec-add-ons'); ?> *<br>
                    <input type="time" name="start_time" required></label></div>
                <div><label><?php esc_html_e('Ende (Datum)','tec-add-ons'); ?><br>
                    <input type="date" name="end_date"></label></div>
                <div><label><?php esc_html_e('Ende (Uhrzeit)','tec-add-ons'); ?><br>
                    <input type="time" name="end_time"></label></div>
            </div>

            <hr>
            <h3><?php esc_html_e('Ort','tec-add-ons'); ?></h3>
            <div class="row">
                <div><label><?php esc_html_e('Bestehenden Ort wählen','tec-add-ons'); ?><br>
                    <select name="existing_venue_id">
                        <option value=""><?php esc_html_e('— Keiner —','tec-add-ons'); ?></option>
                        <?php foreach($venues as $vid): ?>
                            <option value="<?php echo intval($vid); ?>"><?php echo esc_html( get_the_title($vid) ); ?></option>
                        <?php endforeach; ?>
                    </select></label></div>
                <div><label><?php esc_html_e('Neuer Ort (Name)','tec-add-ons'); ?><br>
                    <input type="text" name="new_venue_name"></label></div>
                <div><label><?php esc_html_e('Adresse (optional)','tec-add-ons'); ?><br>
                    <input type="text" name="new_venue_address"></label></div>
            </div>

            <hr>
            <h3><?php esc_html_e('Veranstalter','tec-add-ons'); ?></h3>
            <div class="row">
                <div><label><?php esc_html_e('Bestehenden Veranstalter wählen','tec-add-ons'); ?><br>
                    <select name="existing_org_id">
                        <option value=""><?php esc_html_e('— Keiner —','tec-add-ons'); ?></option>
                        <?php foreach($orgs as $oid): ?>
                            <option value="<?php echo intval($oid); ?>" <?php selected( $default_org === $oid ); ?>><?php echo esc_html( get_the_title($oid) ); ?></option>
                        <?php endforeach; ?>
                    </select></label></div>
                <div><label><?php esc_html_e('Neuer Veranstalter (Name)','tec-add-ons'); ?><br>
                    <input type="text" name="new_org_name" value="<?php echo esc_attr( $default_org ? '' : ( $default_source_term ? $default_source_term->name : '' ) ); ?>"></label></div>
            </div>

            <hr>
            <p><label><?php esc_html_e('Beschreibung','tec-add-ons'); ?> *<br>
                <textarea name="event_content" rows="8" style="width:100%" required></textarea></label></p>

            <p><strong><?php esc_html_e('Kategorien','tec-add-ons'); ?></strong></p>
            <div class="cats">
                <?php foreach($cats as $t): ?>
                    <label><input type="checkbox" name="event_cats[]" value="<?php echo esc_attr($t->term_id); ?>"> <?php echo esc_html($t->name); ?></label>
                <?php endforeach; ?>
            </div>

            <p><label><?php esc_html_e('Quelle (event_source)','tec-add-ons'); ?><br>
                <select name="event_source">
                    <?php foreach($sources as $s): ?>
                        <option value="<?php echo esc_attr($s->term_id); ?>" <?php selected( $default_source_term && $default_source_term->term_id===$s->term_id ); ?>>
                            <?php echo esc_html($s->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select></label>
            </p>

            <p><label><?php esc_html_e('Bild (optional)','tec-add-ons'); ?><br>
                <input type="file" name="event_image" accept="image/*"></label></p>

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Veranstaltung einreichen','tec-add-ons'); ?></button></p>
        </form>

        <script>
        (function(){
            function pad(n){ return (n<10?'0':'')+n; }
            function prefEnd(){
                var sd=document.querySelector('input[name=start_date]');
                var st=document.querySelector('input[name=start_time]');
                var ed=document.querySelector('input[name=end_date]');
                var et=document.querySelector('input[name=end_time]');
                if(!sd || !st || !ed || !et) return;
                if(!sd.value || !st.value) return;
                // nur vorbelegen, wenn Endfelder leer sind
                if(!ed.value){ ed.value = sd.value; }
                if(!et.value){
                    var t=st.value.split(':'); if(t.length<2) return;
                    var h=parseInt(t[0],10), m=parseInt(t[1],10);
                    h=(h+1)%24;
                    et.value = pad(h)+':'+pad(m);
                }
            }
            // beim Laden versuchen (falls Browser Autocomplete etc.)
            document.addEventListener('DOMContentLoaded', prefEnd);
            // auf Änderungen an Startfeldern reagieren (change + input)
            ['change','input'].forEach(function(evt){
                document.addEventListener(evt, function(e){
                    if(e.target && (e.target.name==='start_date' || e.target.name==='start_time')) prefEnd();
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public static function handle_submit(){
        if ( ! is_user_logged_in() ) wp_die( __('Bitte einloggen.','tec-add-ons') );
        if ( ! isset($_POST['_tec_comm_nonce']) || ! wp_verify_nonce( $_POST['_tec_comm_nonce'], 'tec_addons_submit_event' ) ) wp_die('no');

        $redirect = !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : home_url('/');

        $user = wp_get_current_user();
        list($default_source_term, $whitelist) = Mapping::get_source_for_user($user->ID);

        $title    = sanitize_text_field($_POST['event_title'] ?? '');
        $all_day  = !empty($_POST['all_day']);
        $cats     = array_map('absint', $_POST['event_cats'] ?? []);
        $source_term_id = absint( $_POST['event_source'] ?? 0 );

        // Fallbacks für Quelle: User-Standard → Default-Quelle
        if (!$source_term_id && $default_source_term) $source_term_id = $default_source_term->term_id;
        if (!$source_term_id){
            Sources::ensure_default_source_exists();
            $def = get_term_by('name', \TEC_Addons\Community::DEFAULT_SOURCE_NAME, 'event_source');
            if ( $def && ! is_wp_error($def) ) $source_term_id = (int)$def->term_id;
        }

        $sd = sanitize_text_field($_POST['start_date'] ?? '');
        $st = sanitize_text_field($_POST['start_time'] ?? '');
        $ed = sanitize_text_field($_POST['end_date'] ?? '');
        $et = sanitize_text_field($_POST['end_time'] ?? '');
        if ( empty($sd) || empty($st) || empty($title) ) {
            wp_safe_redirect( add_query_arg('tec-comm-ok','0',$redirect) ); exit;
        }
        $start_ts = strtotime($sd.' '.$st);
        $start_mysql = date('Y-m-d H:i:00', $start_ts);

        // Serverseitiger Default: wenn Ende leer und nicht ganztägig → +1h
        if ( $all_day ){
            $end_mysql = $start_mysql;
        } else {
            if ( $ed || $et ){
                $end_mysql = date('Y-m-d H:i:00', strtotime(($ed?:$sd).' '.($et?:$st)));
            } else {
                $end_mysql = date('Y-m-d H:i:00', $start_ts + HOUR_IN_SECONDS);
            }
        }

        $content  = wp_kses_post($_POST['event_content'] ?? '');
        $status = $whitelist ? 'publish' : 'pending';

        $post_id = wp_insert_post([
            'post_type'   => 'tribe_events',
            'post_status' => $status,
            'post_title'  => $title,
            'post_content'=> $content,
            'post_author' => $user->ID,
        ], true );
        if ( is_wp_error($post_id) ) wp_die( $post_id->get_error_message() );

        update_post_meta($post_id,'_EventStartDate',$start_mysql);
        update_post_meta($post_id,'_EventEndDate',$end_mysql);
        update_post_meta($post_id,'_EventAllDay',$all_day ? 'yes' : 'no');

        if (!empty($cats)) wp_set_post_terms($post_id, $cats, 'tribe_events_cat', false);
        if ($source_term_id && taxonomy_exists('event_source')) {
            wp_set_post_terms($post_id, [$source_term_id], 'event_source', false);
        }

        // Venue
        $existing_venue_id = absint($_POST['existing_venue_id'] ?? 0);
        $new_venue_name    = sanitize_text_field($_POST['new_venue_name'] ?? '');
        $new_venue_address = sanitize_text_field($_POST['new_venue_address'] ?? '');
        if ( $new_venue_name ){
            $venue_id = wp_insert_post([
                'post_type'=>'tribe_venue','post_status'=>'publish',
                'post_title'=>$new_venue_name,'post_content'=>''
            ]);
            if ( ! is_wp_error($venue_id) ){
                if ($new_venue_address) update_post_meta($venue_id,'_VenueAddress',$new_venue_address);
                update_post_meta($post_id,'_EventVenueID', $venue_id);
            }
        } elseif ( $existing_venue_id ){
            update_post_meta($post_id,'_EventVenueID', $existing_venue_id);
        }

        // Organizer
        $existing_org_id = absint($_POST['existing_org_id'] ?? 0);
        $new_org_name    = sanitize_text_field($_POST['new_org_name'] ?? '');
        if ( $existing_org_id ){
            update_post_meta($post_id,'_EventOrganizerID', $existing_org_id);
        } elseif ( $new_org_name ){
            $org_id = wp_insert_post([
                'post_type'=>'tribe_organizer','post_status'=>'publish',
                'post_title'=>$new_org_name,'post_content'=>''
            ]);
            if ( ! is_wp_error($org_id) ){
                update_post_meta($post_id,'_EventOrganizerID', $org_id);
            }
        } else {
            // Fallback Organizer = Default-Quelle (falls vorhanden)
            $def = get_term_by('name', \TEC_Addons\Community::DEFAULT_SOURCE_NAME, 'event_source');
            if ( $def ){
                $maybe_org = Sources::find_organizer_by_name( $def->name );
                if ($maybe_org) update_post_meta($post_id,'_EventOrganizerID', $maybe_org);
            }
        }

        // Bild
        if ( ! empty($_FILES['event_image']['name']) ){
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            $move = wp_handle_upload($_FILES['event_image'], ['test_form'=>false]);
            if ( ! isset($move['error']) ){
                $att_id = wp_insert_attachment([
                    'post_mime_type'=>$move['type'],
                    'post_title'=>sanitize_file_name( $_FILES['event_image']['name'] ),
                    'post_content'=>'',
                    'post_status'=>'inherit'
                ], $move['file'], $post_id);
                $attach_data = wp_generate_attachment_metadata($att_id, $move['file']);
                wp_update_attachment_metadata($att_id, $attach_data);
                set_post_thumbnail($post_id, $att_id);
            }
        }

        // Token Edit-Link (bis Publish)
        $token = wp_generate_password(20,false,false);
        update_post_meta($post_id, '_tec_comm_token', $token);
        update_post_meta($post_id, '_tec_comm_submitter_email', $user->user_email);

        $dups = self::find_duplicates($title, $start_mysql);

        Mailer::send_submitter_mail($user, $post_id, $token, $start_mysql, $source_term_id);
        Mailer::send_admin_mail($user, $post_id, $start_mysql, $source_term_id, $dups);

        wp_safe_redirect( add_query_arg('tec-comm-ok','1', $redirect) ); exit;
    }

    public static function force_login_redirect($redirect_to, $requested, $user){
        if ( ! empty($_REQUEST['redirect_to']) ) {
            return esc_url_raw( $_REQUEST['redirect_to'] );
        }
        return $redirect_to;
    }

    public static function find_duplicates($title,$start_mysql){
        $start = date('Y-m-d H:i:s', strtotime($start_mysql.' -24 hours'));
        $end   = date('Y-m-d H:i:s', strtotime($start_mysql.' +24 hours'));
        $q = new \WP_Query([
            'post_type'=>'tribe_events',
            'post_status'=>['publish','pending'],
            'posts_per_page'=>5,
            's' => $title,
            'meta_query'=>[
                'relation'=>'AND',
                [ 'key'=>'_EventStartDate','value'=>$start,'compare'=>'>=','type'=>'DATETIME' ],
                [ 'key'=>'_EventStartDate','value'=>$end,'compare'=>'<=','type'=>'DATETIME' ],
            ]
        ]);
        $out = [];
        if($q->have_posts()){
            foreach($q->posts as $p){
                $out[] = sprintf( "%s (%s) – %s",
                    get_the_title($p->ID),
                    get_post_status($p->ID),
                    get_edit_post_link($p->ID,'raw') ?: get_permalink($p->ID)
                );
            }
        }
        return $out;
    }

    public static function maybe_render_edit_form(){
        if ( empty($_GET['tec-edit-event']) || empty($_GET['post']) ) return;
        $token = sanitize_text_field($_GET['tec-edit-event']);
        $post_id = absint($_GET['post']);
        $post = get_post($post_id);
        if ( ! $post || $post->post_type!=='tribe_events' ) return;

        $stored = get_post_meta($post_id,'_tec_comm_token',true);
        if ( ! $stored || ! hash_equals($stored, $token) ){
            wp_die( __('Ungültiger Bearbeitungslink.','tec-add-ons') );
        }
        if ( get_post_status($post_id)==='publish' ){
            wp_die( __('Dieses Ereignis wurde bereits freigegeben und kann nicht mehr im Frontend bearbeitet werden.','tec-add-ons') );
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['_tec_comm_nonce']) && wp_verify_nonce($_POST['_tec_comm_nonce'],'tec_addons_submit_event') ){
            $title = sanitize_text_field($_POST['event_title'] ?? '');
            $sd = sanitize_text_field($_POST['start_date'] ?? '');
            $st = sanitize_text_field($_POST['start_time'] ?? '');
            $ed = sanitize_text_field($_POST['end_date'] ?? '');
            $et = sanitize_text_field($_POST['end_time'] ?? '');
            $start_mysql = date('Y-m-d H:i:00', strtotime($sd.' '.$st));
            $end_mysql = ($ed || $et) ? date('Y-m-d H:i:00', strtotime(($ed?:$sd).' '.($et?:$st))) : date('Y-m-d H:i:00', strtotime($sd.' '.$st.' +1 hour'));

            wp_update_post(['ID'=>$post_id,'post_title'=>$title,'post_content'=>wp_kses_post($_POST['event_content'] ?? '')]);
            update_post_meta($post_id,'_EventStartDate',$start_mysql);
            update_post_meta($post_id,'_EventEndDate',$end_mysql);

            wp_safe_redirect( add_query_arg(['tec-comm-ok'=>'1'], get_permalink()) ); exit;
        }

        $start = get_post_meta($post_id,'_EventStartDate',true);
        $end   = get_post_meta($post_id,'_EventEndDate',true);

        get_header();
        echo '<main class="tec-comm-edit" style="max-width:900px;margin:2rem auto;padding:1rem">';
        echo '<h1>'.esc_html__('Veranstaltung bearbeiten','tec-add-ons').'</h1>';
        echo '<form method="post">';
        wp_nonce_field('tec_addons_submit_event','_tec_comm_nonce');
        echo '<p><label>'.esc_html__('Titel','tec-add-ons').' *<br><input name="event_title" type="text" value="'.esc_attr(get_the_title($post_id)).'" style="width:100%"></label></p>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
        echo '<p><label>'.esc_html__('Start (Datum)','tec-add-ons').' *<br><input type="date" name="start_date" value="'.esc_attr( date('Y-m-d', strtotime($start)) ).'"></label></p>';
        echo '<p><label>'.esc_html__('Start (Uhrzeit)','tec-add-ons').' *<br><input type="time" name="start_time" value="'.esc_attr( date('H:i', strtotime($start)) ).'"></label></p>';
        echo '<p><label>'.esc_html__('Ende (Datum)','tec-add-ons').'<br><input type="date" name="end_date" value="'.esc_attr( date('Y-m-d', strtotime($end)) ).'"></label></p>';
        echo '<p><label>'.esc_html__('Ende (Uhrzeit)','tec-add-ons').'<br><input type="time" name="end_time" value="'.esc_attr( date('H:i', strtotime($end)) ).'"></label></p>';
        echo '</div>';
        echo '<p><label>'.esc_html__('Beschreibung','tec-add-ons').' *<br><textarea name="event_content" rows="8" style="width:100%">'.esc_textarea(get_post_field('post_content',$post_id)).'</textarea></label></p>';
        echo '<p><button class="button button-primary" type="submit">'.esc_html__('Speichern','tec-add-ons').'</button></p>';
        echo '</form>';
        echo '</main>';
        get_footer();
        exit;
    }

    public static function quick_publish(){
        if ( ! current_user_can('edit_posts') ) wp_die('no');
        $post_id = absint($_GET['post'] ?? 0);
        check_admin_referer('tec_comm_quick_publish_'.$post_id);
        if ( ! $post_id || get_post_type($post_id)!=='tribe_events' ) wp_die('no');
        wp_update_post(['ID'=>$post_id,'post_status'=>'publish']);
        delete_post_meta($post_id,'_tec_comm_token');
        wp_safe_redirect( get_edit_post_link($post_id,'raw') ?: admin_url('edit.php?post_type=tribe_events') );
        exit;
    }
}
