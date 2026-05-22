<?php
namespace TEC_Addons\Mailing;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Renderer', false)) { return; }

class Renderer {

    public static function logo_url(): string {
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

    public static function greeting(array $subscriber): string {
        $name = isset($subscriber['name']) ? trim((string)$subscriber['name']) : '';
        if ($name !== '') return 'Hallo '.esc_html($name);
        return 'Hallo';
    }

    // Liefert HTML (bereits sanitisiert) für Weekly/Monthly-Intro
    public static function intro_text(string $kind): string {
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

    public static function get_event_image_url(int $post_id): string {
        $img = get_the_post_thumbnail_url($post_id, 'large');
        return ($img && filter_var($img, FILTER_VALIDATE_URL)) ? $img : '';
    }

    public static function get_event_venue_text(int $post_id): string {
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

    public static function get_event_excerpt(int $post_id): string {
        $ex = get_the_excerpt($post_id);
        if ($ex && trim($ex)!=='') return wp_strip_all_tags($ex);
        $content = wp_strip_all_tags( (string) get_post_field('post_content',$post_id) );
        $content = trim(preg_replace('/\s+/',' ', $content));
        if ($content==='') return '';
        $words = preg_split('/\s+/', $content);
        if (count($words)>50) $content = implode(' ', array_slice($words,0,50)).'…';
        return $content;
    }

    public static function format_dt_de(?string $mysql_datetime): string {
        if (!$mysql_datetime) return '';
        $ts = mysql2date('U', $mysql_datetime);
        if (!$ts) return '';
        return date_i18n('D., d.m.Y H:i', $ts);
    }

    public static function render_event_item(int $post_id): string {
        $title = get_the_title($post_id);

        // Link: bevorzugt externe Event-Website (_EventURL), sonst Permalink
        $ext = trim((string) get_post_meta($post_id, '_EventURL', true));
        $url = $ext && filter_var($ext, FILTER_VALIDATE_URL) ? $ext : get_permalink($post_id);

        $start = get_post_meta($post_id,'_EventStartDate',true);
        $when  = $start ? self::format_dt_de($start) : '';

        $place = self::get_event_venue_text($post_id);

        $sources     = Sources::event_source_names($post_id);
        $source_line = $sources ? implode(', ', $sources) : '';
        if ($source_line==='' && Sources::is_own_event($post_id)) { $source_line = Sources::own_source_name(); }

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

    public static function build_mail_html(string $subject_label, string $kind, string $start_mysql, string $end_mysql, ?array $subscriber = null): string {
        // Quellenfilter mappen
        $filter = ['event_source'=>[], 'tribe_quelle'=>[]];
        if ($subscriber && array_key_exists('sources',$subscriber) && is_array($subscriber['sources'])) {
            $filter = Sources::map_sources_to_term_ids($subscriber['sources']);
        }

        // Filter neutralisieren, wenn „alles" ausgewählt wurde
        $all_es = [];
        if ( taxonomy_exists('event_source') ) {
            $all_es = get_terms(['taxonomy'=>'event_source','hide_empty'=>false,'fields'=>'ids']);
        }
        $all_qc = [];
        if ( taxonomy_exists('tribe_events_cat') ) {
            $pid = Sources::get_quelle_parent_id();
            if ($pid) { $all_qc = get_terms(['taxonomy'=>'tribe_events_cat','hide_empty'=>false,'fields'=>'ids','parent'=>$pid]); }
        }
        $es_all_selected = !empty($filter['event_source']) && !array_diff((array)$all_es,(array)$filter['event_source']);
        $qc_all_selected = !empty($filter['tribe_quelle']) && !array_diff((array)$all_qc,(array)$filter['tribe_quelle']);
        if ( empty($filter['event_source']) && empty($filter['tribe_quelle']) || ($es_all_selected && $qc_all_selected) ) {
            $filter = ['event_source'=>[], 'tribe_quelle'=>[]];
        }

        $post_ids = Sources::fetch_events($start_mysql, $end_mysql, $filter);

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
}
