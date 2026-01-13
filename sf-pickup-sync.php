<?php
/*
Plugin Name: SF Express Pickup (V18.0 Final Complete Suite)
Description: 順豐自提點系統 V18.0：終極完整版。包含 V17.0 所有功能（採購/分配/收款/撿貨/自動發貨），並新增「老闆戰情看板」、「顧客自助查單」與「操作紀錄日誌」。
Version: 18.0
Author: Tiffany
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

// =========================================================================
//  PART 1: 主程式 (包含排版、物流、WhatsApp、導入、收款、撿貨單、採購、看板)
// =========================================================================

class SF_Pickup_Full_Suite {

private $github_urls = array(
        'station' => 'https://cdn.jsdelivr.net/gh/tiffchanwork-cmyk/sf-data-source@main/sf-stores.json',
        'locker'  => 'https://cdn.jsdelivr.net/gh/tiffchanwork-cmyk/sf-data-source@main/sf-lockers.json'
    );
    // 匯出設定 (順豐範本預設值)
    private $config = array(
        'payment'      => '寄付現結', // *付款方式
        'monthly_card' => '',         // 月結卡號
        'content'      => '服飾',     // *托寄物名稱
        'area_code'    => '852',      // *區號
        'province'     => '香港',     // *省
        'city'         => '香港'      // *市
    );

    public function __construct() {
        $this->load_data_safely();

        // 1. 前端結帳與樣式
        add_filter( 'woocommerce_checkout_fields', array( $this, 'add_3level_fields' ), 1000 );
        add_action( 'woocommerce_checkout_process', array( $this, 'conditional_validation' ) );
        add_action( 'wp_footer', array( $this, 'output_footer_script' ) );
        add_action( 'wp_head', array( $this, 'add_frontend_styles' ) );
        
        // 2. 訂單處理 & 運費邏輯
        add_action( 'woocommerce_checkout_create_order', array( $this, 'override_shipping_address' ), 10, 2 );
        add_filter( 'woocommerce_package_rates', array( $this, 'smart_shipping_logic' ), 20, 2 );

        // 3. 後台單號顯示與儲存
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'show_admin_pickup_code_and_tracking' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_tracking_number' ) );

        // 4. 匯出 Excel (官方格式) & 撿貨單
        add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_actions' ), 10, 3 );

        // 5. 列表欄位 (WhatsApp & 收款確認)
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_custom_columns_header' ), 20 );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_custom_columns_content' ), 10, 2 );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_custom_columns_header' ), 20 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'add_custom_columns_content_hpos' ), 10, 2 );
        
        add_action( 'admin_head', array( $this, 'add_admin_styles' ) );
        add_action( 'admin_footer', array( $this, 'add_js_copy_logic' ) );

        // 6. 批量導入選單 & 採購清單選單
        add_action( 'admin_menu', array( $this, 'add_bulk_import_menu' ) );
        add_action( 'admin_menu', array( $this, 'add_restock_menu' ) );

        // 7. Email 中加入順豐單號
        add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_tracking_to_email' ), 10, 3 );

        // 8. AJAX 收款處理 & 到貨分配檢查
        add_action( 'wp_ajax_sf_mark_paid', array( $this, 'ajax_mark_paid_handler' ) );
        add_action( 'wp_ajax_sf_check_allocation', array( $this, 'ajax_check_allocation_handler' ) );

        // 9. [V18.0] 老闆戰情看板
        add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );

        // 10. [V18.0] 顧客前台自助查單
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'show_tracking_in_my_account' ) );
    }

    // --- [V18.0] 顧客前台自助查單 ---
    public function show_tracking_in_my_account( $order ) {
        $tracking = $order->get_meta( '_sf_tracking_number' );
        if ( $tracking ) {
            echo '<div style="margin: 20px 0; padding: 15px; background: #f7f7f7; border-left: 5px solid #000;">';
            echo '<h3 style="margin-top:0;">📦 物流追蹤</h3>';
            echo '<p><strong>順豐運單號：</strong> ' . esc_html( $tracking ) . '</p>';
            echo '<p><a href="https://htm.sf-express.com/hk/tc/dynamic_function/waybill/#search/bill-number/' . esc_attr( $tracking ) . '" target="_blank" class="button">🔍 點擊追蹤貨件</a></p>';
            echo '</div>';
        }
    }

    // --- [V18.0] 老闆戰情看板 ---
    public function add_dashboard_widgets() {
        wp_add_dashboard_widget( 'sf_boss_dashboard', '📈 網店營收戰情室 (今日概況)', array( $this, 'render_boss_dashboard' ) );
    }

    public function render_boss_dashboard() {
        // 計算今日營收
        $today = date('Y-m-d');
        $args = array(
            'date_created' => $today,
            'status' => array('processing', 'completed', 'on-hold'),
            'limit' => -1,
            'type' => 'shop_order'
        );
        $orders = wc_get_orders($args);
        $revenue = 0;
        $profit = 0;
        $count = count($orders);

        foreach($orders as $order) {
            $order_total = $order->get_total();
            $order_cost = 0;
            foreach($order->get_items() as $item) {
                $cost = $item->get_meta( '_recorded_cost' );
                if ( '' === $cost ) { 
                    $pid = $item->get_product_id();
                    $cost = get_post_meta( $pid, '_product_cost', true );
                }
                if ( is_numeric( $cost ) ) $order_cost += ( floatval( $cost ) * $item->get_quantity() );
            }
            $revenue += $order_total;
            $profit += ($order_total - $order_cost);
        }

        echo '<div style="display:flex; gap:10px; text-align:center;">';
        echo '<div style="flex:1; background:#e3f2fd; padding:10px; border-radius:5px;"><h3>💰 今日營收</h3><p style="font-size:20px; font-weight:bold;">$' . number_format($revenue, 2) . '</p></div>';
        echo '<div style="flex:1; background:#e8f5e9; padding:10px; border-radius:5px;"><h3>📈 今日毛利</h3><p style="font-size:20px; font-weight:bold; color:green;">$' . number_format($profit, 2) . '</p></div>';
        echo '<div style="flex:1; background:#fff3e0; padding:10px; border-radius:5px;"><h3>📦 今日訂單</h3><p style="font-size:20px; font-weight:bold;">' . $count . ' 單</p></div>';
        echo '</div>';
        echo '<p style="text-align:right; margin-top:10px;"><a href="admin.php?page=wc-admin&path=/analytics/overview" class="button">查看詳細報表</a></p>';
    }

    // --- Email 顯示單號邏輯 ---
    public function add_tracking_to_email( $fields, $sent_to_admin, $order ) {
        $tracking = $order->get_meta( '_sf_tracking_number' );
        if ( $tracking ) {
            $fields['sf_tracking'] = array( 'label' => '📦 順豐運單號', 'value' => $tracking );
        }
        return $fields;
    }

    // --- 前端欄位排序 ---
    public function add_3level_fields( $fields ) {
        if ( isset( $fields['billing']['billing_phone'] ) ) {
            $fields['billing']['billing_phone']['priority'] = 21; 
            $fields['billing']['billing_phone']['class'] = array('form-row-wide');
            $fields['billing']['billing_phone']['clear'] = true;
        }
        $addr_fields = array('billing_address_1', 'billing_city', 'billing_state', 'billing_postcode');
        foreach( $addr_fields as $f ) { if ( isset( $fields['billing'][$f] ) ) { $fields['billing'][$f]['required'] = false; } }
        
        $fields['billing']['sf_type_select'] = array(
            'type' => 'select', 'label' => '1. 收貨方式 (自提滿2件免運)',
            'options' => array( 'locker'=>'📦 順豐智能櫃 (SF Locker)', 'station'=>'🏢 順豐站 (SF Station)', 'door'=>'🏠 送貨上門 (不包郵+到付)' ),
            'priority' => 22, 'class' => array('form-row-wide'), 'required' => true, 'default' => 'locker'
        );
        $fields['billing']['sf_district_select'] = array('type' => 'select', 'label' => '2. 選擇分區', 'options' => array('' => '← 請先選擇類型'), 'priority' => 23, 'class' => array('form-row-wide', 'wc-enhanced-select'), 'required' => false );
        $fields['billing']['sf_pickup_point'] = array('type' => 'select', 'label' => '3. 選擇自提點', 'options' => array('' => '← 請先選擇分區'), 'priority' => 24, 'class' => array('form-row-wide', 'wc-enhanced-select'), 'required' => false);
        return $fields;
    }

    public function conditional_validation() {
        $val = isset($_POST['billing_sf_type_select']) ? $_POST['billing_sf_type_select'] : (isset($_POST['sf_type_select']) ? $_POST['sf_type_select'] : '');
        $sf_type = sanitize_text_field( $val );
        if ( $sf_type === 'door' ) {
            if ( empty( $_POST['billing_address_1'] ) ) wc_add_notice( '<strong>街道地址</strong> 是必填欄位。', 'error' );
            if ( empty( $_POST['billing_city'] ) ) wc_add_notice( '<strong>鄉鎮/區域</strong> 是必填欄位。', 'error' );
            if ( empty( $_POST['billing_state'] ) ) wc_add_notice( '<strong>區域</strong> 是必填欄位。', 'error' );
        } elseif ( in_array( $sf_type, array( 'locker', 'station' ) ) ) {
            $p_val = isset($_POST['billing_sf_pickup_point']) ? $_POST['billing_sf_pickup_point'] : (isset($_POST['sf_pickup_point']) ? $_POST['sf_pickup_point'] : '');
            if ( empty( $p_val ) ) wc_add_notice( '⚠️ 您選擇了自提，請務必選擇「分區」與「自提點」！', 'error' );
        }
    }

    public function add_frontend_styles() {
        if ( ! is_checkout() && ! is_cart() ) return;
        ?>
        <style>
            .woocommerce-checkout .select2-container { width: 100% !important; display: block !important; }
            #billing_sf_district_select_field, #billing_sf_pickup_point_field { display: block !important; margin-bottom: 15px; }
            #sf_district_select_field .optional, #sf_pickup_point_field .optional, #billing_sf_district_select_field .optional, #billing_sf_pickup_point_field .optional { display: none !important; }
            .woocommerce-checkout #shipping_method li, .woocommerce-cart #shipping_method li { text-indent: 0 !important; padding-left: 0 !important; display: flex !important; align-items: flex-start !important; margin-bottom: 10px !important; }
            .woocommerce-checkout #shipping_method li input[type="radio"], .woocommerce-cart #shipping_method li input[type="radio"] { margin-right: 8px !important; margin-top: 4px !important; width: 16px !important; height: 16px !important; flex-shrink: 0; position: static !important; }
            .woocommerce-checkout #shipping_method li label, .woocommerce-cart #shipping_method li label { margin-bottom: 0 !important; line-height: 1.5 !important; width: 100%; white-space: normal !important; cursor: pointer; }
        </style>
        <?php
    }

    public function output_footer_script() {
        if ( ! is_checkout() ) return;
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($){
            var sfData = <?php echo json_encode( $this->grouped_data ); ?>;
            var $t=$('select[name="sf_type_select"], select[name="billing_sf_type_select"]');
            var $d=$('select[name="sf_district_select"], select[name="billing_sf_district_select"]');
            var $p=$('select[name="sf_pickup_point"], select[name="billing_sf_pickup_point"]');
            var $dRow = $d.closest('.form-row'), $pRow = $p.closest('.form-row');
            var addrRows = ['#billing_address_1_field', '#billing_address_2_field', '#billing_city_field', '#billing_state_field', '#billing_postcode_field'];
            function refreshSF() {
                var val = $t.val();
                if ( !val ) val = 'locker';
                if ( val === 'door' ) { 
                    $dRow.fadeOut(); $pRow.fadeOut(); 
                    $.each(addrRows, function(i, sel){ $(sel).fadeIn(); });
                    $('#billing_address_1_field label, #billing_city_field label, #billing_state_field label').each(function(){
                        if ($(this).find('.required').length === 0) $(this).append('<span class="required" title="必填欄位">*</span>');
                    });
                } else { 
                    $dRow.fadeIn(); $pRow.fadeIn(); 
                    $.each(addrRows, function(i, sel){ $(sel).fadeOut(); });
                    $('#billing_address_1_field label .required, #billing_city_field label .required, #billing_state_field label .required').remove();
                    $('#sf_district_select_field label, #sf_pickup_point_field label, #billing_sf_district_select_field label, #billing_sf_pickup_point_field label').each(function(){
                          if ($(this).find('.required').length === 0) $(this).append('<span class="required" title="必填欄位">*</span>');
                    });
                    if( $d.children('option').length <= 1 && sfData[val] ) {
                        $d.empty().append('<option value="">請選擇分區...</option>');
                        $p.empty().append('<option value="">← 請先選擇分區</option>');
                        Object.keys(sfData[val]).sort().forEach(function(k){ $d.append('<option value="'+k+'">'+k+'</option>'); });
                    }
                }
            }
            refreshSF();
            $(document.body).on('updated_checkout', function(){ refreshSF(); if($.fn.select2) { $d.select2({width: "100%"}); $p.select2({width: "100%"}); } });
            $t.change(function(){ refreshSF(); $('body').trigger('update_checkout'); });
            $d.change(function(){
                var t=$t.val(), d=$(this).val();
                $p.empty().append('<option value="">請選擇自提點...</option>');
                if(t && d && sfData[t][d]) { $.each(sfData[t][d], function(k,v){ $p.append('<option value="'+k+'">'+v+'</option>'); }); }
            });
        });
        </script>
        <?php
    }

    public function smart_shipping_logic( $rates, $package ) {
        $excluded_cat = 'no-free-shipping'; $min_qty = 2; $count = 0;
        if ( WC()->cart ) { foreach ( WC()->cart->get_cart() as $item ) { if ( ! has_term( $excluded_cat, 'product_cat', $item['product_id'] ) ) $count += $item['quantity']; } }
        $is_free = ( $count >= $min_qty );
        $type = 'locker';
        if ( isset( $_POST['post_data'] ) ) { parse_str( $_POST['post_data'], $pd ); if ( isset( $pd['billing_sf_type_select'] ) ) $type = $pd['billing_sf_type_select']; }
        foreach ( $rates as $id => $rate ) {
            if ( strpos( $rate->label, '順豐' ) !== false || strpos( $rate->label, 'SF' ) !== false ) {
                if ( $type === 'door' ) unset( $rates[ $id ] );
                else if ( $is_free ) { $rates[ $id ]->label = '✅ 順豐自提 (滿2件免運)'; $rates[ $id ]->cost = 0; $rates[ $id ]->taxes = array(); }
            }
            if ( ( strpos( $rate->label, '送貨' ) !== false || strpos( $rate->label, 'Door' ) !== false ) && $type !== 'door' && is_checkout() ) unset( $rates[ $id ] );
            if ( 'free_shipping' === $rate->method_id ) unset( $rates[ $id ] );
        }
        return $rates;
    }

    public function override_shipping_address( $order, $data ) {
        if (!empty($_POST['sf_pickup_point'])) {
            $code = sanitize_text_field($_POST['sf_pickup_point']);
            $addr = $this->find_address_by_code($code);
            if($addr) { $order->set_shipping_address_1( '順豐碼：' .$code); $order->set_shipping_address_2($addr); $order->update_meta_data('_sf_pickup_code', $code); }
        }
    }

    public function add_bulk_import_menu() {
        add_submenu_page( 'woocommerce', '順豐運單導入', '📦 順豐運單導入', 'manage_woocommerce', 'sf-bulk-import', array( $this, 'render_import_page' ) );
    }

    // 缺貨採購清單頁面
    public function add_restock_menu() {
        add_submenu_page( 'woocommerce', '缺貨採購清單', '📋 缺貨採購清單', 'manage_woocommerce', 'sf-restock-list', array( $this, 'render_restock_page' ) );
    }

    public function render_restock_page() {
        $args = array(
            'limit' => -1,
            'status' => array('processing', 'on-hold'),
            'type' => 'shop_order'
        );
        $orders = wc_get_orders($args);
        $shortage_map = array();

        foreach($orders as $order) {
            foreach($order->get_items() as $item) {
                $product = $item->get_product();
                if($product && $product->managing_stock()) {
                    $stock = $product->get_stock_quantity();
                    if($stock < 0) { // 負庫存代表超賣/預訂
                        $pid = $product->get_id();
                        if(!isset($shortage_map[$pid])) {
                            $shortage_map[$pid] = array(
                                'name' => $product->get_name(),
                                'sku' => $product->get_sku(),
                                'stock' => $stock,
                                'needed' => 0
                            );
                        }
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>📋 缺貨採購清單 (Restock List)</h1>
            <p>以下列表顯示所有在「處理中」或「保留」訂單中，庫存為負數（已超賣/預訂）的商品。</p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>商品名稱 / 規格</th>
                        <th>SKU</th>
                        <th>當前庫存</th>
                        <th>建議採購量 (欠客數)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($shortage_map)): ?>
                        <tr><td colspan="4">✅ 目前沒有任何缺貨商品。</td></tr>
                    <?php else: ?>
                        <?php foreach($shortage_map as $pid => $data): ?>
                        <tr>
                            <td><?php echo $data['name']; ?></td>
                            <td><?php echo $data['sku']; ?></td>
                            <td style="color:red; font-weight:bold;"><?php echo $data['stock']; ?></td>
                            <td style="font-weight:bold; font-size:1.2em;"><?php echo abs($data['stock']); ?> 件</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <button onclick="window.print()" class="button button-primary" style="margin-top:20px;">🖨️ 列印採購單</button>
        </div>
        <?php
    }

    public function render_import_page() {
        if ( isset( $_POST['sf_bulk_data'] ) && check_admin_referer( 'sf_import_action', 'sf_import_nonce' ) ) {
            $lines = explode( "\n", $_POST['sf_bulk_data'] );
            $success = 0; $fail = 0;
            $auto_complete = true; 

            foreach ( $lines as $line ) {
                $line = trim( $line ); if ( empty( $line ) ) continue;
                $parts = preg_split( '/[\s,]+/', $line );
                if ( count( $parts ) >= 2 ) {
                    $order_id = str_replace('#', '', trim($parts[0])); 
                    $tracking = trim($parts[1]); 
                    $order = wc_get_order( $order_id );
                    
                    if ( $order ) { 
                        $order->update_meta_data( '_sf_tracking_number', $tracking ); 
                        if ( $auto_complete && $order->get_status() !== 'completed' ) {
                            $order->update_status( 'completed', '📦 系統已批量導入順豐單號：' . $tracking );
                        } else {
                            $order->save(); 
                        }
                        $success++; 
                    } else { 
                        $fail++; 
                    }
                }
            }
            echo '<div class="notice notice-success is-dismissible"><p>✅ 處理完成！成功更新 <strong>' . $success . '</strong> 筆訂單（已自動轉為完成狀態並發信），失敗 ' . $fail . ' 筆。</p></div>';
        }
        ?> 
        <div class="wrap">
            <h1>📦 順豐運單號批量導入 & 自動發貨</h1>
            <p>請將 Excel 中的 <b>「訂單編號」</b> 和 <b>「順豐運單號」</b> 兩列複製，直接貼在下方。</p>
            <div class="notice notice-info inline"><p>💡 提示：導入成功的訂單將會自動變成 <strong>「已完成 (Completed)」</strong> 狀態，客人會收到包含單號的 Email 通知。</p></div>
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top:15px;">
                <form method="post">
                    <?php wp_nonce_field( 'sf_import_action', 'sf_import_nonce' ); ?>
                    <textarea name="sf_bulk_data" rows="15" style="width:100%; font-family:monospace;" placeholder="範例：&#10;1001 SF123456&#10;1002 SF654321"></textarea>
                    <p class="submit"><input type="submit" class="button button-primary button-large" value="🚀 開始批量更新並發貨"></p>
                </form>
            </div>
        </div> 
        <?php
    }

    public function show_admin_pickup_code_and_tracking( $order ) { 
        $code = $order->get_meta( '_sf_pickup_code' ); 
        if($code) echo '<p><strong>順豐碼:</strong> ' .$code.'</p>';
        $tracking = $order->get_meta( '_sf_tracking_number' ); 
        echo '<p><label><strong>順豐運單號碼:</strong></label><input type="text" name="sf_tracking_input" value="' .$tracking. '" style="width:100%" placeholder="輸入單號後點擊右側更新訂單"></p>'; 
    }
    public function save_tracking_number( $order_id ) { 
        if ( isset( $_POST['sf_tracking_input'] ) ) { 
            $order = wc_get_order( $order_id ); 
            if($order) { 
                $order->update_meta_data( '_sf_tracking_number', sanitize_text_field($_POST['sf_tracking_input']) ); 
                $order->save(); 
            } 
        } 
    }

    public function register_bulk_actions( $bulk_actions ) { 
        $bulk_actions['export_sf_official'] = '📥 匯出順豐格式 (官方)';
        $bulk_actions['print_sf_packing_slip'] = '🖨️ 列印撿貨單 (Packing Slip)';
        return $bulk_actions; 
    }
    
    // 處理匯出 & 處理撿貨單列印
    public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
        if ( $action === 'export_sf_official' ) {
            $this->export_sf_xlsx($post_ids);
        } elseif ( $action === 'print_sf_packing_slip' ) {
            $this->print_packing_slip($post_ids);
        }
        return $redirect_to;
    }

// 修改後的匯出邏輯：加入訂單編號到「商家訂單號」與「備註」
    private function export_sf_xlsx( $post_ids ) {
        if (ob_get_length()) ob_clean();
        $filename = 'SF_Import_' . date('Ymd_Hi') . '.xlsx';
        $rows = array();
        
        // 標題列
        $rows[] = array('<center>Excel下單導入資訊範本</center>');
        
        $note = " 【特別說明】\n 1. 手機號碼和固定號碼必須填寫一個。\n 2. 托寄物名稱必填。\n 3. 順豐系統會忽略前三行，從第四行開始讀取數據。\n 4. 商家訂單號已自動填入。";
        $rows[] = array($note);
        
        // 第三行：欄位分類 (調整欄位總數為 29)
        $row3 = array_fill(0, 29, '');
        $row3[0] = '<center>收件人及物品信息填寫</center>';
        $row3[18] = '<center>申報物品信息</center>';
        $rows[] = $row3;
        
        // 第四行：表頭 (新增最後兩欄：商家訂單號、備註)
        $headers = array('序號', '*姓名', '*區號', '手機號碼', '座機號碼', '公司名稱', '*省', '*市', '*區', '*詳細地址','*付款方式', '月結卡號', '*托寄物名稱', '自提點編號', '代收卡號', '代收金額', '收件統編', '件數', '重量', '體積', '申報物品1(中文)', '申報物品1(英文)', '數量', '單價', '單位', '幣別', '原產地', '商家訂單號', '備註');
        $rows[] = $headers;
        
        $index = 1;
        foreach ( $post_ids as $post_id ) {
            $order = wc_get_order( $post_id );
            if (!$order) continue;
            
            $sf_code = $order->get_meta( '_sf_pickup_code' );
            $loc = $this->get_smart_location_info($sf_code);
            $addr = $order->get_shipping_address_2() ?: $order->get_shipping_address_1();
            $full_name = $order->get_shipping_last_name() . $order->get_shipping_first_name();
            $phone = $order->get_shipping_phone() ?: $order->get_billing_phone();
            $phone = preg_replace('/[^0-9]/', '', $phone); 
            
            // 獲取訂單編號
            $order_no = $order->get_order_number();

            // 填入資料 (最後加入 $order_no 兩次，分別對應 商家訂單號 和 備註)
            $rows[] = array( 
                $index, 
                $full_name, 
                $this->config['area_code'], 
                $phone, 
                '', 
                '', 
                $this->config['province'], 
                $loc['city'], 
                $loc['area'], 
                $addr, 
                $this->config['payment'], 
                $this->config['monthly_card'], 
                $this->config['content'], 
                $sf_code, 
                '', 
                '', 
                '', 
                '1', 
                '', 
                '', 
                '', 
                '', 
                '', 
                '', 
                '', 
                '', 
                '', 
                $order_no, // 商家訂單號
                $order_no  // 備註 (雙重保險)
            );
            $index++;
        }
        
        $xlsx = SimpleXLSXGen::fromArray( $rows );
        
        // 調整合併儲存格範圍，因為現在有 29 欄 (A-AC)
        // A1-AC1, A2-AC2, A3-R3(原本的), S3-AC3(原本的後半段)
        $xlsx->mergeCells('A1:AC1'); 
        $xlsx->mergeCells('A2:AC2'); 
        $xlsx->mergeCells('A3:R3'); 
        $xlsx->mergeCells('S3:AC3');
        
        $xlsx->downloadAs($filename);
        exit;
    }

    // 列印撿貨單邏輯
    private function print_packing_slip( $post_ids ) {
        if (ob_get_length()) ob_clean();
        ?>
        <!DOCTYPE html>
        <html><head><title>撿貨單 (Picking List)</title><style>
            body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.4; color: #333; padding: 20px; }
            .order-block { page-break-after: always; border: 2px solid #000; padding: 20px; margin-bottom: 20px; }
            .header { display: flex; justify-content: space-between; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
            .big-id { font-size: 24px; font-weight: bold; }
            .meta { font-size: 14px; }
            .sf-loc { background: #eee; padding: 10px; font-weight: bold; margin-bottom: 15px; font-size: 16px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; border-bottom: 1px solid #333; padding: 5px; }
            td { border-bottom: 1px solid #eee; padding: 8px 5px; vertical-align: middle; }
            .img-thumb { width: 50px; height: 50px; object-fit: cover; border: 1px solid #ddd; }
            .var-data { color: #555; font-weight: bold; display: block; margin-top: 3px; }
            .total-row { text-align: right; font-size: 16px; font-weight: bold; margin-top: 15px; }
            .note { background: #fff8e1; border: 1px solid #ffe082; padding: 10px; margin-top: 10px; font-size: 13px; }
            @media print { .no-print { display: none; } }
        </style></head><body>
        <div class="no-print" style="margin-bottom: 20px; padding: 10px; background: #e0f7fa; text-align: center;">
            <button onclick="window.print()" style="font-size: 20px; padding: 10px 30px; cursor: pointer;">🖨️ 列印此頁面</button>
        </div>
        <?php foreach($post_ids as $pid): $order = wc_get_order($pid); if(!$order) continue; 
            $sf_code = $order->get_meta('_sf_pickup_code');
            $addr = $order->get_shipping_address_2() ?: $order->get_shipping_address_1();
            $loc_str = $sf_code ? "[$sf_code] $addr" : $addr;
            $items = $order->get_items();
            $total_count = 0;
        ?>
        <div class="order-block">
            <div class="header">
                <div><div class="big-id">#<?php echo $order->get_order_number(); ?></div><div><?php echo $order->get_formatted_billing_full_name(); ?></div></div>
                <div class="meta" style="text-align:right;"><div><?php echo $order->get_date_created()->date('Y-m-d H:i'); ?></div><div><?php echo $order->get_payment_method_title(); ?></div></div>
            </div>
            <div class="sf-loc">📍 <?php echo $loc_str; ?></div>
            <table>
                <thead><tr><th>圖片</th><th>商品名稱 / 規格</th><th width="50">數量</th></tr></thead>
                <tbody>
                <?php foreach($items as $item): 
                    $prod = $item->get_product(); 
                    $qty = $item->get_quantity(); 
                    $total_count += $qty;
                    $img = $prod ? $prod->get_image('thumbnail', array('class'=>'img-thumb')) : '';
                    $meta = $prod ? wc_get_formatted_variation( $prod, true ) : '';
                ?>
                <tr>
                    <td width="60"><?php echo $img; ?></td>
                    <td>
                        <div style="font-size:15px;"><?php echo $item->get_name(); ?></div>
                        <?php if($meta): ?><span class="var-data"><?php echo $meta; ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:18px; font-weight:bold; text-align:center;"><?php echo $qty; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="total-row">總件數：<?php echo $total_count; ?> 件</div>
            <?php if($order->get_customer_note()): ?><div class="note">📝 客戶備註：<?php echo $order->get_customer_note(); ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        </body></html>
        <?php
        exit;
    }

    public function add_admin_styles() { 
        echo '<style> 
        .wa-col-wrapper { display: flex; flex-direction: column; gap: 6px; } 
        .wa-row { display: flex; align-items: center; justify-content: space-between; gap: 5px; background: #fff; padding: 5px 8px; border: 1px solid #ddd; border-radius: 4px; } 
        .wa-label { font-size: 12px; font-weight: bold; color: #555; width: 60px; } 
        .wa-btn-copy { background: #f6f7f7; border: 1px solid #ccc; cursor: pointer; padding: 4px 8px; font-size: 11px; flex-grow: 1; border-radius: 3px; } 
        .wa-btn-copy:hover { background: #f0f0f1; border-color: #0073aa; color: #0073aa; } 
        .wa-btn-chat { background-color: #25D366; color: #fff !important; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; } 
        .wa-btn-chat:hover { background-color: #128C7E; } 
        .copy-success { background: #46b450 !important; color: #fff !important; border-color: #46b450 !important; } 
        .column-sf_whatsapp { width: 260px !important; } 
        /* V16.0 樣式 */
        .column-sf_paid_status { width: 80px !important; text-align: center; }
        .sf-pay-btn { cursor: pointer; font-size: 24px; line-height: 1; filter: grayscale(100%); opacity: 0.4; transition: all 0.2s; border: none; background: transparent; }
        .sf-pay-btn:hover { filter: none; opacity: 1; transform: scale(1.2); }
        .sf-pay-done { color: #46b450; font-size: 24px; line-height: 1; cursor: default; }
        /* V17.0 分配助手樣式 */
        .sf-alloc-match { background-color: #d4edda !important; transition: background 0.5s; }
        </style>'; 
    }

    public function add_custom_columns_header( $columns ) { 
        $new_cols = array();
        foreach($columns as $key => $col) {
            $new_cols[$key] = $col;
            if($key === 'order_status') { // 插入到狀態欄後
                $new_cols['sf_paid_status'] = '💰 已收款';
            }
        }
        $new_cols['sf_whatsapp'] = 'WhatsApp 客服';
        return $new_cols;
    }
    public function add_custom_columns_content( $column, $post_id ) { 
        if ( 'sf_whatsapp' === $column ) $this->render_whatsapp_buttons( wc_get_order($post_id) ); 
        if ( 'sf_paid_status' === $column ) $this->render_paid_button( wc_get_order($post_id) );
    }
    public function add_custom_columns_content_hpos( $column, $order ) { 
        if ( 'sf_whatsapp' === $column ) $this->render_whatsapp_buttons( $order ); 
        if ( 'sf_paid_status' === $column ) $this->render_paid_button( $order );
    }

    // 渲染收款按鈕
    private function render_paid_button($order) {
        if(!$order) return;
        $status = $order->get_status();
        // 如果已經是處理中或完成，視為已收款 (綠勾)
        if(in_array($status, array('processing', 'completed'))) {
            echo '<span class="dashicons dashicons-yes sf-pay-done" title="已收款"></span>';
        } else {
            // 否則顯示灰色錢袋，可點擊
            echo '<button type="button" class="sf-pay-btn sf-js-pay" data-oid="'.$order->get_id().'" title="點擊確認收款並檢查庫存">💰</button>';
        }
    }

    // [V18.0] AJAX 處理邏輯 (新增操作日誌)
    public function ajax_mark_paid_handler() {
        check_ajax_referer( 'sf_pay_action', 'nonce' );
        $order_id = absint( $_POST['order_id'] );
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) wp_send_json_error();

        // 記錄操作者
        $user = wp_get_current_user();
        $username = $user->exists() ? $user->display_name : 'System';

        // 檢查庫存邏輯
        $has_shortage = false;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->managing_stock() ) {
                if ( $product->get_stock_quantity() <= 0 ) {
                    $has_shortage = true;
                    break;
                }
            }
        }

        if ( $has_shortage ) {
            $order->update_status( 'on-hold', "💰 [{$username}] 確認收款。但因商品缺貨/預訂，系統自動保留訂單。" );
            wp_send_json_success( array( 'result' => 'hold' ) );
        } else {
            $order->update_status( 'processing', "💰 [{$username}] 確認收款。庫存充足，自動轉為處理中。" );
            wp_send_json_success( array( 'result' => 'processing' ) );
        }
    }

    // 到貨分配檢查邏輯
    public function ajax_check_allocation_handler() {
        check_ajax_referer( 'sf_alloc_action', 'nonce' );
        $orders = wc_get_orders(array('status'=>'on-hold', 'limit'=>-1, 'type'=>'shop_order'));
        $match_ids = array();

        foreach($orders as $order) {
            $all_in_stock = true;
            foreach($order->get_items() as $item) {
                $product = $item->get_product();
                // 只要有一件商品有管理庫存且庫存<=0，就不算齊貨
                if ( $product && $product->managing_stock() && $product->get_stock_quantity() <= 0 ) {
                    $all_in_stock = false;
                    break;
                }
            }
            if($all_in_stock) $match_ids[] = $order->get_id();
        }
        wp_send_json_success($match_ids);
    }

    private function render_whatsapp_buttons( $order ) {
        if ( ! $order ) return;
        $phone = $order->get_shipping_phone() ?: $order->get_billing_phone();
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        if ( strlen( $phone ) == 8 ) $phone = '852' . $phone;
        if ( empty( $phone ) ) { echo '-'; return; }
        $name = str_replace(array('"', "'"), '', $order->get_shipping_first_name() ?: '客戶');
        $oid = $order->get_order_number();
        $sf_code = $order->get_meta( '_sf_pickup_code' );
        $addr = $order->get_shipping_address_2();
        $loc = $sf_code ? $addr . " (" . $sf_code . ")" : "上門派送";
        $loc = str_replace(array("\r", "\n"), ' ', $loc);
        $track = $order->get_meta( '_sf_tracking_number' ) ?: '[未有單號]';
        $url = 'https://wa.me/' . $phone;
        
        // 獲取第一件商品名稱，用於預訂通知
        $items = $order->get_items();
        $first_item = reset($items);
        $p_name = $first_item ? $first_item->get_name() : '商品';

        echo '<div class="wa-col-wrapper">';
        $this->print_row('收到訂單', 'pending', $name, $oid, $track, $loc, $url);
        $this->print_row('收到款項', 'processing', $name, $oid, $track, $loc, $url);
        // 預訂通知按鈕
        $this->print_row('預訂/缺貨', 'preorder', $name, $oid, $track, $p_name, $url); 
        $this->print_row('已寄出', 'shipped', $name, $oid, $track, $loc, $url);
        $this->print_row('已送達', 'arrived', $name, $oid, $track, $loc, $url);
        echo '</div>';
    }
    
    private function print_row($label, $type, $name, $oid, $track, $loc, $url) { 
        echo '<div class="wa-row">'; 
        echo '<span class="wa-label">'.$label.'</span>'; 
        // 傳遞參數：loc 在 preorder 模式下會被當作商品名稱使用
        echo '<button type="button" class="wa-btn-copy sf-js-copy" data-type="'.$type.'" data-name="'.esc_attr($name).'" data-oid="'.esc_attr($oid).'" data-track="'.esc_attr($track).'" data-loc="'.esc_attr($loc).'">📋 複製</button>'; 
        echo '<a href="'.$url.'" target="_blank" class="wa-btn-chat"><span class="dashicons dashicons-whatsapp"></span></a>'; 
        echo '</div>'; 
    }
    
    public function add_js_copy_logic() { ?> 
        <script> 
        jQuery(document).ready(function($){ 
            // 順豐寄件按鈕
            var sfBtn = '<a href="https://htm.sf-express.com/hk/tc/dynamic_function/order/quick/#batchSend" target="_blank" class="button" style="margin-left:5px;">順豐寄件</a>';
            var $btn = $('#doaction'); if($btn.length) $btn.after(sfBtn); else $('.bulkactions').first().append(sfBtn);

            // 到貨分配助手按鈕 - 只在保留頁面或全部頁面顯示
            var allocBtn = '<button type="button" class="button sf-alloc-btn" style="margin-left:5px;">🔍 檢查可發貨訂單</button>';
            $('.bulkactions').first().append(allocBtn);

            // WhatsApp 複製邏輯
            $(document).on('click', '.sf-js-copy', function(e){ e.preventDefault(); var d = $(this).data(), msg = ""; 
                if (d.type === 'pending') msg = `Hello ${d.name} 👋🏻，收到你嘅訂單 #${d.oid} 啦！多謝幫襯 💖\n麻煩付款後 WhatsApp 張入數紙/截圖俾我哋核對返～\n確認後會盡快幫你安排出貨/訂貨架啦，Thank you! ✨`; 
                else if (d.type === 'processing') msg = `Hello ${d.name}，收到款項啦 💰！\n訂單 #${d.oid} 已經幫你跟進緊/訂貨中 📦，請耐心等候一下，出貨會再通知你㗎，感謝！🙏🏻`; 
                // 新增預訂話術
                else if (d.type === 'preorder') msg = `Hello ${d.name} 👋🏻，收到款項啦！\n確認訂單內容：${d.loc} (訂單 #${d.oid})。\n由於此商品目前是 **預訂款/缺貨**，我們已為您向廠商下單 📝。\n預計到貨時間約 7-14 天，一齊貨會立刻安排順豐寄出並通知您，感謝耐心等待！💖`;
                else if (d.type === 'shipped') msg = `Hello ${d.name}，好消息！訂單 #${d.oid} 剛剛寄出咗啦 🚚💨\n\n📦 順豐單號： ${d.track}\n📍 取件地點： ${d.loc}\n\n順豐哥哥送到會再 SMS 通知你取件/派送，留意電話就得㗎啦！多謝支持 🥰`; 
                else if (d.type === 'arrived') msg = `Hello ${d.name} 👋🏻，系統顯示訂單 #${d.oid} 已經送到去 ${d.loc} 啦！🎉\n記得睇吓收唔收到順豐 SMS 取件碼，有時間就可以去攞件啦～\n收到貨確認無誤，歡迎再嚟幫襯啊！💖`; 
                var temp = $("<textarea>"); $("body").append(temp); temp.val(msg).select(); document.execCommand("copy"); temp.remove(); var t = $(this).text(); $(this).text('✅').addClass('copy-success'); var btn = $(this); setTimeout(function(){ btn.text(t).removeClass('copy-success'); }, 1000); 
            }); 
            
            // 收款確認邏輯
            $(document).on('click', '.sf-js-pay', function(e){
                e.preventDefault();
                var btn = $(this);
                if(btn.hasClass('working')) return;
                btn.addClass('working').css('opacity', '0.5');
                $.post(ajaxurl, {
                    action: 'sf_mark_paid',
                    order_id: btn.data('oid'),
                    nonce: '<?php echo wp_create_nonce("sf_pay_action"); ?>'
                }, function(res){
                    if(res.success) {
                        btn.replaceWith('<span class="dashicons dashicons-yes sf-pay-done" title="已收款 (狀態更新完成)"></span>');
                        // location.reload(); 
                    } else {
                        alert('錯誤，請重試。');
                        btn.removeClass('working').css('opacity', '0.4');
                    }
                });
            });

            // 到貨分配檢查邏輯
            $(document).on('click', '.sf-alloc-btn', function(e){
                e.preventDefault();
                var btn = $(this);
                btn.text('檢查中...').prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'sf_check_allocation',
                    nonce: '<?php echo wp_create_nonce("sf_alloc_action"); ?>'
                }, function(res){
                    if(res.success && res.data.length > 0) {
                        var count = 0;
                        $.each(res.data, function(i, oid){
                            var row = $('#post-'+oid);
                            if(row.length) { row.addClass('sf-alloc-match'); count++; }
                        });
                        alert('✅ 找到 ' + res.data.length + ' 張已齊貨的訂單！\n本頁已標記 ' + count + ' 張為綠色背景。');
                        btn.text('🔍 檢查可發貨訂單').prop('disabled', false);
                    } else {
                        alert('⚠️ 目前沒有任何「保留中」的訂單是齊貨的。');
                        btn.text('🔍 檢查可發貨訂單').prop('disabled', false);
                    }
                });
            });
        }); 
        </script> 
    <?php }

    private function get_smart_location_info( $target_code ) { $hk_18 = array('中西區','東區','南區','灣仔區','九龍城區','觀塘區','深水埗區','黃大仙區','油尖旺區','離島區','葵青區','北區','西貢區','沙田區','大埔區','荃灣區','屯門區','元朗區'); $lantau = array('馬灣','愉景灣','東涌','機場','赤鱲角'); foreach ( $this->grouped_data as $type => $group ) { foreach ( $group as $sub => $points ) { if ( isset( $points[ $target_code ] ) ) { $addr = $points[ $target_code ]; $city = ''; foreach($hk_18 as $d) if(mb_strpos($addr, $d)!==false) { $city=$d; break; } if ( empty($city) ) { if ( mb_strpos($addr, '元朗') !== false ) $city = '元朗區'; elseif ( mb_strpos($addr, '大埔') !== false ) $city = '大埔區'; elseif ( mb_strpos($addr, '屯門') !== false ) $city = '屯門區'; elseif ( mb_strpos($addr, '西貢') !== false ) $city = '西貢區'; else $city = $sub; } foreach($lantau as $l) if(mb_strpos($addr, $l)!==false) { $city='大嶼山'; break; } if($city==='離島區') $city='大嶼山'; return array('city'=>$city, 'area'=>$sub); } } } return array('city'=>'香港', 'area'=>'香港'); }
    private function load_data_safely() { $s = $this->fetch_remote_data('station','sf-stores.json'); if($s) foreach($s as $i) $this->process_item('station',$i); $l = $this->fetch_remote_data('locker','sf-lockers.json'); if($l) foreach($l as $i) $this->process_item('locker',$i); }
    private function fetch_remote_data($t, $f) { $trans = 'sf_v132_'.$t; $data = get_transient($trans); if($data) return $data; $res = wp_remote_get($this->github_urls[$t], array('timeout'=>10)); if(!is_wp_error($res) && wp_remote_retrieve_response_code($res)==200) { $data = json_decode(wp_remote_retrieve_body($res), true); set_transient($trans, $data, 12*HOUR_IN_SECONDS); return $data; } $lp = plugin_dir_path(__FILE__).$f; return file_exists($lp) ? json_decode(file_get_contents($lp),true) : false; }
    private function process_item($t, $i) { $c = $i['code']??''; $a = $i['address']??''; $d = $i['district']??'其他'; if(!$c) return; $this->grouped_data[$t][$d][$c] = "$c - ".trim(str_replace($c,'',$a)); }
    private function find_address_by_code($c) { foreach($this->grouped_data as $t=>$gs) foreach($gs as $d=>$ps) if(isset($ps[$c])) { $p = explode(' - ',$ps[$c]); return $p[1]??$ps[$c]; } return false; }
}

