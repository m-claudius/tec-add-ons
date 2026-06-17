<?php
namespace TEC_Addons;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TEC_Addons\Community\Sources;
use TEC_Addons\Community\Mapping;
use TEC_Addons\Community\Form;
use TEC_Addons\Community\Mailer;

// Doppeldefinition vermeiden
if (class_exists(__NAMESPACE__.'\\Community', false)) { return; }

// Helper sind in tec-add-ons.php geladen; defensive Sicherung:
if ( ! class_exists('TEC_Addons\\Community\\Sources', false) ) {
    require_once __DIR__ . '/community/class-sources.php';
    require_once __DIR__ . '/community/class-mapping.php';
    require_once __DIR__ . '/community/class-mailer.php';
    require_once __DIR__ . '/community/class-form.php';
}

/**
 * Community — Fassade.
 * Interne Logik in TEC_Addons\Community\{Sources,Mapping,Form,Mailer}.
 * Öffentliche API (init, maybe_install_tables, DEFAULT_SOURCE_NAME) unverändert.
 */
class Community {

    // Standard-Quelle (wird automatisch angelegt, falls nicht vorhanden)
    const DEFAULT_SOURCE_NAME = 'Kulturstiftung Seevetal';

    /* =======================
     * Bootstrap
     * ======================= */
    public static function init(){
        add_action('init', [Sources::class, 'register_taxonomies'], 5);

        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);

        add_action('admin_post_tec_addons_comm_save',         [Mapping::class, 'admin_save_mapping']);
        add_action('admin_post_tec_addons_comm_delete',       [Mapping::class, 'admin_delete_mapping']);
        add_action('admin_post_tec_addons_comm_add_source',   [Sources::class, 'admin_add_source']);
        add_action('admin_post_tec_addons_comm_sync_sources', [Sources::class, 'admin_sync_sources']);

        add_shortcode('tec_addons_submit_event', [Form::class, 'shortcode_form']);
        add_action('admin_post_tec_addons_submit_event', [Form::class, 'handle_submit']);

        add_action('template_redirect', [Form::class, 'maybe_render_edit_form']);
        add_filter('login_redirect', [Form::class, 'force_login_redirect'], 9999, 3);

        add_action('admin_post_tec_comm_quick_publish', [Form::class, 'quick_publish']);

