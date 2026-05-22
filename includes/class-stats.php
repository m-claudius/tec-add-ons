<?php
namespace TEC_Addons;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Stats {
    public static function init(){ /* aktuell nichts nötig */ }

    public static function render(){
        global $wpdb; $table=$wpdb->prefix.'tec_addons_subscribers';
        if ( $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) ) !== $table ) {
            echo '<div class="notice notice-warning"><p>'.esc_html__('Abo-Tabelle noch nicht angelegt. Bitte einmal das Plugin neu aktivieren.','tec-add-ons').'</p></div>';
            return;
        }

        $total  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='active'");
        $pending= (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending'");
        $unsub  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='unsubscribed'");
        $w      = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE frequency='weekly'");
        $m      = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE frequency='monthly'");

        echo '<table class="widefat striped" style="max-width:700px">';
        echo '<thead><tr><th>Metric</th><th>Wert</th></tr></thead><tbody>';
        echo '<tr><td>Gesamt</td><td>'.$total.'</td></tr>';
        echo '<tr><td>Aktiv</td><td>'.$active.'</td></tr>';
        echo '<tr><td>Ausstehend</td><td>'.$pending.'</td></tr>';
        echo '<tr><td>Abgemeldet</td><td>'.$unsub.'</td></tr>';
        echo '<tr><td>Wöchentlich</td><td>'.$w.'</td></tr>';
        echo '<tr><td>Monatlich</td><td>'.$m.'</td></tr>';
        echo '</tbody></table>';

        $rows = $wpdb->get_results("SELECT sources FROM {$table} WHERE status='active'");
        $freq = [];
        if($rows){
            foreach($rows as $r){
                $srcs = maybe_unserialize($r->sources);
                if(is_array($srcs)){
                    foreach($srcs as $s){
                        $key = sanitize_title($s);
                        $freq[$key] = isset($freq[$key]) ? $freq[$key]+1 : 1;
                    }
                }
            }
        }
        if(!empty($freq)){
            echo '<h2 style="margin-top:20px">Quellen (aktive Abonnenten)</h2>';
            echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Quelle</th><th>Abonnenten</th></tr></thead><tbody>';
            foreach($freq as $k=>$v){
                echo '<tr><td>'.esc_html($k).'</td><td>'.intval($v).'</td></tr>';
            }
            echo '</tbody></table>';
        }
    }
}