// 啟動主程式
new SF_Pickup_Full_Suite();

// =========================================================================
//  PART 2: 成本與利潤報表模組 (Excel 匯出版 - HPOS 兼容版)
// =========================================================================

if ( ! class_exists( 'SF_Profit_Export_Manager' ) ) {

    class SF_Profit_Export_Manager {

        public function __construct() {
            // 1. 後台選單
            add_action( 'admin_menu', array( $this, 'add_export_menu' ) );
            add_action( 'admin_init', array( $this, 'process_profit_export' ) );

            // 2. 商品成本欄位
            add_action( 'woocommerce_product_options_pricing', array( $this, 'add_cost_field_simple' ) );
            add_action( 'woocommerce_process_product_meta', array( $this, 'save_cost_field_simple' ) );
            add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_cost_field_variation' ), 10, 3 );
            add_action( 'woocommerce_save_product_variation', array( $this, 'save_cost_field_variation' ), 10, 2 );

            // 3. 訂單成本記錄
            add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'record_order_cost' ), 10, 4 );
        }

        // A. 報表下載頁面
        public function add_export_menu() {
            add_submenu_page( 'woocommerce', '下載利潤報表', '💰 下載利潤報表', 'manage_woocommerce', 'sf-profit-export', array( $this, 'render_profit_export_page' ) );
        }

        public function render_profit_export_page() {
            ?>
            <div class="wrap">
                <h1>💰 下載銷售與利潤報表 (Excel / HPOS兼容版)</h1>
                <p>請選擇您要計算的日期範圍。系統將會下載 Excel 檔案，包含詳細的成本與毛利分析。</p>
                <div style="background:#fff; padding:30px; border:1px solid #ccc; max-width: 600px; margin-top:20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <form method="post" action="">
                        <?php wp_nonce_field( 'sf_profit_export_action', 'sf_profit_export_nonce' ); ?>
                        <input type="hidden" name="action" value="download_profit_report">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="start_date">開始日期</label></th>
                                <td><input type="date" name="start_date" id="start_date" required value="<?php echo date('Y-m-01'); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="end_date">結束日期</label></th>
                                <td><input type="date" name="end_date" id="end_date" required value="<?php echo date('Y-m-d'); ?>" class="regular-text"></td>
                            </tr>
                        </table>
                        <p class="submit"><button type="submit" class="button button-primary button-hero">📥 立即下載 Excel 報表</button></p>
                    </form>
                </div>
            </div>
            <?php
        }

        // 支援 HPOS 的下載邏輯
        public function process_profit_export() {
            if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'download_profit_report' ) return;
            if ( ! isset( $_POST['sf_profit_export_nonce'] ) || ! wp_verify_nonce( $_POST['sf_profit_export_nonce'], 'sf_profit_export_action' ) ) return;
            if ( ! current_user_can( 'manage_woocommerce' ) ) return;

            $start_date = sanitize_text_field( $_POST['start_date'] );
            $end_date = sanitize_text_field( $_POST['end_date'] );
            $filename = 'Profit_Report_' . $start_date . '_to_' . $end_date . '.csv';

            header('Content-Type: text/csv; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            header("Pragma: no-cache");
            header("Expires: 0");

            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");

            fputcsv($output, array('訂單號', '日期', '狀態', '客戶姓名', '電話', '運送方式', '付款方式', '商品摘要', '總數量', '營收 (Revenue)', '成本 (Cost)', '毛利 (Profit)'));

            $args = array(
                'limit' => -1,
                'return' => 'ids', 
                'status' => array( 'processing', 'completed' ),
                'date_created' => $start_date . '...' . $end_date, 
                'type' => 'shop_order'
            );
            
            $order_ids = wc_get_orders( $args );

            foreach ( $order_ids as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) continue;

                $items_summary = array();
                $total_qty = 0; $total_cost = 0;

                foreach ( $order->get_items() as $item ) {
                    $qty = $item->get_quantity();
                    $total_qty += $qty;
                    $items_summary[] = $item->get_name() . " x" . $qty;

                    $cost = $item->get_meta( '_recorded_cost' );
                    if ( '' === $cost ) { 
                        $pid = $item->get_product_id();
                        $vid = $item->get_variation_id();
                        $target_id = $vid ? $vid : $pid;
                        if ( $target_id ) $cost = get_post_meta( $target_id, '_product_cost', true );
                    }
                    if ( is_numeric( $cost ) ) $total_cost += ( floatval( $cost ) * $qty );
                }

                $revenue = $order->get_total();
                $profit = floatval( $revenue ) - $total_cost;
                $sf_code = $order->get_meta( '_sf_pickup_code' );
                $shipping = $sf_code ? "順豐自提 ($sf_code)" : "送貨上門";
                $phone = $order->get_shipping_phone() ?: $order->get_billing_phone();

                fputcsv($output, array(
                    $order->get_order_number(), $order->get_date_created()->date('Y-m-d'), wc_get_order_status_name( $order->get_status() ),
                    $order->get_formatted_billing_full_name(), $phone, $shipping, $order->get_payment_method_title(),
                    implode( " | ", $items_summary ), $total_qty, $revenue, $total_cost, $profit
                ));

                $order = null; 
                wp_cache_flush(); 
            }
            fclose($output);
            exit;
        }

        // B. 商品成本欄位
        public function add_cost_field_simple() { woocommerce_wp_text_input( array( 'id' => '_product_cost', 'label' => __( '進貨成本 ($)', 'woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'step' => 'any', 'min' => '0' ) )); }
        public function save_cost_field_simple( $post_id ) { if ( isset( $_POST['_product_cost'] ) ) update_post_meta( $post_id, '_product_cost', wc_clean( $_POST['_product_cost'] ) ); }
        
        // [修正] 修復了原本符號錯誤的 add_cost_field_variation
        public function add_cost_field_variation( $loop, $variation_data, $variation ) { woocommerce_wp_text_input( array( 'id' => 'variable_product_cost[' . $loop . ']', 'label' => __( '進貨成本 ($)', 'woocommerce' ), 'value' => get_post_meta( $variation->ID, '_product_cost', true ), 'type' => 'number', 'custom_attributes' => array( 'step' => 'any', 'min' => '0' ), 'wrapper_class' => 'form-row form-row-full', ) ); }
        public function save_cost_field_variation( $variation_id, $i ) { if ( isset( $_POST['variable_product_cost'][$i] ) ) update_post_meta( $variation_id, '_product_cost', wc_clean( $_POST['variable_product_cost'][$i] ) ); }
        
        public function record_order_cost( $item, $cart_item_key, $values, $order ) {
            if ( isset( $values['product_id'] ) ) {
                $pid = $values['product_id']; $vid = isset($values['variation_id']) ? $values['variation_id'] : 0;
                $cost = '';
                if ( !empty( $vid ) ) $cost = get_post_meta( $vid, '_product_cost', true );
                if ( empty( $cost ) ) $cost = get_post_meta( $pid, '_product_cost', true );
                if ( is_numeric($cost) ) $item->add_meta_data( '_recorded_cost', $cost ); 
            }
        }
    }

    // 啟動報表模組
    new SF_Profit_Export_Manager();
}

/**
 * SimpleXLSXGen Class
 * 內建輕量級 XLSX 生成引擎 (無須外部依賴)
 */
class SimpleXLSXGen {
    public $curSheet;
    protected $sheets;
    protected $template;
    protected $F, $F_KEYS;

    public function __construct() {
        $this->curSheet = -1;
        $this->sheets = [ ['name' => 'Sheet1', 'rows' => [], 'hyperlinks' => [], 'mergecells' => []] ];
        $this->template = [
            'app' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>SF Plugin</Application></Properties>',
            'core' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>SF Plugin</dc:creator><cp:lastModifiedBy>SF Plugin</cp:lastModifiedBy></cp:coreProperties>',
            'rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
            'workbook' => ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>', '</sheets></workbook>'],
            'styles' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts><font><sz val="12"/><name val="Arial"/></font><font><b/><sz val="12"/><name val="Arial"/></font></fonts><fills><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs></styleSheet>'
        ];
    }
    public static function fromArray( array $rows ) {
        $xlsx = new self();
        foreach($rows as $row) $xlsx->addRow($row);
        return $xlsx;
    }
    public function addRow( $row ) {
        if ( $this->curSheet < 0 ) $this->curSheet = 0;
        $this->sheets[ $this->curSheet ]['rows'][] = $row;
    }
    public function mergeCells( $range ) {
        if ( $this->curSheet < 0 ) $this->curSheet = 0;
        $this->sheets[ $this->curSheet ]['mergecells'][] = $range;
    }
    public function downloadAs( $filename ) {
        $temp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->saveAs($temp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: ' . filesize($temp));
        readfile($temp);
        unlink($temp);
    }
    public function saveAs( $filename ) {
        $fh = fopen( $filename, 'wb' );
        if (!$fh) return false;
        $zip = new ZipArchive();
        $zip_file = tempnam(sys_get_temp_dir(), 'zip');
        $zip->open($zip_file, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $this->template['rels']);
        $zip->addFromString('docProps/app.xml', $this->template['app']);
        $zip->addFromString('docProps/core.xml', $this->template['core']);
        $zip->addFromString('xl/styles.xml', $this->template['styles']);
        
        $wb_xml = $this->template['workbook'][0];
        $wb_xml .= '<sheet name="Sheet1" sheetId="1" r:id="rId1"/>';
        $wb_xml .= $this->template['workbook'][1];
        $zip->addFromString('xl/workbook.xml', $wb_xml);

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');

        // Generate Sheet XML
        $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>';
        foreach ($this->sheets[0]['rows'] as $i => $row) {
            $sheet_xml .= '<row r="'.($i+1).'">';
            foreach ($row as $j => $val) {
                $c = $this->num2name($j) . ($i+1);
                $v = htmlspecialchars($val);
                $s = (strpos($val, '<center>') !== false) ? 's="1"' : ''; // Apply align style
                $v = str_replace(['<center>','</center>'], '', $v);
                $sheet_xml .= '<c r="'.$c.'" t="inlineStr" '.$s.'><is><t>'.$v.'</t></is></c>';
            }
            $sheet_xml .= '</row>';
        }
        $sheet_xml .= '</sheetData>';
        if ( !empty($this->sheets[0]['mergecells']) ) {
            $sheet_xml .= '<mergeCells count="'.count($this->sheets[0]['mergecells']).'">';
            foreach($this->sheets[0]['mergecells'] as $range) $sheet_xml .= '<mergeCell ref="'.$range.'"/>';
            $sheet_xml .= '</mergeCells>';
        }
        $sheet_xml .= '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
        $zip->close();
        
        $c = file_get_contents($zip_file);
        fwrite($fh, $c);
        fclose($fh);
        unlink($zip_file);
    }
    protected function num2name($num) {
        $numeric = $num % 26; $letter = chr(65 + $numeric);
        $num2 = intval($num / 26);
        if ($num2 > 0) return $this->num2name($num2 - 1) . $letter;
        return $letter;
    }
}