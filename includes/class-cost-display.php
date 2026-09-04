<?php
namespace TEC_Addons;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Preisangabe „Kostenlos“ unterdrücken
 *
 * Belegt im Quellcode von The Events Calendar 6.9.1:
 *
 *   Tribe__Cost_Utils::maybe_replace_cost_with_free()
 *     -> ist der Preis numerisch und formatiert sich zu "0.00",
 *        wird daraus esc_html__( 'Free', 'tribe-common' ), deutsch „Kostenlos“.
 *
 *   Tribe__Events__Cost_Utils::get_event_costs()
 *     -> leere Werte werden herausgefiltert; ein leeres Preisfeld erzeugt
 *        also gar keine Ausgabe.
 *
 * Eine Null im Preisfeld ist damit die einzige Quelle des Wortes. Sie kann von
 * Hand eingetragen sein, beim Anlegen über die TEC-ORM entstehen oder von
 * Event Tickets stammen, das bei Tickets und Reservierungen ohne Preis eine 0
 * hinterlegt. Dieser Filter setzt am Ausgabewert an und greift deshalb in
 * allen drei Fällen.
 *
 * tribe_get_cost() ist der letzte Filter vor der Ausgabe:
 *   return apply_filters( 'tribe_get_cost', $cost, $post_id, $with_currency_symbol );
 * tribe_get_formatted_cost() ruft tribe_get_cost() intern auf, ist also
 * mit abgedeckt.
 *
 * Echte Beträge bleiben unangetastet – nur was sich zu null ausrechnet,
 * verschwindet.
 */
class Cost_Display {

    const OPTION = 'tec_addons_hide_zero_cost';

    public static function init() {
        if ( get_option( self::OPTION, 'yes' ) !== 'yes' ) { return; }
        add_filter( 'tribe_get_cost', [__CLASS__, 'filter_cost'], 20, 3 );
    }

    /**
     * @param mixed    $cost                 Wert, wie TEC ihn ausgeben würde
     * @param int|null $post_id
     * @param bool     $with_currency_symbol
     * @return mixed
     */
    public static function filter_cost( $cost, $post_id = null, $with_currency_symbol = false ) {
        /**
         * Einzelne Veranstaltungen ausnehmen.
         *
         * @param bool     $hide
         * @param int|null $post_id
         */
        if ( ! apply_filters( 'tec_addons_hide_zero_cost', true, $post_id ) ) {
            return $cost;
        }

        if ( ! is_string( $cost ) && ! is_numeric( $cost ) ) { return $cost; }

        // 1) Rohwert entscheidet. Steht im Preisfeld nur eine Null, ist die Sache
        //    klar - unabhängig von Übersetzung, Währungssymbol und Textbaustein.
        //    Bewusst alle Meta-Werte: ein Event kann mehrere _EventCost-Zeilen
        //    haben, TEC bildet daraus eine Spanne.
        if ( $post_id && self::meta_reads_as_free( get_post_meta( (int) $post_id, '_EventCost', false ) ) ) {
            return '';
        }

        // 2) Kein Rohwert greifbar (z.B. Preis kommt aus einem Ticket): dann am
        //    Ausgabetext entscheiden. $free ist genau der String, den
        //    maybe_replace_cost_with_free() erzeugt hätte.
        $free = esc_html__( 'Free', 'tribe-common' );

        return self::reads_as_free( (string) $cost, (string) $free ) ? '' : $cost;
    }

    /**
     * Sind die Rohwerte aus _EventCost ausschließlich Nullen?
     *
     * Ein leeres Feld heißt „kein Preis hinterlegt“ und wird nicht angefasst –
     * dann darf ein Ticketpreis durchkommen. Steht neben einer Null ein echter
     * Betrag, bleibt die Angabe ebenfalls stehen; dieser Fall gehört bereinigt,
     * nicht versteckt (siehe redundant_zero_cost_ids()).
     *
     * @param mixed $raw Einzelwert oder Array aller Meta-Werte
     */
    public static function meta_reads_as_free( $raw ) {
        $values = is_array( $raw ) ? $raw : [ $raw ];
        $seen   = false;

        foreach ( $values as $value ) {
            $value = trim( (string) $value );
            if ( $value === '' ) { continue; }

            $seen  = true;
            $value = str_replace( ',', '.', $value );
            if ( ! is_numeric( $value ) ) { return false; }
            if ( number_format( (float) $value, 2, '.', ',' ) !== '0.00' ) { return false; }
        }

        return $seen;
    }

    /**
     * Veranstaltungen, bei denen neben einem echten Betrag zusätzlich eine Null
     * im Preisfeld steht.
     *
     * TEC bildet daraus eine Spanne und zeigt „Kostenlos – 15,00 €“. Die Null
     * ist in diesem Fall ein Datenrest, kein Preis – wegräumen statt verstecken.
     *
     * @return int[]
     */
    public static function redundant_zero_cost_ids() {
        global $wpdb;

        $sql = "SELECT DISTINCT z.post_id
                  FROM {$wpdb->postmeta} z
                  INNER JOIN {$wpdb->postmeta} v
                          ON v.post_id = z.post_id AND v.meta_key = '_EventCost'
                  INNER JOIN {$wpdb->posts} p ON p.ID = z.post_id
                 WHERE z.meta_key = '_EventCost'
                   AND p.post_type = 'tribe_events'
                   AND TRIM( z.meta_value ) REGEXP '^0([.,]0+)?$'
                   AND TRIM( v.meta_value ) <> ''
                   AND TRIM( v.meta_value ) NOT REGEXP '^0([.,]0+)?$'";

        return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
    }

    /**
     * Entfernt bei diesen Veranstaltungen die überzähligen Null-Zeilen.
     *
     * @return int Anzahl der bereinigten Veranstaltungen
     */
    public static function clean_redundant_zero_costs() {
        $done = 0;
        foreach ( self::redundant_zero_cost_ids() as $post_id ) {
            foreach ( (array) get_post_meta( $post_id, '_EventCost', false ) as $value ) {
                if ( self::meta_reads_as_free( $value ) ) {
                    delete_post_meta( $post_id, '_EventCost', $value );
                }
            }
            clean_post_cache( $post_id );
            $done++;
        }
        return $done;
    }

    /**
     * Läuft diese Preisangabe auf „kostenlos“ hinaus?
     *
     * Public, damit sie ohne WordPress geprüft werden kann – siehe
     * tests/test-cost-display.php.
     *
     * @param string $cost       z.B. "0", "0,00 €", "Kostenlos", "15 €"
     * @param string $free_label Das übersetzte Wort, das TEC für eine Null einsetzt
     */
    public static function reads_as_free( $cost, $free_label = 'Free' ) {
        $cost = trim( (string) $cost );
        if ( $cost === '' ) { return false; } // kein Preis gesetzt: TEC zeigt ohnehin nichts

        // Kein fest verdrahtetes „Kostenlos“, damit eine geänderte Übersetzung
        // den Filter nicht aushebelt.
        $free_label = trim( (string) $free_label );
        if ( $free_label !== '' && strcasecmp( $cost, $free_label ) === 0 ) { return true; }

        // Zahlenwert herausschälen: Währungssymbole und Text weg
        $plain = trim( (string) preg_replace( '~[^0-9,.\-]~u', '', $cost ) );
        if ( $plain === '' ) { return false; }

        $plain = str_replace( ',', '.', $plain );
        if ( ! is_numeric( $plain ) ) { return false; }

        return number_format( (float) $plain, 2, '.', ',' ) === '0.00';
    }
}
