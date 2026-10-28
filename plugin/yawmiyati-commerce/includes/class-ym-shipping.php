<?php
/**
 * Delivery fee by governorate, charged to the customer once per order (Taager, 28 Oct 2026).
 * The fee is read from YM_Country on the server; nothing from the browser sets it.
 */

defined( 'ABSPATH' ) || exit;

class YM_Shipping extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'ym_governorate';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'توصيل حسب المحافظة (يومياتي)';
		$this->method_description = 'رسوم توصيل تاجر حسب المحافظة، مرة واحدة على الطلب.';
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		$this->title              = 'التوصيل';
		$this->enabled            = 'yes';
	}

	public function is_available( $package ) {
		$dest = $package['destination'] ?? array();
		return ( $dest['country'] ?? '' ) === YM_Country::code();
	}

	public function calculate_shipping( $package = array() ) {
		$state = $package['destination']['state'] ?? '';
		$fee   = YM_Country::fee( $state );
		if ( null === $fee ) {
			return; // No governorate chosen yet: no rate, so checkout cannot complete without one.
		}
		$this->add_rate(
			array(
				'id'        => $this->get_rate_id(),
				'label'     => 'التوصيل',
				'cost'      => $fee,
				'taxes'     => false,
				'meta_data' => array( 'ym_governorate' => $state ),
			)
		);
	}
}
