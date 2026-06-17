<?php
namespace TEC_Addons\Community;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Mailer', false)) { return; }

/**
 * Community-Mails: E-Mail-Vorlagen seeden, Bestätigung an Einreicher,
 * Benachrichtigung an Admin (inkl. Editor- und Direkt-Freigabe-Link).
 */
class Mailer {

    public static function maybe_seed_templates(){
        if ( ! get_option('tec_addons_comm_tpl_submitter') ){
            update_option('tec_addons_comm_tpl_submitter',
                "Hallo {name},\n\nvielen Dank für Ihre Einreichung „{event_title}“. Wir prüfen die Veranstaltung und melden uns.\n\nBearbeiten (solange nicht freigegeben): {edit_link}\n\nBeste Grüße\n{site_name}"
            );
        }
        if ( ! get_option('tec_addons_comm_tpl_admin') ){
            update_option('tec_addons_comm_tpl_admin',
                "Neue Community-Einreichung:\n\nTitel: {event_title}\nDatum: {event_start}\nQuelle: {event_source}\nEinreicher: {submitter_name} <{submitter_email}>\n\nPrüfen/Freigeben (Editor): {admin_edit_link}\nDirekt freigeben: {approve_link}\n\nMögliche Duplikate:\n{duplicates}"
            );
        }
    }

    public static function send_submitter_mail($user,$post_id,$token,$start_mysql,$source_term_id){
        $tpl = get_option('tec_addons_comm_tpl_submitter');
        $repl = [
            '{name}'         => $user->display_name ?: $user->user_email,
            '{email}'        => $user->user_email,
            '{event_title}'  => get_the_title($post_id),
            '{event_start}'  => mysql2date('d.m.Y H:i',$start_mysql),
            '{edit_link}'    => add_query_arg(['tec-edit-event'=>$token,'post'=>$post_id], home_url('/')),
            '{site_name}'    => get_bloginfo('name'),
        ];
        $body = strtr($tpl, $repl);
        wp_mail( $user->user_email, sprintf('[%s] %s', get_bloginfo('name'), __('Einreichung erhalten','tec-add-ons')), $body );
    }

    public static function send_admin_mail($user,$post_id,$start_mysql,$source_term_id,$duplicates){
        $tpl = get_option('tec_addons_comm_tpl_admin');
        $term = ($source_term_id && taxonomy_exists('event_source')) ? get_term($source_term_id,'event_source') : get_term_by('name', \TEC_Addons\Community::DEFAULT_SOURCE_NAME, 'event_source');
        $dups = $duplicates ? "- ".implode("\n- ", $duplicates) : __('keine erkannt','tec-add-ons');

        $admin_edit = get_edit_post_link($post_id,'raw');
        $approve = wp_nonce_url( admin_url('admin-post.php?action=tec_comm_quick_publish&post='.$post_id), 'tec_comm_quick_publish_'.$post_id );

        $repl = [
            '{event_title}'     => get_the_title($post_id),
            '{event_start}'     => mysql2date('d.m.Y H:i',$start_mysql),
            '{event_source}'    => ($term && !is_wp_error($term)) ? $term->name : '—',
            '{submitter_name}'  => $user->display_name ?: $user->user_email,
            '{submitter_email}' => $user->user_email,
            '{admin_edit_link}' => $admin_edit,
            '{approve_link}'    => $approve,
            '{duplicates}'      => $dups,
        ];

        $body = strtr($tpl, $repl);
        if ( strpos($body, $approve) === false ){
            $body .= "\n\n".__('Direkt freigeben: ','tec-add-ons').$approve;
        }
        wp_mail( get_option('admin_email'), sprintf('[%s] %s', get_bloginfo('name'), __('Neue Community-Einreichung','tec-add-ons')), $body );
    }
}
