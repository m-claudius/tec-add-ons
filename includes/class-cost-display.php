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

        // 1) Rohwert entscheidet. Steht im Preisfeld eine Null, ist die Sache klar -
        //    unabhängig von Übersetzung, Währungssymbol und Textbaustein.
        if ( $post_id && self::meta_reads_as_free( get_post_meta( (int) $post_id, '_EventCost', true ) ) ) {
            return '';
        }

        // 2) Kein Rohwert greifbar (z.B. Preis kommt aus einem Ticket): dann am
        //    Ausgabetext entscheiden. $free ist genau der String, den
        //    maybe_replace_cost_with_free() erzeugt hätte.
        $free = esc_html__( 'Free', 'tribe-common' );

        return self::reads_as_free( (string) $cost, (string) $free ) ? '' : $cost;
    }

    /**
     * Ist der Rohwert aus _EventCost eine Null?
     *
     * Ein leeres Feld heißt „kein Preis hinterlegt“ und wird nicht angefasst –
     * dann darf ein Ticketpreis durchkommen.
     *
     * @param mixed $raw
     */
    public static function meta_reads_as_free( $raw ) {
        if ( is_array( $raw ) ) { $raw = reset( $raw ); }
        $raw = trim( (string) $raw );
        if ( $raw === '' ) { return false; }

        $raw = str_replace( ',', '.', $raw );
        if ( ! is_numeric( $raw ) ) { return false; }

        return number_format( (float) $raw, 2, '.', ',' ) === '0.00';
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
