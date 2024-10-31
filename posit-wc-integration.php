<?php
/**
 * POSIT Integration.
 *
 * @package   Posit POS integration for WooCommerce
 * @category Integration
 * @author   POSIT
 */
if (!class_exists('WC_POSIT_Integration')) :
    class WC_POSIT_Integration extends WC_Integration
    {

        private const INVENTORY_UPDATE_INTERVAL = 3600; // 1 hour in seconds

        /**
         * API Key for Posit API
         * @var string
         */
        private string $api_key;
        /**
         * Pos ID for Posit Sales API
         * @var string
         */
        private string $pos_id;

        /**
         * Tenant URL
         * @var string
         */
        private string $tenant;

        /**
         * Voucher ID for Posit Sales API
         * @var string
         */
        private string $voucher_id;

        /**
         * Last time inventory was updated
         * @var string
         */
        private string $last_inventory_update;
        private bool $enable_inventory_interface;
        private bool $enable_sales_interface;
        private bool $enable_inventory_update_along_processing_order_items;
        private bool $email_failed_sales;
        private const INVOICE_TYPES = [
            'debit' => 1,
            'refund' => 2,
        ];


        /**
         * Init and hook in the integration.
         */
        public function __construct()
        {
            global $woocommerce;
            $this->id = 'posit-integration';
            $this->method_title = __('Posit POS integration for WooCommerce');
            $this->method_description = __('Posit POS integration for WooCommerce Settings');
            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            $this->api_key = $this->get_option('posit_api_key');
            $this->pos_id = $this->get_option('posit_pos_id');
            $this->tenant = $this->get_option('posit_tenant');
            $this->voucher_id = $this->get_option('voucher_id');

            $this->enable_inventory_update_along_processing_order_items = 'yes' === $this->get_option('enable_inventory_update_along_processing_order_items', 'no');
            $this->enable_inventory_interface = 'yes' === $this->get_option('enable_inventory_interface', 'no');
            $this->enable_sales_interface = 'yes' === $this->get_option('enable_sales_interface', 'no');
            $this->email_failed_sales = 'yes' === $this->get_option('email_failed_sales', 'no');
            $this->last_inventory_update = $this->get_option('last_inventory_update') ?? 0;

            // Actions.
            add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_checkout_create_order', array($this, 'posit_success_flag_order'));
            add_action('woocommerce_after_register_post_type', array($this, 'posit_cron_setup'));
            if ($this->enable_sales_interface) {
                add_action('woocommerce_order_status_completed', array($this, 'posit_send_completed_order'));
                add_action('woocommerce_order_status_cancelled', array($this, 'posit_send_refund_order'));
                add_action('woocommerce_order_status_refunded', array($this, 'posit_send_refund_order'));
            }

            add_filter('woocommerce_order_actions', array($this, 'add_resend_to_posit_icon'));
            add_action('woocommerce_order_action_resend_to_posit', array($this, 'process_resend_action'));
            add_action('woocommerce_order_action_resend_to_posit_for_refund', array($this, 'process_resend_action_for_refund'));
        }

        function process_resend_action(WC_Order $order): void
        {
            $this->posit_send_completed_order($order->get_id());
        }
        function process_resend_action_for_refund(WC_Order $order): void
        {
            $this->posit_send_refund_order($order->get_id());
        }

        function add_resend_to_posit_icon($actions)
        {
            // Add your custom action
            $actions['resend_to_posit'] = __('Resend Order to POSIT');
            $actions['resend_to_posit_for_refund'] = __('Refund Order from POSIT');
            return $actions;
        }

        public function process_admin_options()
        {
            update_option('last_inventory_update', 0);
            parent::process_admin_options();
        }

        /**
         * Initialize integration settings form fields.
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'api_key' => array(
                    'id' => 'wc_posit_api_key',
                    'title' => __('API KEY'),
                    'type' => 'text',
                    'description' => __('Enter your Posit API KEY here'),
                    'desc_tip' => true,
                    'default' => '',
                    'css' => 'width:450px;',
                ),
                'pos_id' => array(
                    'id' => 'wc_posit_pos_id',
                    'title' => __('POS Key'),
                    'type' => 'text',
                    'description' => __('Enter your Posit POS Key here'),
                    'desc_tip' => true,
                    'default' => '',
                    'css' => 'width:450px;',
                ),
                'tenant' => array(
                    'id' => 'wc_posit_tenant',
                    'title' => __('Tenant URL'),
                    'type' => 'text',
                    'description' => __('Enter your Tenant URL'),
                    'desc_tip' => true,
                    'default' => '',
                    'css' => 'width:450px;',
                ),
                'voucher_id' => array(
                    'id' => 'wc_posit_voucher_id',
                    'title' => __('Voucher ID'),
                    'type' => 'text',
                    'description' => __('Enter your Voucher ID'),
                    'desc_tip' => true,
                    'default' => '133337',
                    'css' => 'width:450px;',
                ),
                'inventory_type' => array(
                    'id' => 'wc_posit_inventory',
                    'title' => __('Inventory Type'),
                    'type' => 'select',
                    'options' => array(
                        'store_inventory' => __('Store Inventory', 'woocommerce'),
                        'company_inventory' => __('Company Inventory', 'woocommerce'),
                    ),
                    'desc_tip' => true,
                    'default' => 'store_inventory',
                    'css' => 'width:450px;',
                ),
                'enable_inventory_interface' => array(
                    'id' => 'wc_posit_enable_inventory_interface',
                    'title' => __('Enable Inventory Interface'),
                    'type' => 'checkbox',
                    'desc_tip' => __('Check this box if you want to use the inventory interface.'),
                    'default' => 1,
                ),
                'enable_sales_interface' => array(
                    'id' => 'enable_sales_interface',
                    'title' => __('Enable Sales Interface'),
                    'type' => 'checkbox',
                    'desc_tip' => __('Check this box if you want to use the sales interface.'),
                    'default' => 1,
                ),
                'enable_inventory_update_along_processing_order_items' => array(
                    'id' => 'enable_inventory_update_along_processing_order_items',
                    'title' => __('Update Inventory along with processing Orders\' Items'),
                    'type' => 'checkbox',
                    'desc_tip' => __('Check this box if you want inventory to be updated with both server inventory and processing orders\' items.'),
                    'default' => 1,
                ),
                'email_failed_sales' => array(
                    'id' => 'email_failed_sales',
                    'title' => __('Send Daily Failed Sales Report by email?'),
                    'type' => 'checkbox',
                    'desc_tip' => __('Check this box if you want to receive a daily email with failed sales to the website admin email.'),
                    'default' => 1,
                ),
            );
        }

        /**
         * Add POSIT sent flag to newly created order meta
         *
         * @param $order
         *
         * @return void
         */
        function posit_success_flag_order($order): void
        {
            $order->update_meta_data('posit_success', 0);
        }

        /**
         * Send completed sale to POSIT API
         *
         * @param $order_id
         *
         * @return void
         */
        public function posit_send_completed_order($order_id): bool
        {
            // Get the order object
            $order = wc_get_order($order_id);
            if (!$order) {
                WC_Posit::posit_log('Tried to send non-existing order ID:', $order_id);
                return false;
            }
            try {
                $api_sent = $order->get_meta('posit_success');
                if ($api_sent) {
                    WC_Posit::posit_log('Order #' . $order->get_order_number() . ' Already Sent to POSIT');
                    $order->add_order_note(
                        'Order #' . $order->get_order_number() . ' Already Sent to POSIT'
                    );
                    return false;
                }
                $order->add_order_note('Sending Order to POSIT API');

                $api_key = $this->get_option('api_key');
                $tenant = $this->get_option('tenant');
                $pos_id = $this->get_option('pos_id');
                $voucher_id = $this->get_option('voucher_id');
                if (empty($api_key) || empty($tenant) || empty($pos_id)) {
                    $order->add_order_note('Error: Missing parameters. Please check your settings.');
                    $order->set_status('failed');
                    $order->save();
                    add_action('admin_notices', array($this, 'posit_setup_error_notice'));

                    return false;
                }
                $url = $tenant . "api/sales";

                WC_Posit::posit_log("Sending Order to POSIT", $order_id, $order->get_order_number(), $order->get_total());
                $orderBody = $this->build_sale_posit_format($order, $pos_id, $voucher_id, self::INVOICE_TYPES['debit']);
                WC_Posit::posit_log(json_encode($orderBody));
                $args = array(
                    'headers' => array(
                        'Authorization' => 'Token token=' . $api_key,
                        'Content-Type' => 'application/json; charset=utf-8',
                    ),
                    'body' => json_encode($orderBody),
                    'timeout' => 10
                );
                $response = wp_remote_post($url, $args);
                if (is_wp_error($response)) {
                    // Handle error
                    $error_message = $response->get_error_message();
                    WC_Posit::posit_log("Something went wrong: $error_message");
                    $order->add_order_note(
                        'Error while sending Sale to POSIT, check logs for more details, this could be because of incorrect API key or tenant.'
                    );
                    $order->set_status('failed');
                    $order->save();
                } else {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if ($data) {
                        if ($data['success']) {
                            WC_Posit::posit_log('Order Successfully Sent to POSIT with response:', $body);
                            $order->update_meta_data('posit_success', 1);
                            $response = $data['message'][0];
                            $order->update_meta_data('posit_debit_store_id', $response['store_id']);
                            $order->update_meta_data('posit_debit_invoice', $response['invoice']);
                            $order->save();
                            // After a successful sale we update the stock
                            $this->posit_fetch_inventory();
                            $order->add_order_note(
                                'Order #' . $order->get_id() . ' Successfully Sent to POSIT ' . $response['message'] . ' | invoice ID: ' . $response['invoice']
                            );

                            return true;
                        } else {
                            $response = $data['message'][0];
                            $order->add_order_note(
                                'Error while sending Sale to POSIT: ' . $response['message']
                            );
                            $order->set_status('failed');
                            $order->save();
                        }
                    } else {
                        $order->add_order_note(
                            'Error while sending Sale to POSIT, check logs for more details, this could be because of incorrect API key or tenant.'
                        );
                        $order->set_status('failed');
                        $order->save();
                    }


                }
            } catch (Exception $e) {
                WC_Posit::posit_log('Error while sending Sale to POSIT: ' . $e->getMessage());
                $order->add_order_note(
                    'Error while sending Sale to POSIT: ' . $e->getMessage()
                );
                $order->set_status('failed');
                $order->save();
            }
            return false;
        }

        /**
         * Fetch POSIT Inventory and update WooCommerce
         * @return void
         */
        public function posit_fetch_inventory(): void
        {
            $api_key = $this->get_option('api_key');
            $tenant = $this->get_option('tenant');
            if (empty($api_key) || empty($tenant)) {
                add_action('admin_notices', array($this, 'posit_setup_error_notice'));

                return;
            }
            $url = $tenant . 'api/inventory';
            $args = array(
                'headers' => array(
                    'Accept' => 'application/json',
                    'Authorization' => 'Token  token=' . $api_key,
                    'Content-Type' => 'application/json; charset=utf-8',
                ),
            );
            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                // Handle error
                $error_message = $response->get_error_message();
                WC_Posit::posit_log("Something went wrong: $error_message");
            } else {
                // Process the response
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                WC_Posit::posit_log('fetch_posit_inventory was called with response:  ', substr($body, 0, 200));
                if (empty($data['response'])) {
                    WC_Posit::posit_log('No data received from POSIT');

                    return;
                }
                $serverItems = sizeof($data['response']);
                $updatedItems = 0;
                
                // Get Processing order items
                $enable_inventory_update_along_processing_order_items = 'yes' === $this->get_option('enable_inventory_update_along_processing_order_items', 'no');
                $processing_orders_items_quantity = [];
                if ($enable_inventory_update_along_processing_order_items) {
                    $args = array(
                        'status' => 'processing'
                    );
                    $processing_orders = wc_get_orders($args);
                    foreach ($processing_orders as $order) {
                        $items = $order->get_items();
                        foreach ($items as $order_item) {
                            $quantity = $order_item->get_quantity();
                            $product = $order_item->get_product();
                            if (!$product) {
                                continue;
                            }                            
                            $item_sku = $product->get_sku();
                            if (array_key_exists($item_sku, $processing_orders_items_quantity)) {
                                $processing_orders_items_quantity[$item_sku] += $quantity;
                            } else {
                                $processing_orders_items_quantity[$item_sku] = $quantity;
                            }
                        }
                    }
                }

                foreach ($data['response'] as $item) {
                    $sku = $item['code'];
                    $product_id = wc_get_product_id_by_sku($sku);
                    if ($product_id) {
                        $product_post = get_post($product_id);
                        if ($product_post) {
                            $product = wc_get_product($product_post);
                            if ($product) {
                                $res_item_inv_stock = $item[$this->get_option('inventory_type')];
                                if ($enable_inventory_update_along_processing_order_items) {
                                    $processing_order_item_quantity_quantity = $processing_orders_items_quantity[$sku] ?? 0;
                                    $res_item_inv_stock -= $processing_order_item_quantity_quantity;
                                }
                                if ($product->get_stock_quantity() != $res_item_inv_stock) {
                                    $product->set_manage_stock(true);
                                    $product->set_stock_quantity($res_item_inv_stock);
                                    $product->save();
                                    $updatedItems++;
                                }
                            }
                        }
                    }
                }
                WC_Posit::posit_log('Update inventory was called, Items Received: ', $serverItems, 'Items Updated: ', $updatedItems);
                $this->update_option('last_inventory_update', time());

            }
        }

        
        /**
         * Send completed sale to POSIT API for refund
         *
         * @param $order_id
         *
         * @return void
         */
        public function posit_send_refund_order($order_id): bool
        {
            // Get the order object
            $order = wc_get_order($order_id);
            if (!$order) {
                WC_Posit::posit_log('Tried to send non-existing order ID:', $order_id);
                return false;
            }
            try {
                $api_sent = $order->get_meta('posit_success');
                $api_refund_sent = $order->get_meta('posit_refund_success');
                $api_debit_invoice = $order->get_meta('posit_debit_invoice');
                $api_store_id = $order->get_meta('posit_debit_store_id');
                if (!$api_sent) {
                    WC_Posit::posit_log('Order #' . $order->get_order_number() . ' Cannot be refunded to POSIT, it was not sent to POSIT for charge');
                    $order->add_order_note(
                        'Order #' . $order->get_order_number() . ' Cannot be refunded to POSIT, it was not sent to POSIT for charge'
                    );
                    return false;
                }
                if ($api_refund_sent) {
                    WC_Posit::posit_log('Order #' . $order->get_order_number() . ' Already Sent to POSIT for refund');
                    $order->add_order_note(
                        'Order #' . $order->get_order_number() . ' Already Sent to POSIT for refund'
                    );
                    return false;
                }
                if (!$api_debit_invoice) {
                    WC_Posit::posit_log('Order #' . $order->get_order_number() . ' Cannot be refunded to POSIT, cannot find debit invoice number');
                    $order->add_order_note(
                        'Order #' . $order->get_order_number() . ' Cannot be refunded to POSIT, cannot find debit invoice number'
                    );
                    return false;
                }
                if (!$api_store_id) {
                    WC_Posit::posit_log('Order #' . $order->get_order_number() . ' Cannot be refunded to POSIT, cannot find debit sale\'s store ID');
                    $order->add_order_note(
                        'Order #' . $order->get_order_number() . ' Cannot be refunded to POSIT, cannot find debit sale\'s store ID'
                    );
                    return false;
                }
                $order->add_order_note('Sending Order to POSIT API for refund');

                $api_key = $this->get_option('api_key');
                $tenant = $this->get_option('tenant');
                $pos_id = $this->get_option('pos_id');
                $voucher_id = $this->get_option('voucher_id');
                if (empty($api_key) || empty($tenant) || empty($pos_id)) {
                    $order->add_order_note('Error: Missing parameters. Please check your settings.');
                    $order->set_status('failed');
                    $order->save();
                    add_action('admin_notices', array($this, 'posit_setup_error_notice'));

                    return false;
                }
                $url = $tenant . "api/sales";

                WC_Posit::posit_log("Sending Order to POSIT", $order_id, $order->get_order_number(), $order->get_total());
                $orderBody = $this->build_sale_posit_format($order, $pos_id, $voucher_id, 2, self::INVOICE_TYPES['refund']);
                WC_Posit::posit_log(json_encode($orderBody));
                $args = array(
                    'headers' => array(
                        'Authorization' => 'Token token=' . $api_key,
                        'Content-Type' => 'application/json; charset=utf-8',
                    ),
                    'body' => json_encode($orderBody),
                    'timeout' => 10
                );
                $response = wp_remote_post($url, $args);
                if (is_wp_error($response)) {
                    // Handle error
                    $error_message = $response->get_error_message();
                    WC_Posit::posit_log("Something went wrong: $error_message");
                    $order->add_order_note(
                        'Error while sending Sale to POSIT for refund, check logs for more details, this could be because of incorrect API key or tenant.'
                    );
                    $order->set_status('failed');
                    $order->save();
                } else {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if ($data) {
                        if ($data['success']) {
                            WC_Posit::posit_log('Order Successfully Sent to POSIT for refund with response:', $body);
                            $order->update_meta_data('posit_refund_success', 1);
                            $order->save();
                            // After a successful sale we update the stock
                            $this->posit_fetch_inventory();
                            $response = $data['message'][0];
                            $order->add_order_note(
                                'Order #' . $order->get_id() . ' Successfully Sent to POSIT for refund ' . $response['message'] . ' | invoice ID: ' . $response['invoice']
                            );

                            return true;
                        } else {
                            $response = $data['message'][0];
                            $order->add_order_note(
                                'Error while sending Sale to POSIT: ' . $response['message']
                            );
                            $order->set_status('failed');
                            $order->save();
                        }
                    } else {
                        $order->add_order_note(
                            'Error while sending Sale to POSIT, check logs for more details, this could be because of incorrect API key or tenant.'
                        );
                        $order->set_status('failed');
                        $order->save();
                    }


                }
            } catch (Exception $e) {
                WC_Posit::posit_log('Error while sending Sale to POSIT: ' . $e->getMessage());
                $order->add_order_note(
                    'Error while sending Sale to POSIT: ' . $e->getMessage()
                );
                $order->set_status('failed');
                $order->save();
            }
            return false;
        }

        public function posit_setup_error_notice()
        {
            ?>
            <div class="notice notice-error is-dismissible">
                <h2><?php _e('Please finish setting up POSIT Integration', 'posit-pos-integration-for-woocommerce'); ?></h2>
                <p><?php _e('Please go to WooCommerce > Settings > POSIT Integration and finish the setup', 'posit-pos-integration-for-woocommerce'); ?></p>
                <p>
                    <a href="<?php echo menu_page_url(POSIT_PLUGIN_SLUG, false); ?>&tab=integration&section=posit-integration"><?php _e('Check Settings', 'posit-pos-integration-for-woocommerce') ?></a>
                </p>
            </div>
            <?php
        }


        /**
         * Build the sale format for POSIT API
         * @param WC_Order $order
         * @param string $pos_id
         * @param string $voucher_id
         * @param int $invoice_type
         * @return array|array[]
         */
        public function build_sale_posit_format(WC_Order $order, string $pos_id, string $voucher_id, int $invoice_type): array
        {
            $is_refund_sale = $invoice_type == self::INVOICE_TYPES['refund'];
            $tax_total = $order->get_total_tax();
            $vatable_amount = $this->calculate_vatable_amount($order->get_items()) + $order->get_shipping_tax();
            $vatable_amount_without_vat = $vatable_amount - $tax_total;
            if ($vatable_amount_without_vat == 0) {
                $tax_rate = 0;
            } else {
                $tax_rate = $tax_total / $vatable_amount_without_vat;
            }
            if (function_exists('wc_get_custom_checkout_fields')) {
                $fields = wc_get_custom_checkout_fields($order);
                $mailing_list = wc_get_checkout_field_value($order, 'mailing_list', $fields['mailing_list']);
                $mailing_list = (bool)$mailing_list;
            } else {
                $mailing_list = false;
            }

            // Build the POST array
            $postFields = array(
                'sales' => array(
                    array(
                        'sale' => array(
                            'invoice_type' => $invoice_type,
                            'physical_pos_id' => $pos_id,
                            'total_quantity' => $order->get_item_count() * ($is_refund_sale ? -1 : 1),
                            'total_amount' => $order->get_total() * ($is_refund_sale ? -1 : 1),
                            'total_vatable_amount' =>  $vatable_amount * ($is_refund_sale ? -1 : 1),
                            'vat' => round($tax_rate * 100, 2),
                            'external_id' => $order->get_order_number(),
                            'external_post_id' => $order->get_id(),
                            'parent_invoice_number' => $is_refund_sale ? $order->get_meta('posit_debit_invoice') : null,
                            'parent_invoice_store_id' => $is_refund_sale ? $order->get_meta('posit_debit_store_id') : null,
                        ),
                        'customer' => array(
                            'first_name' => $order->get_billing_first_name(),
                            'last_name' => $order->get_billing_last_name(),
                            'email' => $order->get_billing_email(),
                            'phone' => $order->get_billing_phone(),
                            'address' => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
                            'city' => $order->get_billing_city(),
                            'mailing_list' => $mailing_list,
                        ),
                        'items' => array(),
                        'payments' => array(
                            array(
                                'method' => 3, // Voucher
                                'amount' => $order->get_total() * ($is_refund_sale ? -1 : 1),
                                "voucher_type" => $voucher_id, // Pre defined voucher type for internet sales

                            ),
                        ),
                    ),
                ),
            );
            // Build the Items array
            $items = $order->get_items();
            $tax = new WC_Tax();

            foreach ($items as $key => $item) {
                $product = $item->get_product(); // Get the product object directly from the order item
                if ($product) {
                    $quantity = $item->get_quantity();

                    $line_subtotal = $item->get_subtotal(); // total before discounts for all units
                    $line_subtotal_tax = $item->get_subtotal_tax(); // total tax before discounts for all units
                    if ($line_subtotal == 0) {
                        $tax_rate = 0;
                        $tax_amount_per_item = 0; // Prevent division by zero if line subtotal is zero
                    } else {
                        $tax_rate = $line_subtotal_tax / $line_subtotal; // tax rate for all units
                        $tax_amount_per_item = $line_subtotal_tax / $quantity; // tax amount per unit
                    }
                    $tax_rate_as_percent = round($tax_rate * 100); // tax rate for all units as a percentage
                    $line_total = $item->get_total(); // total after discounts for all units

                    // Calculate the original unit price (before discount)
                    $original_unit_price = $line_subtotal / $quantity;

                    // Calculate the discounted unit price (after discount)
                    $discounted_unit_price = $line_total / $quantity;

                    // Calculate the discount amount per unit
                    $discount_amount_per_unit = $original_unit_price - $discounted_unit_price;
                    $discount_amount_per_unit_with_tax = $discount_amount_per_unit + ($discount_amount_per_unit * $tax_rate);

                    $item_price_with_tax = $original_unit_price + $tax_amount_per_item;

                    $postFields['sales'][0]['items'][] = array(
                        'row_number' => $key + 1,
                        'quantity' => $quantity * ($is_refund_sale ? -1 : 1),
                        'unit_price' => round($item_price_with_tax, 2),
                        'item_code' => !empty($product->get_sku()) ? $product->get_sku() : "1000",
                        'barcode' => !empty($product->get_sku()) ? $product->get_sku() : "1000",
                        'item_desc' => $product->get_name(),
                        'vat' => $tax_rate_as_percent,
                        'discountAmount' => round($discount_amount_per_unit_with_tax * $quantity, 2) * ($is_refund_sale ? -1 : 1),
                    );
                }
            }
            // Add shipping as an item
            if ($order->get_shipping_total() > 0) {
                $total_shipping_cost = floatval($order->get_shipping_total());
                $shipping_tax = floatval($order->get_shipping_tax());
                $total_with_tax = $total_shipping_cost + $shipping_tax;
                $vat_rate_percent = $shipping_tax > 0 ? (($shipping_tax / $total_shipping_cost) * 100)   : 0;

                $postFields['sales'][0]['items'][] = array(
                    'row_number' => count($items) + 1,
                    'quantity' => 1,
                    'unit_price' => round($total_with_tax, 2),
                    'item_code' => "1000",
                    'barcode' => "shipping",
                    'item_desc' => $order->get_shipping_method(),
                    'vat' => round($vat_rate_percent, 2),
                );
            }

            return $postFields;
        }

        /**
         * Calculate the amount of VAT for the order
         *
         * @param WC_Order_Item[] $items
         *
         * @return float
         */
        private function calculate_vatable_amount(array $items): float
        {
            $total = 0;
            foreach ($items as $key => $item) {
                if ($item && is_a($item, 'WC_Order_Item_Product')) {
                    $product_id = $item->get_product_id();
                    $product = wc_get_product($product_id);
                    if ($product->is_taxable()) {
                        $total += $item->get_total() + $item->get_total_tax();
                    }
                }
            }

            return round($total, 2);
        }

        /**
         * @throws Exception
         */
        private function get_orders_with_meta_field($meta_key, $meta_value)
        {
            $args = array(
                'status' => array('failed'),
                'meta_key' => $meta_key, // The meta key you want to search for
                'meta_value' => $meta_value, // The meta value you want to match
            );

            $query = new WC_Order_Query($args);

            return $query->get_orders();
        }

        /*
         *  ------------------------------------------------------------
         *  CRON JOBS
         *  ------------------------------------------------------------
         */
        public function posit_cron_setup(): void
        {
            // Cron Jobs and Schedules Setup
            add_filter('cron_schedules', array($this, 'posit_cron_scheduler'));
            // Update the inventory every Hour
            if ($this->get_option('enable_inventory_interface')) {
                if (!wp_next_scheduled('posit_inventory_cron')) {
                    wp_schedule_event(time(), 'hourly', 'posit_inventory_cron');
                }
                add_action('posit_inventory_cron', array($this, 'posit_inventory_cron'));
                // update the inventory if the last update was more than 1 hour ago
                if (time() - (int)$this->last_inventory_update > self::INVENTORY_UPDATE_INTERVAL ) {
                    $this->posit_fetch_inventory();
                }
            }
            // Send failed sales report every day
            if ($this->get_option('email_failed_sales')) {
                if (!wp_next_scheduled('email_failed_sales_cron')) {
                    wp_schedule_event(time(), 'daily', 'email_failed_sales_cron');
                }
                add_action('email_failed_sales_cron', array($this, 'email_failed_sales_cron'));
            }
        }

        function posit_cron_scheduler($schedules)
        {
            $schedules['hourly'] = array(
                'interval' => 3600, // 1 hour in seconds
                'display' => __('Hourly'),
            );

            return $schedules;
        }

        public function posit_inventory_cron(): void
        {
            $this->posit_fetch_inventory();
        }

        public function email_failed_sales_cron(): void
        {
            try {
                if (!$this->get_option('email_failed_sales')) {
                    return;
                }
                // Get failed orders
                $args = array(
                    'status' => 'failed',
                );
                $failed_orders = wc_get_orders($args);
                if(empty($failed_orders)){
                    return;
                }
                $headers = array('Content-Type: text/html; charset=UTF-8');
                $today = date('d/m/Y');
                $title = "דוח הזמנות שנכשלו לתאריך {$today} ";
                // Start of email HTML content
                $email_content = "<html dir=\"rtl\" lang=\"he\">
<head><style>body, ul, h2, h3, p { direction: rtl; text-align: right; }</style></head>
<body style='font-family: Arial, sans-serif;'>
<h2 style='color: #333;'>{$title}</h2>
<p>ההזמנות הבאות נמצאות בסטטוס נכשל:</p>
<ul>";
                foreach ($failed_orders as $order) {
                    $order_id = $order->get_id();
                    $order_edit_link = admin_url('post.php?post=' . $order_id . '&action=edit');
                    $email_content .= '<li>הזמנה <a href="' . $order_edit_link . '">#' . $order->get_order_number() . '</a> - סכום הזמנה: ' . $order->get_formatted_order_total() . "</li>";
                }
                $email_content .= "
</ul>
<br>
<h3>ניתן לשלוח מחדש הזמנות אלו דרך עריכת הזמנה -> פעולות -> Resend Order to POSIT</h3>
</body></html>";


                // Send the email
                wp_mail(get_option('admin_email'), $title, $email_content, $headers);
            } catch (Exception $e) {
                WC_Posit::posit_log('Error while sending sales to POSIT on Cron: ' . $e->getMessage());
            }

        }

    }
endif;