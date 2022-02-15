<?php
// $parse_uri = explode( 'wp-content', $_SERVER['SCRIPT_FILENAME'] );
// require_once( $parse_uri[0] . 'wp-load.php' );
function silicon_pay_full_load_path_page() {
    require_once ABSPATH . 'wp-load.php';
    die(); // this is required to return a proper result
}
add_action( 'silicon_pay_wp_ajax_load_invoice_page', 'silicon_pay_full_load_path_page' );
add_action( 'silicon_pay_wp_ajax_nopriv_load_invoice_page', 'silicon_pay_full_load_path_page' );




$code = @$_GET['code'];
function spgformat_metadata($data)
{
    $new = json_decode($data);
    $text = '';
    if (array_key_exists("0", $new)) {
        foreach ($new as $key => $item) {
            if ($item->type == 'text') {
                $text .= '<div class="span12 unit">
								<label class="label inline">' . $item->display_name . ':</label>
								<strong>' . $item->value . '</strong>
							</div>';
            } else {
                $text .= '<div class="span12 unit">
								<label class="label inline">' . $item->display_name . ':</label>
								<strong> <a target="_blank" href="' . $item->value . '">link</a></strong>
							</div>';
            }

        }
    } else {
        $text = '';
        if (count($new) > 0) {
            foreach ($new as $key => $item) {
                $text .= '<div class="span12 unit">
								<label class="label inline">' . $key . ':</label>
								<strong>' . $item . '</strong>
							</div>';
            }
        }
    }
    //
    return $text;
}

global $wpdb;
$table = $wpdb->prefix . SPG_WP_SILICONPAY_TABLE;
$record = $wpdb->get_results("SELECT * FROM $table WHERE (txn_code = '" . $code . "')");

if (array_key_exists("0", $record)) {
    get_header();
    $dbdata = $record[0];
    $currency = get_post_meta($dbdata->post_id, '_currency', true);


    ?>
    <div class="content-area main-content" id="primary">
        <main role="main" class="site-main" id="main">
            <div class="blog_post">
                <article class="post-4 page type-page status-publish hentry" id="post-4">
                    <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post"
                          enctype="multipart/form-data" class="j-forms retry-form" id="pf-form" novalidate="">
                        <input type="hidden" name="action" value="spg_wp_siliconpay_retry_action">
                        <input type="hidden" name="code" value="<?php echo esc_attr($code); ?>"/>
                        <div class="content">

                            <div class="divider-text gap-top-20 gap-bottom-45">
                                <span>Payment Invoice</span>
                            </div>

                            <div class="j-row">
                                <div class="span12 unit">
                                    <label class="label inline">Email:</label>
                                    <strong><a href="mailto:<?php echo esc_attr($dbdata->email); ?>"><?php echo esc_attr($dbdata->email); ?></a></strong>
                                </div>
                                <div class="span12 unit">
                                    <label class="label inline">Amount:</label>
                                    <strong><?php echo $currency . number_format(esc_attr($dbdata->amount)); ?></strong>
                                </div>
                                <?php echo spgformat_metadata(esc_attr($dbdata->metadata)); ?>

                                <div class="span12 unit">
                                    <label class="label inline">Date:</label>
                                    <strong><?php echo esc_attr($dbdata->created_at); ?></strong>
                                </div>
                                <?php if ($dbdata->paid == 1) { ?>
                                    <div class="span12 unit">
                                        <label class="label inline">Payment Status:</label>
                                        <strong> Successful</strong>
                                    </div>
                                <?php } ?>


                            </div>
                        </div>

                        <div class="footer">
                            <small><span style="color: red;">*</span> are compulsory</small><br>
                            <img class="siliconpay-cardlogos size-full wp-image-1096" alt="cardlogos"
                                 src="<?php echo plugins_url('../images/logos@2x.png', __FILE__); ?>">
                            <?php if ($dbdata->paid == 0) { ?>
                                <button type="submit" class="primary-btn" id='submitbtn'>Retry Payment</button>
                            <?php } ?>

                        </div>
                    </form>
                </article>
            </div>
        </main>
    </div>
    <?php
    get_footer();
} else {
    die('Invoice code invalid');
}