        Mailer::maybe_seed_templates();
    }

    // Back-Compat für Bootstrap (tec-add-ons.php)
    public static function maybe_install_tables(){ Mapping::maybe_install_tables(); }

    /* =======================
     * Admin UI
     * ======================= */
    public static function menu(){
        add_submenu_page(
            'tec-add-ons',
            __('Community','tec-add-ons'),
            __('Community','tec-add-ons'),
            'manage_options',
            'tec-add-ons-community',
            [__CLASS__,'render_admin']
        );
    }

    public static function settings(){
        register_setting('tec_addons_comm','tec_addons_comm_tpl_submitter',['type'=>'string','default'=>'']);
        register_setting('tec_addons_comm','tec_addons_comm_tpl_admin',['type'=>'string','default'=>'']);
    }

    public static function render_admin(){
        if ( ! current_user_can('manage_options') ) return;

        $users   = get_users(['fields'=>['ID','display_name','user_email'],'orderby'=>'display_name','order'=>'ASC']);
        $sources = Sources::get_all_sources();
        $map     = Mapping::get_all_mappings();
        $tpl_user  = get_option('tec_addons_comm_tpl_submitter');
        $tpl_admin = get_option('tec_addons_comm_tpl_admin');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Community – Nutzer & Quellen','tec-add-ons'); ?></h1>

            <h2><?php esc_html_e('Quellen verwalten (event_source)','tec-add-ons'); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:1em">
                <?php wp_nonce_field('tec_addons_comm_add_source','_tec_comm_src_nonce'); ?>
                <input type="hidden" name="action" value="tec_addons_comm_add_source">
                <input type="text" name="new_source_name" placeholder="<?php esc_attr_e('Neue Quelle (Name)','tec-add-ons'); ?>" required>
                <button class="button"><?php esc_html_e('Quelle hinzufügen','tec-add-ons'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:2em">
                <?php wp_nonce_field('tec_addons_comm_sync_sources','_tec_comm_sync_nonce'); ?>
                <input type="hidden" name="action" value="tec_addons_comm_sync_sources">
                <button class="button"><?php esc_html_e('Aus Mailing-Quellen synchronisieren','tec-add-ons'); ?></button>
                <p class="description"><?php esc_html_e('Importiert fehlende Quellen aus „TEC add ons → Mailing → Zusätzliche Quellen“.','tec-add-ons'); ?></p>
            </form>

            <h2><?php esc_html_e('Zuordnung: WP-User ↔ Standard-Quelle','tec-add-ons'); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field('tec_addons_comm_save','_tec_comm_nonce'); ?>
                <input type="hidden" name="action" value="tec_addons_comm_save">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Benutzer','tec-add-ons'); ?></th>
                        <td>
                            <select name="user_id">
                                <?php foreach($users as $u): ?>
                                    <option value="<?php echo intval($u->ID); ?>">
                                        <?php echo esc_html($u->display_name.' <'.$u->user_email.'>'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Standard-Quelle (event_source)','tec-add-ons'); ?></th>
                        <td>
                            <select name="source_term_id">
                                <option value=""><?php esc_html_e('— Keine —','tec-add-ons'); ?></option>
                                <?php foreach($sources as $t): ?>
                                    <option value="<?php echo intval($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Vorauswahl im Formular; Nutzer kann Quelle ändern.','tec-add-ons'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Whitelist (Auto-Freigabe)','tec-add-ons'); ?></th>
                        <td><label><input type="checkbox" name="whitelist" value="1"> <?php esc_html_e('Einreichungen werden automatisch veröffentlicht.','tec-add-ons'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button( __('Zuordnung speichern','tec-add-ons') ); ?>
            </form>

            <h3><?php esc_html_e('Bestehende Zuordnungen','tec-add-ons'); ?></h3>
            <table class="widefat striped" style="margin-bottom:2em">
                <thead><tr><th>User</th><th>E-Mail</th><th>Quelle</th><th>Whitelist</th><th>Aktionen</th></tr></thead>
                <tbody>
                    <?php if(empty($map)): ?>
                        <tr><td colspan="5"><?php esc_html_e('Keine Einträge.','tec-add-ons'); ?></td></tr>
                    <?php else: foreach($map as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['display_name']); ?></td>
                            <td><?php echo esc_html($row['user_email']); ?></td>
                            <td><?php echo $row['source_term'] ? esc_html($row['source_term']->name) : '<em>'.esc_html__('—','tec-add-ons').'</em>'; ?></td>
                            <td><?php echo $row['whitelist'] ? '✓' : '–'; ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=tec_addons_comm_delete&user_id='.$row['user_id']), 'tec_addons_comm_delete_'.$row['user_id'] ) ); ?>" onclick="return confirm('<?php echo esc_js(__('Eintrag löschen?','tec-add-ons')); ?>');"><?php esc_html_e('Löschen','tec-add-ons'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <hr>
            <h2><?php esc_html_e('E-Mail-Vorlagen','tec-add-ons'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('tec_addons_comm'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Bestätigung an Einreicher','tec-add-ons'); ?></th>
                        <td>
                            <textarea name="tec_addons_comm_tpl_submitter" class="large-text code" rows="8"><?php echo esc_textarea($tpl_user); ?></textarea>
                            <p class="description"><?php esc_html_e('Platzhalter: {name}, {email}, {event_title}, {event_start}, {edit_link}, {site_name}','tec-add-ons'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Benachrichtigung an Admin','tec-add-ons'); ?></th>
                        <td>
                            <textarea name="tec_addons_comm_tpl_admin" class="large-text code" rows="10"><?php echo esc_textarea($tpl_admin); ?></textarea>
                            <p class="description"><?php esc_html_e('Platzhalter: {event_title}, {event_start}, {event_source}, {submitter_name}, {submitter_email}, {admin_edit_link}, {approve_link}, {duplicates}','tec-add-ons'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __('Vorlagen speichern','tec-add-ons') ); ?>
            </form>
        </div>
        <?php
    }
}
