<?php
/**
 * Customer points: the balance, a history and the account page.
 *
 * Nothing awards points yet. A future feature calls Nexora_Toolkit_Points::add(), and this page
 * shows the result. The page makes no promise about earning rates or what points are worth.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Points.
 */
class Nexora_Toolkit_Points {

	const BALANCE = '_nx_points';
	const LOG     = '_nx_points_log';
	const KEEP    = 200; // History rows kept per customer.

	/**
	 * Hook up.
	 */
	public static function init() {
		if ( self::enabled() ) {
			add_action( 'woocommerce_account_points_endpoint', array( __CLASS__, 'account_tab' ) );
		}
	}

	/**
	 * Is the Points page switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Nexora_Toolkit_Settings::get( 'points_enabled' );
	}

	/**
	 * Address of the page.
	 *
	 * @return string
	 */
	public static function url() {
		return Nexora_Store::active() ? Nexora_Store::url( 'account', 'points' ) : wc_get_account_endpoint_url( 'points' );
	}

	/**
	 * Points a customer has.
	 *
	 * @param int $user User.
	 * @return int
	 */
	public static function balance( $user ) {
		return max( 0, (int) get_user_meta( $user, self::BALANCE, true ) );
	}

	/**
	 * History, newest first.
	 *
	 * @param int $user User.
	 * @return array[] Each: at, points, note, balance.
	 */
	public static function history( $user ) {
		$log = get_user_meta( $user, self::LOG, true );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/**
	 * Add or remove points and record why.
	 *
	 * @param int    $user   User.
	 * @param int    $points Points, negative to remove.
	 * @param string $note   Reason shown to the customer.
	 * @return int|false New balance, or false if the customer does not have enough points.
	 */
	public static function add( $user, $points, $note ) {
		$balance = self::balance( $user ) + (int) $points;
		if ( ! get_userdata( $user ) || ! $points || $balance < 0 ) {
			return false;
		}
		$log   = get_user_meta( $user, self::LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'at'      => time(),
			'points'  => (int) $points,
			'note'    => sanitize_text_field( $note ),
			'balance' => $balance,
		);
		update_user_meta( $user, self::LOG, array_slice( $log, -self::KEEP ) );
		update_user_meta( $user, self::BALANCE, $balance );
		return $balance;
	}

	/**
	 * The account page.
	 */
	public static function account_tab() {
		if ( ! self::enabled() ) {
			return;
		}
		$user    = get_current_user_id();
		$balance = self::balance( $user );
		$history = array_slice( self::history( $user ), 0, 50 );
		?>
		<div class="nx-wallet">
			<header class="nx-wallet__head">
				<h2><?php esc_html_e( 'Points', 'nexora-commerce-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Every point you earn is recorded here.', 'nexora-commerce-toolkit' ); ?></p>
			</header>
			<section class="nx-points-hero" aria-label="<?php esc_attr_e( 'Your points balance', 'nexora-commerce-toolkit' ); ?>">
				<span class="nx-points-hero__icon" aria-hidden="true"><?php echo Nexora_Store::icon( 'star', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<p class="nx-points-hero__label"><?php esc_html_e( 'Points earned', 'nexora-commerce-toolkit' ); ?></p>
					<p class="nx-points-hero__value"><?php echo esc_html( number_format_i18n( $balance ) ); ?></p>
				</div>
				<p class="nx-points-hero__note"><?php esc_html_e( 'Ways to earn and use your points are coming soon.', 'nexora-commerce-toolkit' ); ?></p>
			</section>
			<section aria-labelledby="nx-points-title">
				<h3 id="nx-points-title"><?php esc_html_e( 'Points history', 'nexora-commerce-toolkit' ); ?></h3>
				<?php if ( $history ) : ?>
					<div class="nx-wallet-scroll" tabindex="0" role="group" aria-labelledby="nx-points-title">
						<table class="nx-wallet-table">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Date', 'nexora-commerce-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Details', 'nexora-commerce-toolkit' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Points', 'nexora-commerce-toolkit' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Balance', 'nexora-commerce-toolkit' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $history as $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $row['at'] ) ); ?></td>
									<td><?php echo esc_html( $row['note'] ); ?></td>
									<td class="num"><?php echo esc_html( ( $row['points'] > 0 ? '+' : '−' ) . number_format_i18n( abs( (int) $row['points'] ) ) ); ?></td>
									<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['balance'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<p class="nx-wallet-empty"><?php esc_html_e( 'You have no points yet. Points you earn will be listed here.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}
}
