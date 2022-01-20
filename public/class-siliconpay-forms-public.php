<?php

require_once ABSPATH . "wp-admin" . '/includes/image.php';
require_once ABSPATH . "wp-admin" . '/includes/file.php';
require_once ABSPATH . "wp-admin" . '/includes/media.php';
include_once plugin_dir_path(__FILE__) . 'class-siliconpay-plugin-tracker.php';


class Spg_WP_SiliconPay_Public
{
    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function enqueue_styles()
    {
        wp_enqueue_style($this->plugin_name . '0', 'https://fonts.googleapis.com/css?family=Open+Sans:400,600&display=swap', false );
        wp_enqueue_style($this->plugin_name . '1', plugin_dir_url(__FILE__) . 'css/spg-siliconpay-style.css', array(), $this->version, 'all');
        wp_enqueue_style($this->plugin_name . '2', plugin_dir_url(__FILE__) . 'css/font-awesome.min.css', array(), $this->version, 'all');

    }

    public static function fetchEncryptionKey()
    {
        $mode = esc_attr(get_option('mode'));
        if ($mode == 'test') {
            $key = esc_attr(get_option('lpk'));
        } else {
            $key = esc_attr(get_option('lpk'));
        }
        return $key;
    }



    public static function fetchFeeSettings()
    {
        $ret = [];
        $ret['prc'] = intval(floatval(esc_attr(get_option('prc', 4))) * 100) / 10000;
        $ret['ths'] = intval(floatval(esc_attr(get_option('ths', 0))));
        $ret['adc'] = intval(floatval(esc_attr(get_option('adc', 0))));
        $ret['cap'] = intval(floatval(esc_attr(get_option('cap', 0))));
        return $ret;
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('blockUI', plugin_dir_url(__FILE__) . 'js/jquery.blockUI.min.js', array('jquery'), $this->version, true, true);
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('siliconpay_frontend', plugin_dir_url(__FILE__) . 'js/siliconpay-forms-public.js', array('jquery'), $this->version, true, true);
        wp_localize_script('siliconpay_frontend', 'spg_settings', array('key' => Spg_WP_SiliconPay_Public::fetchEncryptionKey(), 'fee' => Spg_WP_SiliconPay_Public::fetchFeeSettings()), $this->version, true, true);
    }
}

define('SPG_WP_SILICONPAY_PERCENTAGE', 0.04);
define('SPG_WP_SILICONPAY_CROSSOVER_TOTAL', 0);
define('SPG_WP_SILICONPAY_ADDITIONAL_CHARGE', 0);
define('SPG_WP_SILICONPAY_LOCAL_CAP', 0);

define('SPG_WP_SILICONPAY_CHARGE_DIVIDER', floatval(1 - SPG_WP_SILICONPAY_PERCENTAGE));
define('SPG_WP_SILICONPAY_CROSSOVER_AMOUNT', intval((SPG_WP_SILICONPAY_CROSSOVER_TOTAL * SPG_WP_SILICONPAY_CHARGE_DIVIDER) - SPG_WP_SILICONPAY_ADDITIONAL_CHARGE));
define('SPG_WP_SILICONPAY_FLATLINE_AMOUNT_PLUS_CHARGE', intval((SPG_WP_SILICONPAY_LOCAL_CAP - SPG_WP_SILICONPAY_ADDITIONAL_CHARGE) / SPG_WP_SILICONPAY_PERCENTAGE));
define('SPG_WP_SILICONPAY_FLATLINE_AMOUNT', SPG_WP_SILICONPAY_FLATLINE_AMOUNT_PLUS_CHARGE - SPG_WP_SILICONPAY_LOCAL_CAP);

class Spg_WP_SiliconPay_Charge
{
    public $percentage;
    public $additional_charge;
    public $crossover_total;
    public $cap;

    public $charge_divider;
    public $crossover;
    public $flatline_plus_charge;
    public $flatline;

    public function __construct($percentage = 0.04, $additional_charge = 0, $crossover_total = 0, $cap = 0)
    {
        $this->percentage = $percentage;
        $this->additional_charge = $additional_charge;
        $this->crossover_total = $crossover_total;
        $this->cap = $cap;
        $this->__setup();
    }

    private function __setup()
    {
        $this->charge_divider = $this->__charge_divider();
        $this->crossover = $this->__crossover();
        $this->flatline_plus_charge = $this->__flatline_plus_charge();
        $this->flatline = $this->__flatline();
    }

    private function __charge_divider()
    {
        return floatval(1 - $this->percentage);
    }

    private function __crossover()
    {
        return ceil(($this->crossover_total * $this->charge_divider) - $this->additional_charge);
    }

    private function __flatline_plus_charge()
    {
        return floor(($this->cap - $this->additional_charge) / $this->percentage);
    }

    private function __flatline()
    {
        return $this->flatline_plus_charge - $this->cap;
    }

    public function convert_for_units($amountPayable)
    {
        if ($amountPayable > $this->flatline) {
            return $amountPayable + $this->cap;
        } elseif ($amountPayable > $this->crossover) {
            return ceil(($amountPayable + $this->additional_charge) / $this->charge_divider);
        } else {
            return ceil($amountPayable / $this->charge_divider);
        }
    }

    public function convert_for_non_ugx($amount_to_convert)
    {
        return $this->convert_for_units(ceil($amount_to_convert));
    }
}

function spg_wp_siliconpay_add_siliconpay_charge($amount)
{
    $feeSettings = Spg_WP_SiliconPay_Public::fetchFeeSettings();
    $pc = new Spg_WP_SiliconPay_Charge(
        $feeSettings['prc'],
        $feeSettings['adc'],
        $feeSettings['ths'],
        $feeSettings['cap']
    );
    return $pc->convert_for_non_ugx($amount);
}

add_filter("wp_mail_content_type", "spg_wp_siliconpay_mail_content_type");
function spg_wp_siliconpay_mail_content_type()
{
    return "text/html";
}

add_filter("wp_mail_from_name", "spg_wp_siliconpay_mail_from_name");
function spg_wp_siliconpay_mail_from_name()
{
    $name = get_option('blogname');
    return $name;
}

function spg_wp_add_google_fonts() {
    wp_enqueue_style( 'spg_wp_pay-google-fonts', 'http://fonts.googleapis.com/css?family=Noto+Sans:400,700&display=swap', false );
}



function spg_wp_siliconpay_send_invoice($currency, $amount, $name, $email, $code)
{
    //  echo date('F j,Y');
    $user_email = stripslashes($email);

    $email_subject = "Payment Invoice for " . $currency . ' ' . number_format($amount);

    ob_start(); ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
    <html>

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="format-detection" content="telephone=no">
        <meta name="format-detection" content="date=no">
        <meta name="format-detection" content="address=no">
        <meta name="format-detection" content="email=no">
        <title></title>
        <?php echo add_action( 'wp_enqueue_scripts', 'spg_wp_add_google_fonts' ); ?>
         <style type="text/css">
            body {
                Margin: 0;
                padding: 0;
                min-width: 100%
            }

            a,
            #outlook a {
                display: inline-block
            }

            a,
            a span {
                text-decoration: none
            }

            img {
                line-height: 1;
                outline: 0;
                border: 0;
                text-decoration: none;
                -ms-interpolation-mode: bicubic;
                mso-line-height-rule: exactly
            }

            table {
                border-spacing: 0;
                mso-table-lspace: 0;
                mso-table-rspace: 0
            }

            td {
                padding: 0
            }

            .email_summary {
                display: none;
                font-size: 1px;
                line-height: 1px;
                max-height: 0;
                max-width: 0;
                opacity: 0;
                overflow: hidden
            }

            .font_default,
            h1,
            h2,
            h3,
            h4,
            h5,
            h6,
            p,
            a {
                font-family: Helvetica, Arial, sans-serif
            }

            small {
                font-size: 86%;
                font-weight: normal
            }

            .pricing_box_cell small {
                font-size: 74%
            }

            .font_default,
            p {
                font-size: 15px
            }

            p {
                line-height: 23px;
                Margin-top: 16px;
                Margin-bottom: 24px
            }

            .lead {
                font-size: 19px;
                line-height: 27px;
                Margin-bottom: 16px
            }

            .header_cell .column_cell {
                font-size: 20px;
                font-weight: bold
            }

            .header_cell p {
                margin-bottom: 0
            }

            h1,
            h2,
            h3,
            h4,
            h5,
            h6 {
                Margin-left: 0;
                Margin-right: 0;
                Margin-top: 16px;
                Margin-bottom: 8px;
                padding: 0
            }

            .line-through {
                text-decoration: line-through
            }

            h1,
            h2 {
                font-size: 26px;
                line-height: 36px;
                font-weight: bold
            }

            .pricing_box h1,
            .pricing_box h2,
            .primary_pricing_box h1,
            .primary_pricing_box h2 {
                line-height: 20px;
                Margin-top: 16px;
                Margin-bottom: 0
            }

            h3,
            h4 {
                font-size: 22px;
                line-height: 30px;
                font-weight: bold
            }

            h5 {
                font-size: 18px;
                line-height: 26px;
                font-weight: bold
            }

            h6 {
                font-size: 16px;
                line-height: 24px;
                font-weight: bold
            }

            .primary_btn td,
            .secondary_btn td {
                font-size: 16px;
                mso-line-height-rule: exactly
            }

            .primary_btn a,
            .secondary_btn a {
                font-weight: bold
            }

            .email_body {
                padding: 32px 10px;
                text-align: center
            }

            .email_container,
            .row,
            .col-1,
            .col-13,
            .col-2,
            .col-3 {
                display: inline-block;
                width: 100%;
                vertical-align: top;
                text-align: center
            }

            .email_container {
                width: 100%;
                margin: 0 auto
            }

            .email_container,
            .row,
            .col-3 {
                max-width: 580px
            }

            .col-1 {
                max-width: 190px
            }

            .col-2 {
                max-width: 290px
            }

            .col-13 {
                max-width: 390px
            }

            .row {
                margin: 0 auto
            }

            .column {
                width: 100%;
                vertical-align: top
            }

            .column_cell {
                padding: 16px;
                text-align: center;
                vertical-align: top
            }

            .col-bottom-0 .column_cell {
                padding-bottom: 0
            }

            .col-top-0 .column_cell {
                padding-top: 0
            }

            .email_container,
            .header_cell,
            .jumbotron_cell,
            .content_cell,
            .footer_cell,
            .image_responsive {
                font-size: 0 !important;
                text-align: center
            }

            .header_cell,
            .footer_cell {
                padding-bottom: 16px
            }

            .header_cell .column_cell,
            .footer_cell .col-13 .column_cell,
            .footer_cell .col-1 .column_cell {
                text-align: left;
                padding-top: 16px
            }

            .header_cell {
                -webkit-border-radius: 4px 4px 0 0;
                border-radius: 4px 4px 0 0
            }

            .header_cell img {
                max-width: 156px;
                height: auto
            }

            .footer_cell {
                text-align: center;
                -webkit-border-radius: 0 0 4px 4px;
                border-radius: 0 0 4px 4px
            }

            .footer_cell p {
                Margin: 16px 0
            }

            .invoice_cell .column_cell {
                text-align: left;
                padding-top: 0;
                padding-bottom: 0
            }

            .invoice_cell p {
                margin-top: 8px;
                margin-bottom: 16px
            }

            .pricing_box {
                border-collapse: separate;
                padding: 10px 16px;
                -webkit-border-radius: 4px;
                border-radius: 4px
            }

            .primary_pricing_box {
                border-collapse: separate;
                padding: 18px 16px;
                -webkit-border-radius: 4px;
                border-radius: 4px
            }

            .text_quote .column_cell {
                border-left: 4px solid;
                text-align: left;
                padding-right: 0;
                padding-top: 0;
                padding-bottom: 0
            }

            .primary_btn,
            .secondary_btn {
                clear: both;
                margin: 0 auto
            }

            .primary_btn td,
            .secondary_btn td {
                text-align: center;
                vertical-align: middle;
                padding: 12px 24px;
                -webkit-border-radius: 4px;
                border-radius: 4px
            }

            .primary_btn a,
            .primary_btn span,
            .secondary_btn a,
            .secondary_btn span {
                text-align: center;
                display: block
            }

            .label .font_default {
                font-size: 10px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 2px;
                padding: 3px 7px;
                -webkit-border-radius: 2px;
                border-radius: 2px;
                white-space: nowrap
            }

            .icon_holder,
            .hruler {
                width: 62px;
                margin-left: auto;
                margin-right: auto;
                clear: both
            }

            .icon_holder {
                width: 48px
            }

            .hspace,
            .hruler_cell {
                font-size: 0;
                height: 8px;
                overflow: hidden
            }

            .hruler_cell {
                height: 4px;
                line-height: 4px
            }

            .icon_cell {
                font-size: 0;
                line-height: 1;
                -webkit-border-radius: 80px;
                border-radius: 80px;
                padding: 8px;
                height: 48px
            }

            .product_row {
                padding: 0 0 16px
            }

            .product_row .column_cell {
                padding: 16px 16px 0
            }

            .image_thumb img {
                -webkit-border-radius: 4px;
                border-radius: 4px
            }

            .product_row .col-13 .column_cell {
                text-align: left
            }

            .product_row h6 {
                Margin-top: 0
            }

            .product_row p {
                Margin-top: 8px;
                Margin-bottom: 8px
            }

            .order_total_right .column_cell {
                text-align: right
            }

            .order_total_left .column_cell {
                text-align: left
            }

            .order_total p {
                Margin: 8px 0
            }

            .order_total h2 {
                Margin: 8px 0
            }

            .image_responsive img {
                display: block;
                width: 100%;
                height: auto;
                max-width: 580px;
                margin-left: auto;
                margin-right: auto
            }

            body,
            .email_body {
                background-color: #f2f2f2
            }

            .header_cell,
            .footer_cell,
            .content_cell {
                background-color: #fff
            }

            .secondary_btn td,
            .icon_primary .icon_cell,
            .primary_pricing_box {
                background-color: #ffb26b
            }

            .jumbotron_cell,
            .pricing_box {
                background-color: #fafafa
            }

            .primary_btn td,
            .label .font_default {
                background-color: #666
            }

            .icon_secondary .icon_cell {
                background-color: #dbdbdb
            }

            .label_1 .font_default {
                background-color: #62a9dd
            }

            .label_2 .font_default {
                background-color: #8965ad
            }

            .label_3 .font_default {
                background-color: #df6164
            }

            .primary_btn a,
            .primary_btn span,
            .secondary_btn a,
            .secondary_btn span,
            .label .font_default,
            .primary_pricing_box,
            .primary_pricing_box h1,
            .primary_pricing_box small {
                color: #fff
            }

            h2,
            h4,
            h5,
            h6 {
                color: #666
            }

            .column_cell {
                color: #888
            }

            h1,
            h3,
            a,
            a span,
            .text-secondary,
            .column_cell .text-secondary,
            .content_cell h2 .text-secondary {
                color: #ffb26b
            }

            .footer_cell a,
            .footer_cell a span {
                color: #7a7a7a
            }

            .text-muted,
            .footer_cell .column_cell,
            .content h4 span,
            .content h3 span {
                color: #b3b3b5
            }

            .footer_cell,
            .product_row,
            .order_total {
                border-top: 1px solid
            }

            .product_row,
            .order_total,
            .icon_secondary .icon_cell,
            .footer_cell,
            .content .product_row,
            .content .order_total,
            .pricing_box,
            .text_quote .column_cell {
                border-color: #f2f2f2
            }

            @media screen {

                h1,
                h2,
                h3,
                h4,
                h5,
                h6,
                p,
                a,
                .font_default {
                    font-family: "Noto Sans", Helvetica, Arial, sans-serif !important
                }

                .primary_btn td,
                .secondary_btn td {
                    padding: 0 !important
                }

                .primary_btn a,
                .secondary_btn a {
                    padding: 12px 24px !important
                }
            }

            @media screen and (min-width: 631px) and (max-width: 769px) {

                .col-1,
                .col-2,
                .col-3,
                .col-13 {
                    float: left !important
                }

                .col-1 {
                    width: 200px !important
                }

                .col-2 {
                    width: 300px !important
                }
            }

            @media screen and (max-width: 630px) {
                .jumbotron_cell {
                    background-size: cover !important
                }

                .row,
                .col-1,
                .col-13,
                .col-2,
                .col-3 {
                    max-width: 100% !important
                }
            }
        </style>
    </head>

    <body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0"
          style="margin:0;padding:0;min-width:100%;background-color:#f2f2f2">
    <div class="email_body" style="padding:32px 10px;text-align:center;background-color:#f2f2f2">
        <div class="email_container"
             style="display:inline-block;width:100%;vertical-align:top;text-align:center;margin:0 auto;max-width:580px;font-size:0!important">
            <table class="header" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="header_cell col-bottom-0" align="center" valign="top"
                        style="padding:0;text-align:center;padding-bottom:16px;-webkit-border-radius:4px 4px 0 0;border-radius:4px 4px 0 0;background-color:#fff;font-size:0!important">
                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">
                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:20px;text-align:left;vertical-align:top;color:#ffb26b;font-weight:bold;padding-bottom:0;padding-top:16px">
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="content" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="content_cell" align="center" valign="top"
                        style="padding:0;text-align:center;background-color:#fff;font-size:0!important">
                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">
                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:center;vertical-align:top;color:#888">
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:28px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                <small class="text-muted"
                                                       style="font-size:86%;font-weight:normal;color:#b3b3b5">
                                                    <a href="#"
                                                       style="display:inline-block;text-decoration:none;font-family:Helvetica,Arial,sans-serif;color:#ffb26b"><strong
                                                                class="text-muted" style="color:#b3b3b5">Invoice
                                                            #<?php echo $code; ?></strong></a>
                                            </p>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="jumbotron" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="jumbotron_cell product_row" align="center" valign="top"
                        style="padding:0 0 16px;text-align:center;background-color:#fff;border-top:1px solid;border-color:#f2f2f2;font-size:0!important">
                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">
                            <div class="col-13"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:390px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px 16px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#888">
                                            <small class="text-muted"
                                                   style="font-size:86%;font-weight:normal;color:#b3b3b5"><?php echo date('F j,Y'); ?></small>
                                            <h6
                                                    style="font-family:Helvetica,Arial,sans-serif;margin-left:0;margin-right:0;margin-top:0;margin-bottom:8px;padding:0;font-size:16px;line-height:24px;font-weight:bold;color:#666">
                                                <?php echo $name; ?></h6>
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:8px;margin-bottom:8px">
                                                <?php echo $email; ?></p>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-1"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:190px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="left" valign="top"
                                            style="padding:16px 16px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:center;vertical-align:top;color:#888">
                                            <h1
                                                    style="font-family:Helvetica,Arial,sans-serif;margin-left:0;margin-right:0;margin-top:16px;margin-bottom:8px;padding:0;font-size:26px;line-height:36px;font-weight:bold;color:#ffb26b">
                                                <?php echo $currency . ' ' . number_format($amount); ?></h1>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="content" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="content_cell" align="center" valign="top"
                        style="padding:0;text-align:center;background-color:#fff;font-size:0!important">
                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">
                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:center;vertical-align:top;color:#888">
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                You're getting this email because <br/>you tried making initiating
                                                payment
                                                to <?php echo get_option('blogname'); ?>.</p>
                                            <table class="primary_btn" align="center" border="0" cellspacing="0"
                                                   cellpadding="0"
                                                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;clear:both;margin:0 auto">
                                                <tbody>
                                                <tr>
                                                    <p
                                                            style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                        <small class="text-muted"
                                                               style="font-size:86%;font-weight:normal;color:#b3b3b5">Use
                                                            this link below to try again, if you encountered
                                                            <br/>any
                                                            issue while trying to make the
                                                            payment.</small><br>
                                                    </p>
                                                    <td class="font_default"
                                                        style="padding:12px 24px;font-family:Helvetica,Arial,sans-serif;font-size:16px;mso-line-height-rule:exactly;text-align:center;vertical-align:middle;-webkit-border-radius:4px;border-radius:4px;background-color:#666">
                                                        <a href="<?php echo get_site_url() . '/siliconpayinvoice/?code=' . $code; ?>"
                                                           style="display:block;text-decoration:none;font-family:Helvetica,Arial,sans-serif;color:#fff;font-weight:bold;text-align:center">
                                                                        <span
                                                                                style="text-decoration:none;color:#fff;text-align:center;display:block">Try
                                                                            Again</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="footer" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="footer_cell" align="center" valign="top"
                        style="padding:0;text-align:center;padding-bottom:16px;-webkit-border-radius:0 0 4px 4px;border-radius:0 0 4px 4px;background-color:#fff;border-top:1px solid;border-color:#f2f2f2;font-size:0!important">
                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">
                            <div class="col-13 col-bottom-0"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:390px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#b3b3b5;padding-bottom:0;padding-top:16px">
                                            <strong><?php echo get_option('blogname'); ?></strong><br>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-1 col-bottom-0"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:190px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#b3b3b5;padding-bottom:0;padding-top:16px">
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    </body>

    </html>
    <?php

    $message = ob_get_contents();
    ob_end_clean();
    // $admin_email = get_option( 'admin_email' );

    $admin_email = get_option('admin_email');
    $website = get_option('blogname');
    $headers = array('Reply-To: ' . $admin_email, "From: $website <$admin_email>" . "\r\n");
    $headers = "From: " . $website . "<$admin_email>" . "\r\n";
    wp_mail($user_email, $email_subject, $message, $headers);
}

function spg_wp_siliconpay_send_receipt($id, $currency, $amount, $name, $email, $code, $metadata)
{
    //  echo date('F j,Y'); 
    // error_log(print_r("Sending reciept", TRUE)); 
    $user_email = stripslashes($email);
    $subject = get_post_meta($id, '_subject', true);
    $merchant = get_post_meta($id, '_merchant', true);
    $heading = get_post_meta($id, '_heading', true);
    $sitemessage = get_post_meta($id, '_message', true);

    $email_subject = $subject;

    ob_start(); ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
    <html>

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="format-detection" content="telephone=no">
        <meta name="format-detection" content="date=no">
        <meta name="format-detection" content="address=no">
        <meta name="format-detection" content="email=no">
        <title></title>
        <?php echo add_action( 'wp_enqueue_scripts', 'spg_wp_add_google_fonts' ); ?>
        <style type="text/css">
            body {
                Margin: 0;
                padding: 0;
                min-width: 100%
            }

            a,
            #outlook a {
                display: inline-block
            }

            a,
            a span {
                text-decoration: none
            }

            img {
                line-height: 1;
                outline: 0;
                border: 0;
                text-decoration: none;
                -ms-interpolation-mode: bicubic;
                mso-line-height-rule: exactly
            }

            table {
                border-spacing: 0;
                mso-table-lspace: 0;
                mso-table-rspace: 0
            }

            td {
                padding: 0
            }

            .email_summary {
                display: none;
                font-size: 1px;
                line-height: 1px;
                max-height: 0;
                max-width: 0;
                opacity: 0;
                overflow: hidden
            }

            .font_default,
            h1,
            h2,
            h3,
            h4,
            h5,
            h6,
            p,
            a {
                font-family: Helvetica, Arial, sans-serif
            }

            small {
                font-size: 86%;
                font-weight: normal
            }

            .pricing_box_cell small {
                font-size: 74%
            }

            .font_default,
            p {
                font-size: 15px
            }

            p {
                line-height: 23px;
                Margin-top: 16px;
                Margin-bottom: 24px
            }

            .lead {
                font-size: 19px;
                line-height: 27px;
                Margin-bottom: 16px
            }

            .header_cell .column_cell {
                font-size: 20px;
                font-weight: bold
            }

            .header_cell p {
                margin-bottom: 0
            }

            h1,
            h2,
            h3,
            h4,
            h5,
            h6 {
                Margin-left: 0;
                Margin-right: 0;
                Margin-top: 16px;
                Margin-bottom: 8px;
                padding: 0
            }

            .line-through {
                text-decoration: line-through
            }

            h1,
            h2 {
                font-size: 26px;
                line-height: 36px;
                font-weight: bold
            }

            .pricing_box h1,
            .pricing_box h2,
            .primary_pricing_box h1,
            .primary_pricing_box h2 {
                line-height: 20px;
                Margin-top: 16px;
                Margin-bottom: 0
            }

            h3,
            h4 {
                font-size: 22px;
                line-height: 30px;
                font-weight: bold
            }

            h5 {
                font-size: 18px;
                line-height: 26px;
                font-weight: bold
            }

            h6 {
                font-size: 16px;
                line-height: 24px;
                font-weight: bold
            }

            .primary_btn td,
            .secondary_btn td {
                font-size: 16px;
                mso-line-height-rule: exactly
            }

            .primary_btn a,
            .secondary_btn a {
                font-weight: bold
            }

            .email_body {
                padding: 32px 6px;
                text-align: center
            }

            .email_container,
            .row,
            .col-1,
            .col-13,
            .col-2,
            .col-3 {
                display: inline-block;
                width: 100%;
                vertical-align: top;
                text-align: center
            }

            .email_container {
                width: 100%;
                margin: 0 auto
            }

            .email_container {
                max-width: 588px
            }

            .row,
            .col-3 {
                max-width: 580px
            }

            .col-1 {
                max-width: 190px
            }

            .col-2 {
                max-width: 290px
            }

            .col-13 {
                max-width: 390px
            }

            .row {
                margin: 0 auto
            }

            .column {
                width: 100%;
                vertical-align: top
            }

            .column_cell {
                padding: 16px;
                text-align: center;
                vertical-align: top
            }

            .col-bottom-0 .column_cell {
                padding-bottom: 0
            }

            .col-top-0 .column_cell {
                padding-top: 0
            }

            .email_container,
            .header_cell,
            .jumbotron_cell,
            .content_cell,
            .footer_cell,
            .image_responsive {
                font-size: 0 !important;
                text-align: center
            }

            .header_cell,
            .footer_cell {
                padding-bottom: 16px
            }

            .header_cell .column_cell,
            .footer_cell .col-13 .column_cell,
            .footer_cell .col-1 .column_cell {
                text-align: left;
                padding-top: 16px
            }

            .header_cell img {
                max-width: 156px;
                height: auto
            }

            .footer_cell {
                text-align: center
            }

            .footer_cell p {
                Margin: 16px 0
            }

            .invoice_cell .column_cell {
                text-align: left;
                padding-top: 0;
                padding-bottom: 0
            }

            .invoice_cell p {
                margin-top: 8px;
                margin-bottom: 16px
            }

            .pricing_box {
                border-collapse: separate;
                padding: 10px 16px
            }

            .primary_pricing_box {
                border-collapse: separate;
                padding: 18px 16px
            }

            .text_quote .column_cell {
                border-left: 4px solid;
                text-align: left;
                padding-right: 0;
                padding-top: 0;
                padding-bottom: 0
            }

            .primary_btn,
            .secondary_btn {
                clear: both;
                margin: 0 auto
            }

            .primary_btn td,
            .secondary_btn td {
                text-align: center;
                vertical-align: middle;
                padding: 12px 24px
            }

            .primary_btn a,
            .primary_btn span,
            .secondary_btn a,
            .secondary_btn span {
                text-align: center;
                display: block
            }

            .label .font_default {
                font-size: 10px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 2px;
                padding: 3px 7px;
                white-space: nowrap
            }

            .icon_holder,
            .hruler {
                width: 62px;
                margin-left: auto;
                margin-right: auto;
                clear: both
            }

            .icon_holder {
                width: 48px
            }

            .hspace,
            .hruler_cell {
                font-size: 0;
                height: 8px;
                overflow: hidden
            }

            .hruler_cell {
                height: 4px;
                line-height: 4px
            }

            .icon_cell {
                font-size: 0;
                line-height: 1;
                padding: 8px;
                height: 48px
            }

            .product_row {
                padding: 0 0 16px
            }

            .product_row .column_cell {
                padding: 16px 16px 0
            }

            .product_row .col-13 .column_cell {
                text-align: left
            }

            .product_row h6 {
                Margin-top: 0
            }

            .product_row p {
                Margin-top: 8px;
                Margin-bottom: 8px
            }

            .order_total_right .column_cell {
                text-align: right
            }

            .order_total_left .column_cell {
                text-align: left
            }

            .order_total p {
                Margin: 8px 0
            }

            .order_total h2 {
                Margin: 8px 0
            }

            .image_responsive img {
                display: block;
                width: 100%;
                height: auto;
                max-width: 580px;
                margin-left: auto;
                margin-right: auto
            }

            body,
            .email_body,
            .header_cell,
            .content_cell,
            .footer_cell {
                background-color: #fff
            }

            .secondary_btn td,
            .icon_primary .icon_cell,
            .primary_pricing_box {
                background-color: #2f68b4
            }

            .jumbotron_cell,
            .pricing_box {
                background-color: #f2f2f5
            }

            .primary_btn td,
            .label .font_default {
                background-color: #22aaa0
            }

            .icon_secondary .icon_cell {
                background-color: #e1e3e7
            }

            .label_1 .font_default {
                background-color: #62a9dd
            }

            .label_2 .font_default {
                background-color: #8965ad
            }

            .label_3 .font_default {
                background-color: #df6164
            }

            .primary_btn a,
            .primary_btn span,
            .secondary_btn a,
            .secondary_btn span,
            .label .font_default,
            .primary_pricing_box,
            .primary_pricing_box h1,
            .primary_pricing_box small {
                color: #fff
            }

            h2,
            h4,
            h5,
            h6 {
                color: #383d42
            }

            .column_cell {
                color: #888
            }

            .header_cell .column_cell,
            .header_cell a,
            .header_cell a span,
            h1,
            h3,
            a,
            a span,
            .text-secondary,
            .column_cell .text-secondary,
            .content_cell h2 .text-secondary {
                color: #2f68b4
            }

            .footer_cell a,
            .footer_cell a span {
                color: #7a7a7a
            }

            .text-muted,
            .footer_cell .column_cell,
            .content h4 span,
            .content h3 span {
                color: #b3b3b5
            }

            .header_cell,
            .footer_cell {
                border-top: 4px solid;
                border-bottom: 4px solid
            }

            .header_cell,
            .footer_cell,
            .jumbotron_cell,
            .content_cell {
                border-left: 4px solid;
                border-right: 4px solid
            }

            .footer_cell,
            .product_row,
            .order_total {
                border-top: 1px solid
            }

            .header_cell,
            .footer_cell,
            .jumbotron_cell,
            .content_cell,
            .product_row,
            .order_total,
            .icon_secondary .icon_cell,
            .footer_cell,
            .content .product_row,
            .content .order_total,
            .pricing_box,
            .text_quote .column_cell {
                border-color: #d8dde4
            }

            @media screen {

                h1,
                h2,
                h3,
                h4,
                h5,
                h6,
                p,
                a,
                .font_default {
                    font-family: "Noto Sans", Helvetica, Arial, sans-serif !important
                }

                .primary_btn td,
                .secondary_btn td {
                    padding: 0 !important
                }

                .primary_btn a,
                .secondary_btn a {
                    padding: 12px 24px !important
                }
            }

            @media screen and (min-width: 631px) and (max-width: 769px) {

                .col-1,
                .col-2,
                .col-3,
                .col-13 {
                    float: left !important
                }

                .col-1 {
                    width: 200px !important
                }

                .col-2 {
                    width: 300px !important
                }
            }

            @media screen and (max-width: 630px) {
                .jumbotron_cell {
                    background-size: cover !important
                }

                .row,
                .col-1,
                .col-13,
                .col-2,
                .col-3 {
                    max-width: 100% !important
                }
            }
        </style>
    </head>

    <body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0"
          style="margin:0;padding:0;min-width:100%;background-color:#fff">
    <div class="email_body" style="padding:32px 6px;text-align:center;background-color:#fff">

        <div class="email_container"
             style="display:inline-block;width:100%;vertical-align:top;text-align:center;margin:0 auto;max-width:588px;font-size:0!important">
            <table class="header" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="header_cell col-bottom-0" align="center" valign="top"
                        style="padding:0;text-align:center;padding-bottom:16px;border-top:4px solid;border-bottom:0 solid;background-color:#fff;border-left:4px solid;border-right:4px solid;border-color:#d8dde4;font-size:0!important">

                    </td>
                </tr>
                </tbody>
            </table>
            <table class="content" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="content_cell" align="center" valign="top"
                        style="padding:0;text-align:center;background-color:#fff;border-left:4px solid;border-right:4px solid;border-color:#d8dde4;font-size:0!important">

                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">

                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:center;vertical-align:top;color:#888">
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                &nbsp; </p>
                                            <h5
                                                    style="font-family:Helvetica,Arial,sans-serif;margin-left:0;margin-right:0;margin-top:16px;margin-bottom:8px;padding:0;font-size:18px;line-height:26px;font-weight:bold;color:#383d42">
                                                <?php echo $heading; ?></h5>
                                            <p align="left"
                                               style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                Hello <?php echo strstr($name . " ", " ", true); ?>,</p>
                                            <p align="left"
                                               style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                <?php echo $sitemessage; ?></p>
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                &nbsp; </p>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>

                        </div>

                    </td>
                </tr>
                </tbody>
            </table>
            <table class="jumbotron" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="jumbotron_cell invoice_cell" align="center" valign="top"
                        style="padding:0;text-align:center;background-color:#fafafa;font-size:0!important">

                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">

                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:left">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#888;padding-top:0;padding-bottom:0">
                                            <table class="label" border="0" cellspacing="0" cellpadding="0"
                                                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                                                <tbody>
                                                <tr>
                                                    <td class="hspace"
                                                        style="padding:0;font-size:0;height:8px;overflow:hidden">
                                                        &nbsp;
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="hspace"
                                                        style="padding:0;font-size:0;height:8px;overflow:hidden">
                                                        &nbsp;
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="font_default"
                                                        style="padding:3px 7px;font-family:Helvetica,Arial,sans-serif;font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:2px;-webkit-border-radius:2px;border-radius:2px;white-space:nowrap;background-color:#666;color:#fff">
                                                        Your Details
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:8px;margin-bottom:16px">
                                                Amount <strong>
                                                    :
                                                    <?php echo $currency . ' ' . number_format($amount); ?></strong><br>
                                                Email <strong> : <?php echo $user_email; ?></strong><br>
                                                <?php
                                                $new = json_decode($metadata);
                                                if (array_key_exists("0", $new)) {
                                                    foreach ($new as $key => $item) {
                                                        if ($item->type == 'text') {
                                                            echo $item->display_name . "<strong>  :" . $item->value . "</strong><br>";
                                                        } else {
                                                            echo $item->display_name . "<strong>  : <a target='_blank' href='" . $item->value . "'>link</a></strong><br>";
                                                        }
                                                    }
                                                } else {
                                                    $text = '';
                                                    if (count($new) > 0) {
                                                        foreach ($new as $key => $item) {
                                                            echo $key . "<strong>  :" . $item . "</strong><br />";
                                                        }
                                                    }
                                                } ?>
                                                Transaction code: <strong> <?php echo $code; ?></strong><br>
                                            </p>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </td>
                </tr>
                </tbody>
            </table>
            <table class="jumbotron" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="jumbotron_cell product_row" align="center" valign="top"
                        style="padding:0 0 16px;text-align:center;background-color:#f2f2f5;border-left:4px solid;border-right:4px solid;border-top:1px solid;border-color:#d8dde4;font-size:0!important">

                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">

                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px 16px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:center;vertical-align:top;color:#888">
                                            <small
                                                    style="font-size:86%;font-weight:normal"><strong>Notice</strong><br>
                                                You're getting this email because you've initiated a payment
                                                of <?php $currency . ' ' . number_format($amount); ?> to <a
                                                        href="<?php echo get_bloginfo('url') ?>"
                                                        style="display:inline-block;text-decoration:none;font-family:Helvetica,Arial,sans-serif;color:#2f68b4"><?php echo get_option('blogname'); ?></a>.</small>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="footer" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="footer_cell" align="center" valign="top"
                        style="padding:0;text-align:center;padding-bottom:16px;border-top:1px solid;border-bottom:4px solid;background-color:#fff;border-left:4px solid;border-right:4px solid;border-color:#d8dde4;font-size:0!important">
                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">
                            <div class="col-13 col-bottom-0"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:390px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#b3b3b5;padding-bottom:0;padding-top:16px">
                                            <strong><?php echo get_option('blogname'); ?></strong><br>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-1 col-bottom-0"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:190px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#b3b3b5;padding-bottom:0;padding-top:16px">
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    </body>

    </html>

    <?php

    $message = ob_get_contents();
    ob_end_clean();
    $admin_email = get_option('admin_email');
    $website = get_option('blogname');
    $headers = array('Reply-To: ' . $admin_email, "From: $website <$admin_email>" . "\r\n");
    $headers = "From: " . $website . "<$admin_email>" . "\r\n";
    wp_mail($user_email, $email_subject, $message, $headers);
}

function spg_wp_siliconpay_send_receipt_owner($id, $currency, $amount, $name, $email, $code, $metadata)
{
    //  echo date('F j,Y');
    $user_email = stripslashes($email);
    $subject = "You just received a payment";
    $heading = get_post_meta($id, '_heading', true);
    $sitemessage = get_post_meta($id, '_message', true);

    $email_subject = $subject;

    ob_start(); ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
    <html>

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="format-detection" content="telephone=no">
        <meta name="format-detection" content="date=no">
        <meta name="format-detection" content="address=no">
        <meta name="format-detection" content="email=no">
        <title></title>
        <?php echo add_action( 'wp_enqueue_scripts', 'spg_wp_add_google_fonts' ); ?>
        <style type="text/css">
            body {
                Margin: 0;
                padding: 0;
                min-width: 100%
            }

            a,
            #outlook a {
                display: inline-block
            }

            a,
            a span {
                text-decoration: none
            }

            img {
                line-height: 1;
                outline: 0;
                border: 0;
                text-decoration: none;
                -ms-interpolation-mode: bicubic;
                mso-line-height-rule: exactly
            }

            table {
                border-spacing: 0;
                mso-table-lspace: 0;
                mso-table-rspace: 0
            }

            td {
                padding: 0
            }

            .email_summary {
                display: none;
                font-size: 1px;
                line-height: 1px;
                max-height: 0;
                max-width: 0;
                opacity: 0;
                overflow: hidden
            }

            .font_default,
            h1,
            h2,
            h3,
            h4,
            h5,
            h6,
            p,
            a {
                font-family: Helvetica, Arial, sans-serif
            }

            small {
                font-size: 86%;
                font-weight: normal
            }

            .pricing_box_cell small {
                font-size: 74%
            }

            .font_default,
            p {
                font-size: 15px
            }

            p {
                line-height: 23px;
                Margin-top: 16px;
                Margin-bottom: 24px
            }

            .lead {
                font-size: 19px;
                line-height: 27px;
                Margin-bottom: 16px
            }

            .header_cell .column_cell {
                font-size: 20px;
                font-weight: bold
            }

            .header_cell p {
                margin-bottom: 0
            }

            h1,
            h2,
            h3,
            h4,
            h5,
            h6 {
                Margin-left: 0;
                Margin-right: 0;
                Margin-top: 16px;
                Margin-bottom: 8px;
                padding: 0
            }

            .line-through {
                text-decoration: line-through
            }

            h1,
            h2 {
                font-size: 26px;
                line-height: 36px;
                font-weight: bold
            }

            .pricing_box h1,
            .pricing_box h2,
            .primary_pricing_box h1,
            .primary_pricing_box h2 {
                line-height: 20px;
                Margin-top: 16px;
                Margin-bottom: 0
            }

            h3,
            h4 {
                font-size: 22px;
                line-height: 30px;
                font-weight: bold
            }

            h5 {
                font-size: 18px;
                line-height: 26px;
                font-weight: bold
            }

            h6 {
                font-size: 16px;
                line-height: 24px;
                font-weight: bold
            }

            .primary_btn td,
            .secondary_btn td {
                font-size: 16px;
                mso-line-height-rule: exactly
            }

            .primary_btn a,
            .secondary_btn a {
                font-weight: bold
            }

            .email_body {
                padding: 32px 6px;
                text-align: center
            }

            .email_container,
            .row,
            .col-1,
            .col-13,
            .col-2,
            .col-3 {
                display: inline-block;
                width: 100%;
                vertical-align: top;
                text-align: center
            }

            .email_container {
                width: 100%;
                margin: 0 auto
            }

            .email_container {
                max-width: 588px
            }

            .row,
            .col-3 {
                max-width: 580px
            }

            .col-1 {
                max-width: 190px
            }

            .col-2 {
                max-width: 290px
            }

            .col-13 {
                max-width: 390px
            }

            .row {
                margin: 0 auto
            }

            .column {
                width: 100%;
                vertical-align: top
            }

            .column_cell {
                padding: 16px;
                text-align: center;
                vertical-align: top
            }

            .col-bottom-0 .column_cell {
                padding-bottom: 0
            }

            .col-top-0 .column_cell {
                padding-top: 0
            }

            .email_container,
            .header_cell,
            .jumbotron_cell,
            .content_cell,
            .footer_cell,
            .image_responsive {
                font-size: 0 !important;
                text-align: center
            }

            .header_cell,
            .footer_cell {
                padding-bottom: 16px
            }

            .header_cell .column_cell,
            .footer_cell .col-13 .column_cell,
            .footer_cell .col-1 .column_cell {
                text-align: left;
                padding-top: 16px
            }

            .header_cell img {
                max-width: 156px;
                height: auto
            }

            .footer_cell {
                text-align: center
            }

            .footer_cell p {
                Margin: 16px 0
            }

            .invoice_cell .column_cell {
                text-align: left;
                padding-top: 0;
                padding-bottom: 0
            }

            .invoice_cell p {
                margin-top: 8px;
                margin-bottom: 16px
            }

            .pricing_box {
                border-collapse: separate;
                padding: 10px 16px
            }

            .primary_pricing_box {
                border-collapse: separate;
                padding: 18px 16px
            }

            .text_quote .column_cell {
                border-left: 4px solid;
                text-align: left;
                padding-right: 0;
                padding-top: 0;
                padding-bottom: 0
            }

            .primary_btn,
            .secondary_btn {
                clear: both;
                margin: 0 auto
            }

            .primary_btn td,
            .secondary_btn td {
                text-align: center;
                vertical-align: middle;
                padding: 12px 24px
            }

            .primary_btn a,
            .primary_btn span,
            .secondary_btn a,
            .secondary_btn span {
                text-align: center;
                display: block
            }

            .label .font_default {
                font-size: 10px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 2px;
                padding: 3px 7px;
                white-space: nowrap
            }

            .icon_holder,
            .hruler {
                width: 62px;
                margin-left: auto;
                margin-right: auto;
                clear: both
            }

            .icon_holder {
                width: 48px
            }

            .hspace,
            .hruler_cell {
                font-size: 0;
                height: 8px;
                overflow: hidden
            }

            .hruler_cell {
                height: 4px;
                line-height: 4px
            }

            .icon_cell {
                font-size: 0;
                line-height: 1;
                padding: 8px;
                height: 48px
            }

            .product_row {
                padding: 0 0 16px
            }

            .product_row .column_cell {
                padding: 16px 16px 0
            }

            .product_row .col-13 .column_cell {
                text-align: left
            }

            .product_row h6 {
                Margin-top: 0
            }

            .product_row p {
                Margin-top: 8px;
                Margin-bottom: 8px
            }

            .order_total_right .column_cell {
                text-align: right
            }

            .order_total_left .column_cell {
                text-align: left
            }

            .order_total p {
                Margin: 8px 0
            }

            .order_total h2 {
                Margin: 8px 0
            }

            .image_responsive img {
                display: block;
                width: 100%;
                height: auto;
                max-width: 580px;
                margin-left: auto;
                margin-right: auto
            }

            body,
            .email_body,
            .header_cell,
            .content_cell,
            .footer_cell {
                background-color: #fff
            }

            .secondary_btn td,
            .icon_primary .icon_cell,
            .primary_pricing_box {
                background-color: #2f68b4
            }

            .jumbotron_cell,
            .pricing_box {
                background-color: #f2f2f5
            }

            .primary_btn td,
            .label .font_default {
                background-color: #22aaa0
            }

            .icon_secondary .icon_cell {
                background-color: #e1e3e7
            }

            .label_1 .font_default {
                background-color: #62a9dd
            }

            .label_2 .font_default {
                background-color: #8965ad
            }

            .label_3 .font_default {
                background-color: #df6164
            }

            .primary_btn a,
            .primary_btn span,
            .secondary_btn a,
            .secondary_btn span,
            .label .font_default,
            .primary_pricing_box,
            .primary_pricing_box h1,
            .primary_pricing_box small {
                color: #fff
            }

            h2,
            h4,
            h5,
            h6 {
                color: #383d42
            }

            .column_cell {
                color: #888
            }

            .header_cell .column_cell,
            .header_cell a,
            .header_cell a span,
            h1,
            h3,
            a,
            a span,
            .text-secondary,
            .column_cell .text-secondary,
            .content_cell h2 .text-secondary {
                color: #2f68b4
            }

            .footer_cell a,
            .footer_cell a span {
                color: #7a7a7a
            }

            .text-muted,
            .footer_cell .column_cell,
            .content h4 span,
            .content h3 span {
                color: #b3b3b5
            }

            .header_cell,
            .footer_cell {
                border-top: 4px solid;
                border-bottom: 4px solid
            }

            .header_cell,
            .footer_cell,
            .jumbotron_cell,
            .content_cell {
                border-left: 4px solid;
                border-right: 4px solid
            }

            .footer_cell,
            .product_row,
            .order_total {
                border-top: 1px solid
            }

            .header_cell,
            .footer_cell,
            .jumbotron_cell,
            .content_cell,
            .product_row,
            .order_total,
            .icon_secondary .icon_cell,
            .footer_cell,
            .content .product_row,
            .content .order_total,
            .pricing_box,
            .text_quote .column_cell {
                border-color: #d8dde4
            }

            @media screen {

                h1,
                h2,
                h3,
                h4,
                h5,
                h6,
                p,
                a,
                .font_default {
                    font-family: "Noto Sans", Helvetica, Arial, sans-serif !important
                }

                .primary_btn td,
                .secondary_btn td {
                    padding: 0 !important
                }

                .primary_btn a,
                .secondary_btn a {
                    padding: 12px 24px !important
                }
            }

            @media screen and (min-width: 631px) and (max-width: 769px) {

                .col-1,
                .col-2,
                .col-3,
                .col-13 {
                    float: left !important
                }

                .col-1 {
                    width: 200px !important
                }

                .col-2 {
                    width: 300px !important
                }
            }

            @media screen and (max-width: 630px) {
                .jumbotron_cell {
                    background-size: cover !important
                }

                .row,
                .col-1,
                .col-13,
                .col-2,
                .col-3 {
                    max-width: 100% !important
                }
            }
        </style>
    </head>

    <body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0"
          style="margin:0;padding:0;min-width:100%;background-color:#fff">
    <div class="email_body" style="padding:32px 6px;text-align:center;background-color:#fff">

        <div class="email_container"
             style="display:inline-block;width:100%;vertical-align:top;text-align:center;margin:0 auto;max-width:588px;font-size:0!important">
            <table class="header" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="header_cell col-bottom-0" align="center" valign="top"
                        style="padding:0;text-align:center;padding-bottom:16px;border-top:4px solid;border-bottom:0 solid;background-color:#fff;border-left:4px solid;border-right:4px solid;border-color:#d8dde4;font-size:0!important">

                    </td>
                </tr>
                </tbody>
            </table>
            <table class="content" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="content_cell" align="center" valign="top"
                        style="padding:0;text-align:center;background-color:#fff;border-left:4px solid;border-right:4px solid;border-color:#d8dde4;font-size:0!important">

                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">

                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:center;vertical-align:top;color:#888">
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:16px;margin-bottom:24px">
                                                &nbsp; </p>
                                            <h5
                                                    style="font-family:Helvetica,Arial,sans-serif;margin-left:0;margin-right:0;margin-top:16px;margin-bottom:8px;padding:0;font-size:18px;line-height:26px;font-weight:bold;color:#383d42">
                                                You just received a payment</h5>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>

                        </div>

                    </td>
                </tr>
                </tbody>
            </table>
            <table class="jumbotron" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="jumbotron_cell invoice_cell" align="center" valign="top"
                        style="padding:0;text-align:center;background-color:#fafafa;font-size:0!important">

                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">

                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:left">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#888;padding-top:0;padding-bottom:0">
                                            <table class="label" border="0" cellspacing="0" cellpadding="0"
                                                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                                                <tbody>
                                                <tr>
                                                    <td class="hspace"
                                                        style="padding:0;font-size:0;height:8px;overflow:hidden">
                                                        &nbsp;
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="hspace"
                                                        style="padding:0;font-size:0;height:8px;overflow:hidden">
                                                        &nbsp;
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="font_default"
                                                        style="padding:3px 7px;font-family:Helvetica,Arial,sans-serif;font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:2px;-webkit-border-radius:2px;border-radius:2px;white-space:nowrap;background-color:#666;color:#fff">
                                                        Payment Details
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                            <p
                                                    style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;margin-top:8px;margin-bottom:16px">
                                                Amount <strong>
                                                    :
                                                    <?php echo $currency . ' ' . number_format($amount); ?></strong><br>
                                                Email <strong> : <?php echo $user_email; ?></strong><br>
                                                <?php
                                                $new = json_decode($metadata);
                                                if (array_key_exists("0", $new)) {
                                                    foreach ($new as $key => $item) {
                                                        if ($item->type == 'text') {
                                                            echo $item->display_name . "<strong>  :" . esc_attr($item->value) . "</strong><br>";
                                                        } else {
                                                            echo $item->display_name . "<strong>  : <a target='_blank' href='" . esc_url($item->value) . "'>link</a></strong><br>";
                                                        }
                                                    }
                                                } else {
                                                    $text = '';
                                                    if (count($new) > 0) {
                                                        foreach ($new as $key => $item) {
                                                            echo $key . "<strong>  :" . esc_attr($item) . "</strong><br />";
                                                        }
                                                    }
                                                } ?>
                                                Transaction code: <strong> <?php echo $code; ?></strong><br>
                                            </p>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </td>
                </tr>
                </tbody>
            </table>
            <table class="jumbotron" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="jumbotron_cell product_row" align="center" valign="top"
                        style="padding:0 0 16px;text-align:center;background-color:#f2f2f5;border-left:4px solid;border-right:4px solid;border-top:1px solid;border-color:#d8dde4;font-size:0!important">

                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">

                            <div class="col-3"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px 16px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:center;vertical-align:top;color:#888">
                                            <small
                                                    style="font-size:86%;font-weight:normal"><strong>Notice</strong><br>
                                                You're getting this email because someone initiated a payment
                                                of <?php $currency . ' ' . number_format($amount); ?> to <a
                                                        href="<?php echo get_bloginfo('url') ?>"
                                                        style="display:inline-block;text-decoration:none;font-family:Helvetica,Arial,sans-serif;color:#2f68b4"><?php echo get_option('blogname'); ?></a>.</small>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="footer" width="100%" border="0" cellspacing="0" cellpadding="0"
                   style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tbody>
                <tr>
                    <td class="footer_cell" align="center" valign="top"
                        style="padding:0;text-align:center;padding-bottom:16px;border-top:1px solid;border-bottom:4px solid;background-color:#fff;border-left:4px solid;border-right:4px solid;border-color:#d8dde4;font-size:0!important">
                        <div class="row"
                             style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:580px;margin:0 auto">
                            <div class="col-13 col-bottom-0"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:390px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#b3b3b5;padding-bottom:0;padding-top:16px">
                                            <strong><?php echo get_option('blogname'); ?></strong><br>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-1 col-bottom-0"
                                 style="display:inline-block;width:100%;vertical-align:top;text-align:center;max-width:190px">
                                <table class="column" width="100%" border="0" cellspacing="0" cellpadding="0"
                                       style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;width:100%;vertical-align:top">
                                    <tbody>
                                    <tr>
                                        <td class="column_cell font_default" align="center" valign="top"
                                            style="padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:15px;text-align:left;vertical-align:top;color:#b3b3b5;padding-bottom:0;padding-top:16px">
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    </body>

    </html>

    <?php

    $message = ob_get_contents();
    ob_end_clean();
    $admin_email = get_option('admin_email');
    $website = get_option('blogname');
    // $headers = array("From: $website <$admin_email>" . "\r\n");
    $headers = "From: " . $website . "<$admin_email>" . "\r\n";
    wp_mail($admin_email, $email_subject, $message, $headers);
}

function spg_wp_siliconpay_fetch_plan($code)
{
    $mode = esc_attr(get_option('mode'));
    if ($mode == 'test') {
        $key = esc_attr(get_option('lsk'));
    } else {
        $key = esc_attr(get_option('lsk'));
    }
    $siliconpay_url = 'https://silicon-pay.com/plan/' . $code;
    $headers = array(
        'Authorization' => 'Bearer ' . $key
    );
    $args = array(
        'headers' => $headers,
        'timeout' => 60
    );
    $request = wp_remote_get($siliconpay_url, $args);
    if (!is_wp_error($request)) {
        $siliconpay_response = json_decode(wp_remote_retrieve_body($request));
    }
    return $siliconpay_response;
}

function spg_wp_siliconpay_form_shortcode($atts)
{
    ob_start();

    global $current_user;
    $user_id = $current_user->ID;
    $email = $current_user->user_email;
    $fname = $current_user->user_firstname;
    $lname = $current_user->user_lastname;
    if ($fname == '' && $lname == '') {
        $fullname = '';
    } else {
        $fullname = $fname . ' ' . $lname;
    }
    extract(
        shortcode_atts(
            array(
                'id' => 0,
            ),
            $atts
        )
    );
    $pk = Spg_WP_SiliconPay_Public::fetchEncryptionKey();
    if (!$pk) {
        $settingslink = get_admin_url() . 'edit.php?post_type=siliconpay_form&page=class-siliconpay-forms-admin.php';
        echo "<h5>You must set your SiliconPay API keys first <a href='" . $settingslink . "'>settings</a></h5>";
    } elseif ($id != 0) {
        $obj = get_post($id);
        if ($obj->post_type == 'siliconpay_form') {
            $amount = get_post_meta($id, '_amount', true);
            $thankyou = get_post_meta($id, '_successmsg', true);
            $paybtn = get_post_meta($id, '_paybtn', true);
            $loggedin = get_post_meta($id, '_loggedin', true);
            $txncharge = get_post_meta($id, '_txncharge', true);
            $currency = get_post_meta($id, '_currency', true);
            $recur = get_post_meta($id, '_recur', true);
            $recurplan = get_post_meta($id, '_recurplan', true);
            $usequantity = get_post_meta($id, '_usequantity', true);
            $quantity = get_post_meta($id, '_quantity', true);
            $quantityunit = get_post_meta($id, '_quantityunit', true);
            $useagreement = get_post_meta($id, '_useagreement', true);
            $agreementlink = get_post_meta($id, '_agreementlink', true);
            $minimum = get_post_meta($id, '_minimum', true);
            $variableamount = get_post_meta($id, '_variableamount', true);
            $usevariableamount = get_post_meta($id, '_usevariableamount', true);
            $hidetitle = get_post_meta($id, '_hidetitle', true);
            if ($minimum == "") {
                $minimum = 0;
            }
            if ($usevariableamount == "") {
                $usevariableamount = 0;
            }
            if ($usevariableamount == 1) {
                $paymentoptions = explode(',', $variableamount);

            }
            $showbtn = true;
            $planerrorcode = 'Input Correct Recurring Plan Code';
            if ($recur == 'plan') {
                if ($recurplan == '' || $recurplan == null) {
                    $showbtn = false;
                } else {
                    $plan = spg_wp_siliconpay_fetch_plan($recurplan);
                    if (isset($plan->data->amount)) {
                        $planamount = $plan->data->amount;
                    } else {
                        $showbtn = false;
                    }
                }
            }
            $useinventory = get_post_meta($id, '_useinventory', true);
            $inventory = get_post_meta($id, '_inventory', true);
            $sold = get_post_meta($id, '_sold', true);
            if ($inventory == "") {
                $inventory = '1';
            }
            if ($sold == "") {
                $sold = '0';
            }
            if ($useinventory == "") {
                $useinventory = "no";
            }
            $stock = $inventory - $sold;

            if ($useinventory == "yes" && $stock <= 0) {
                echo "<h1>Out of Stock</h1>";
            } else if ((($user_id != 0) && ($loggedin == 'yes')) || $loggedin == 'no') {
                echo '<div id="siliconpay-form">';
                if ($hidetitle != 1) {
                    echo "<h1 id='pf-form" . $id . "'>" . $obj->post_title . "</h1>";
                }
                echo '<form version="' . SPG_WP_SILICONPAY_VERSION . '" enctype="multipart/form-data" action="' . admin_url('admin-ajax.php') . '" url="' . admin_url() . '" method="post" class="siliconpay-form j-forms" novalidate>
				 <div class="j-row">';
                echo '<input type="hidden" name="action" value="spg_wp_siliconpay_submit_action">';
                echo '<input type="hidden" name="pf-id" value="' . $id . '" />';
                echo '<input type="hidden" name="pf-user_id" value="' . $user_id . '" />';
                echo '<input type="hidden" name="pf-recur" value="' . $recur . '" />';
                echo '<input type="hidden" name="pf-currency" id="pf-currency" value="' . $currency . '" />';
                $feeSettings = Spg_WP_SiliconPay_Public::fetchFeeSettings();
                echo '<script>window.SPG_SILICONPAY_CHARGE_SETTINGS={
                    percentage:' . $feeSettings['prc'] . ',
                    additional_charge:' . $feeSettings['adc'] . ',
                    threshold:' . $feeSettings['ths'] . ',
                    cap:' . $feeSettings['cap'] . '
                }</script>';
                echo '<div class="span12 unit">
				 <label class="label">Full Name <span>*</span></label>
				 <div class="input">
					 <input type="text" name="pf-fname" placeholder="First & Last Name" value="' . $fullname . '"
					 ';

                echo ' required>
				 </div>
			     </div>';
                echo '<div class="span12 unit">
				 <label class="label">Email <span>*</span></label>
				 <div class="input">
					 <input type="email" name="pf-pemail" placeholder="Enter Email Address"  id="pf-email" value="' . $email . '"
					 ';
                if ($loggedin == 'yes') {
                    echo 'readonly ';
                }
                echo ' required>
				 </div>
                 </div>';

                echo '<div class="span12 unit">
				 <label class="label">Phone Number <span>*</span></label>
				 <div class="input">
					 <input type="text" name="pf-phone" placeholder="Enter Phone Number e.g 256xxxxxxxxx"  id="pf-phone" value=""
					 ';
                echo ' required>
				 </div>
                 </div>';

                echo '<div class="span12 unit">
                         <label class="label">Payment Method <span>*</span></label>
                         <div class="input">
                            <select  class="form-control" id="pf-method" name="pf-method">
                                <option value="Mobile Money" selected>Mobile Money</option>
                                <option value="Credit/Debit Card">Credit/Debit Card</option>
                                <option value="MPESA">MPESA</option>
                            </select>                   
                         </div>
                    </div>';


                echo '<div class="span12 unit">
				 <label class="label">Amount (' . $currency;
                if ($minimum == 0 && $amount != 0 && $usequantity == 'yes') {
                    echo ' ' . number_format($amount);
                }


                echo ') <span>*</span></label>
				 <div class="input">';
                if ($usevariableamount == 0) {
                    if ($minimum == 1) {
                        echo '<small> Minimum payable amount <b style="font-size:87% !important;">' . $currency . '  ' . number_format($amount) . '</b></small>';
                        //make it available for javascript so we can test against the input value
                        echo '<input type="hidden" name="pf-minimum-hidden" value="' . number_format($amount) . '" id="pf-minimum-hidden">';
                    }
                    if ($recur == 'plan') {
                        if ($showbtn) {
                            echo '<input type="text" name="pf-amount" value="' . $planamount . '" id="pf-amount" readonly required/>';
                        } else {
                            echo '<div class="span12 unit">
                                    <label class="label" style="font-size:18px;font-weight:600;line-height: 20px;">' . $planerrorcode . '</label>
                                </div>';
                        }
                    } elseif ($recur == 'optional') {
                        echo '<input type="text" name="pf-amount" class="pf-number" id="pf-amount" value="' . $amount . '" required/>';
                    } else {
                        if ($amount == 0) {
                            echo '<input type="text" name="pf-amount" class="pf-number" value="0" id="pf-amount" required/>';
                        } elseif ($amount != 0 && $minimum == 1) {
                            echo '<input type="text" name="pf-amount" value="' . $amount . '" id="pf-amount" required/>';
                        } else {
                            echo '<input type="text" name="pf-amount" value="' . $amount . '" id="pf-amount" readonly required/>';
                        }
                    }
                } else {
                    if ($usevariableamount == "") {
                        echo "Form Error, set variable amount string";
                    } else {
                        if (count($paymentoptions) > 0) {
                            echo '<div class="select">
			 				 	 	<input type="hidden"  id="pf-vname" name="pf-vname" />
			 				 	 	<input type="hidden"  id="pf-amount" />
 									<select class="form-control" id="pf-vamount" name="pf-amount">';
                            $max = $quantity + 1;
                            if ($max > ($stock + 1)) {
                                $max = $stock + 1;
                            }
                            foreach ($paymentoptions as $key => $paymentoption) {
                                list($a, $b) = explode(':', $paymentoption);
                                echo '<option value="' . $b . '" data-name="' . $a . '">' . $a . ' - ' . $currency . ' ' . number_format($b) . '</option>';
                            }
                            echo '</select> <i></i> </div>';
                        }
                    }
                }
                if ($txncharge != 'merchant' && $recur != 'plan' && $usequantity !== "yes") {
                    echo '<small>Transaction Charge: <b class="pf-txncharge"></b>, Total: <b  class="pf-txntotal"></b></small>';
                }

                echo '<span id="pf-min-val-warn" style="color: red; font-size: 13px;"></span> 
				</div>
			 </div>';
                if ($recur == 'no' && $usequantity == 'yes') {
                    echo
                        '<div class="span12 unit">
                        <label class="label">' . $quantityunit . '</label>
                        <div class="select">
                            <input type="hidden" value="' . $amount . '" id="pf-qamount"/>
                            <select class="form-control" id="pf-quantity" name="pf-quantity" >';
                    $max = $quantity + 1;

                    if ($max > ($stock + 1) && $useinventory == 'yes') {
                        $max = $stock + 1;
                    }
                    for ($i = 1; $i < $max; $i++) {
                        echo ' <option value="' . $i . '">' . $i . '</option>';
                    }
                    echo '</select>
                            <i></i>
                        </div>
                    </div>
                    <div class="span12 unit">
                        <label class="label">Total (' . $currency;
                    echo ') <span>*</span></label>
                        <div class="input">
                            <input type="text" id="pf-total" name="pf-total" placeholder="" value="" disabled>';
                    if ($txncharge != 'merchant' && $recur != 'plan') {
                        echo '<small>Transaction Charge: <b class="pf-txncharge"></b>, Total: <b  class="pf-txntotal"></b></small>';
                    }
                    echo '</div>
                    </div>';
                }

                if ($recur == 'optional') {
                    echo '<div class="span12 unit">
			 				 <label class="label">Recurring Payment</label>
			 				 <div class="select">
			 					 <select class="form-control" name="pf-interval" >
			 						 <option value="no">None</option>
			 						 <option value="daily">Daily</option>
			 						 <option value="weekly">Weekly</option>
                                     <option value="monthly">Monthly</option>
                                     <option value="biannually">Biannually</option>
			 						 <option value="annually">Annually</option>
			 					 </select>
			 					 <i></i>
			 				 </div>
			 			 </div>';
                } elseif ($recur == 'plan') {
                    if ($showbtn) {
                        echo '<input type="hidden" name="pf-plancode" value="' . $recurplan . '" />';
                        echo '<div class="span12 unit">
									<label class="label" style="font-size:18px;font-weight:600;line-height: 20px;">' . $plan->data->name . ' ' . $plan->data->interval . ' recuring payment - ' . $plan->data->currency . ' ' . number_format($planamount) . '</label>
								</div>';
                    } else {
                        echo '<div class="span12 unit">
								 <label class="label" style="font-size:18px;font-weight:600;line-height: 20px;">' . $planerrorcode . '</label>
							 </div>';
                    }
                }


                echo(do_shortcode($obj->post_content));

                if ($useagreement == 'yes') {
                    echo '<div class="span12 unit">
						<label class="checkbox ">
							<input type="checkbox" name="agreement" id="pf-agreement" required value="yes">
							<i id="pf-agreementicon" ></i>
							Accept terms <a target="_blank" href="' . $agreementlink . '">Link </a>
						</label>
					</div><br>';
                }
                echo '<div class="span12 unit">
						<small><span style="color: red;">*</span> are compulsory</small><br />
						<img src="' . plugins_url('../images/logos@2x.png', __FILE__) . '" alt="cardlogos"  class="siliconpay-cardlogos size-full wp-image-1096" />

							<button type="reset" class="secondary-btn">Reset</button>';
                if ($showbtn) {
                    echo '<button type="submit" class="primary-btn">' . $paybtn . '</button>';
                }
                echo '</div>';

                echo '</div>
            </form>';
                echo '<div id="SPModal" class="silicon-pay-modal">
                        <div class="silicon-pay-modal-content">
                        <div class="silicon-pay-modal-header">
                            <span class="close">&times;</span>
                            <h4 id="silicon-pay-title">Payment Status:</h4>
                        </div>
                        <div class="silicon-pay-modal-body">
                            <p id="silicon-pay-main-text">Some text in the Modal Body</p>
                            <p id="silicon-pay-main-text-2">Some other text...</p>
                        </div>
                        <div class="silicon-pay-modal-footer">
                            <h6 class="silicon-powered">Powered by Silicon Pay</h6>
                        </div>
                    </div>
              </div>';
                echo '</div>';
            } else {
                echo "<h5>You must be logged in to make payment</h5>";
            }
        }
    }


    return ob_get_clean();
}

add_shortcode('spg-siliconpay', 'spg_wp_siliconpay_form_shortcode');


function spg_wp_siliconpay_datepicker_shortcode($atts)
{
    extract(
        shortcode_atts(
            array(
                'name' => 'Title',
                'required' => '0',
            ),
            $atts
        )
    );
    $code = '<div class="span12 unit">
		<label class="label">' . $name;
    if ($required == 'required') {
        $code .= ' <span>*</span>';
    }
    $code .= '</label>
		<div class="input">
			<input type="text" class="date-picker" name="' . $name . '" placeholder="Enter ' . $name . '"';
    if ($required == 'required') {
        $code .= ' required="required" ';
    }
    $code .= '" /></div></div>';
    return $code;
}

add_shortcode('datepicker', 'spg_wp_siliconpay_datepicker_shortcode');


function spg_wp_siliconpay_text_shortcode($atts)
{
    extract(
        shortcode_atts(
            array(
                'name' => 'Title',
                'required' => '0',
            ),
            $atts
        )
    );
    $code = '<div class="span12 unit">
		<label class="label">' . $name;
    if ($required == 'required') {
        $code .= ' <span>*</span>';
    }
    $code .= '</label>
		<div class="input">
			<input type="text" name="' . $name . '" placeholder="Enter ' . $name . '"';
    if ($required == 'required') {
        $code .= ' required="required" ';
    }
    $code .= '" /></div></div>';
    return $code;
}

add_shortcode('text', 'spg_wp_siliconpay_text_shortcode');
function spg_wp_siliconpay_select_shortcode($atts)
{
    extract(
        shortcode_atts(
            array(
                'name' => 'Title',
                'options' => '',
                'required' => '0',
            ),
            $atts
        )
    );
    $code = '<div class="span12 unit">
		<label class="label">' . $name;
    if ($required == 'required') {
        $code .= ' <span>*</span>';
    }
    $code .= '</label>
		<div class="input">
			<select class="form-control"  name="' . $name . '"';
    if ($required == 'required') {
        $code .= ' required="required" ';
    }
    $code .= ">";

    $soptions = explode(',', $options);
    if (count($soptions) > 0) {
        foreach ($soptions as $key => $option) {
            $code .= '<option  value="' . $option . '" >' . $option . '</option>';
        }
    }
    $code .= '" </select><i></i></div></div>';
    return $code;
}

add_shortcode('select', 'spg_wp_siliconpay_select_shortcode');
function spg_wp_siliconpay_radio_shortcode($atts)
{
    extract(
        shortcode_atts(
            array(
                'name' => 'Title',
                'options' => '',
                'required' => '0',
            ),
            $atts
        )
    );
    $code = '<div class="span12 unit">
		<label class="label">' . $name;
    if ($required == 'required') {
        $code .= ' <span>*</span>';
    }
    $code .= '</label>
		<div class="inline-group">
		';
    $soptions = explode(',', $options);
    if (count($soptions) > 0) {
        foreach ($soptions as $key => $option) {
            $code .= '<label class="radio">
				<input type="radio" name="' . $name . '" value="' . $option . '"';
            if ($key == 0) {
                $code .= ' checked';
                if ($required == 'required') {
                    $code .= ' required="required"';
                }
            }

            $code .= '/>
				<i></i>
				' . $option . '
			</label>';
        }
    }
    $code .= '</div></div>';
    return $code;
}

add_shortcode('radio', 'spg_wp_siliconpay_radio_shortcode');
function spg_wp_siliconpay_checkbox_shortcode($atts)
{
    extract(
        shortcode_atts(
            array(
                'name' => 'Title',
                'options' => '',
                'required' => '0',
            ),
            $atts
        )
    );
    $code = '<div class="span12 unit">
		<label class="label">' . $name;
    if ($required == 'required') {
        $code .= ' <span>*</span>';
    }
    $code .= '</label>
		<div class="inline-group">
		';

    $soptions = explode(',', $options);
    if (count($soptions) > 0) {
        foreach ($soptions as $key => $option) {
            $code .= '<label class="checkbox">
				<input type="checkbox" name="' . $name . '[]" value="' . $option . '"';
            if ($key == 0) {
                $code .= ' checked';
                if ($required == 'required') {
                    $code .= ' required="required"';
                }
            }

            $code .= '/>
				<i></i>
				' . $option . '
			</label>';
        }
    }
    $code .= '</div></div>';
    return $code;
}

add_shortcode('checkbox', 'spg_wp_siliconpay_checkbox_shortcode');
function spg_wp_siliconpay_textarea_shortcode($atts)
{
    extract(
        shortcode_atts(
            array(
                'name' => 'Title',
                'required' => '0',
            ),
            $atts
        )
    );
    $code = '<div class="span12 unit">
		<label class="label">' . $name;
    if ($required == 'required') {
        $code .= ' <span>*</span>';
    }
    $code .= '</label>
		<div class="input">
			<textarea type="text" name="' . $name . '" rows="3" placeholder="Enter ' . $name . '"';
    if ($required == 'required') {
        $code .= ' required="required" ';
    }
    $code .= '" ></textarea></div></div>';
    return $code;
}

add_shortcode('textarea', 'spg_wp_siliconpay_textarea_shortcode');
function spg_wp_siliconpay_input_shortcode($atts)
{
    extract(
        shortcode_atts(
            array(
                'name' => 'Title',
                'required' => '0',
            ),
            $atts
        )
    );

    $uniqq = spg_wp_siliconpay_generate_new_code();
    $code = '<div class="span12 unit">
		<label class="label">' . $name;
    if ($required == 'required') {
        $code .= ' <span>*</span>';
    }
    $code .= '</label>
		<div class="input  append-small-btn">
		<div class="file-button">
			Browse
			<input type="file" name="' . $name . '" onchange="document.getElementById(\'append-small-btn-' . $uniqq . '\').value = this.value;"';
    if ($required == 'required') {
        $code .= ' required="required" ';
    }
    $code .= '" /></div>
		<input type="text" id="append-small-btn-' . $uniqq . '" readonly="" placeholder="no file selected">
	</div></div>';
    return $code;
}

add_shortcode('input', 'spg_wp_siliconpay_input_shortcode');

// Save the Metabox Data
function spg_wp_siliconpay_generate_new_code($length = 10)
{
    $characters = '06EFGHI9KL' . time() . 'MNOPJRSUVW01YZ923234' . time() . 'ABCD5678QXT';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return time() . "_" . $randomString;
}

function spg_wp_siliconpay_check_code($code)
{
    global $wpdb;
    $table = $wpdb->prefix . SPG_WP_SILICONPAY_TABLE;
    $o_exist = $wpdb->get_results("SELECT * FROM $table WHERE txn_code = '" . $code . "'");

    if (count($o_exist) > 0) {
        $result = true;
    } else {
        $result = false;
    }

    return $result;
}

function spg_wp_siliconpay_generate_code()
{
    $code = 0;
    $check = true;
    while ($check) {
        $code = spg_wp_siliconpay_generate_new_code();
        $check = spg_wp_siliconpay_check_code($code);
    }

    return $code;
}

function spg_wp_siliconpay_get_the_user_ip()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

add_action('wp_ajax_spg_wp_siliconpay_submit_action', 'spg_wp_siliconpay_submit_action');
add_action('wp_ajax_nopriv_spg_wp_siliconpay_submit_action', 'spg_wp_siliconpay_submit_action');
function spg_wp_siliconpay_submit_action()
{
    if (trim($_POST['pf-pemail']) == '') {
        $response['result'] = 'failed';
        $response['message'] = 'Email is required';

        // Exit here, for not processing further because of the error
        exit(json_encode($response));
    }

    if (trim($_POST['pf-phone']) == '') {
        $response['result'] = 'failed';
        $response['message'] = 'Phone Number is required';

        // Exit here, for not processing further because of the error
        exit(json_encode($response));
    }

    // Hookable location. Allows other plugins use a fresh submission before it is saved to the database.
    // Such a plugin only needs do
    // add_action( 'spg_wp_siliconpay_before_save', 'function_to_use_posted_values' );
    // somewhere in their code;
    do_action('spg_wp_siliconpay_before_save');

    global $wpdb;
    $code = spg_wp_siliconpay_generate_code();

    $table = $wpdb->prefix . SPG_WP_SILICONPAY_TABLE;
    $metadata = sanitize_post($_POST);
    $fullname = sanitize_text_field($_POST['pf-fname']);
    $recur = sanitize_text_field($_POST['pf-recur']);
    unset($metadata['action']);
    unset($metadata['pf-recur']);
    unset($metadata['pf-id']);
    unset($metadata['pf-pemail']);
    unset($metadata['pf-amount']);
    unset($metadata['pf-user_id']);
    unset($metadata['pf-interval']);

    // echo '<pre>';
    // print_r($_POST);

    $untouchedmetadata = spg_wp_siliconpay_meta_as_custom_fields($metadata);
    $fixedmetadata = [];
    // print_r($fixedmetadata );
    $filelimit = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_filelimit', true);
    $currency = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_currency', true);
    $formamount = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_amount', true); /// From form
    $recur = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_recur', true);
    $subaccount = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_subaccount', true);
    $txnbearer = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_txnbearer', true);
    $transaction_charge = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_merchantamount', true);
    $transaction_charge = $transaction_charge;

    $txncharge = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_txncharge', true);
    $minimum = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_minimum', true);
    $variableamount = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_variableamount', true);
    $usevariableamount = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_usevariableamount', true);
    $amount = (int)str_replace(' ', '', $_POST["pf-amount"]);
    $variablename = isset($_POST["pf-vname"]) ? sanitize_text_field($_POST["pf-vname"]) : '';
    $originalamount = $amount;
    $quantity = 1;
    $usequantity = get_post_meta(sanitize_text_field($_POST["pf-id"]), '_usequantity', true);

    if (($recur == 'no') && ($formamount != 0)) {
        $amount = (int)str_replace(' ', '', $formamount);
    }
    if ($minimum == 1 && $formamount != 0) {
        if ($originalamount < $formamount) {
            $amount = $formamount;
        } else {
            $amount = $originalamount;
        }
    }
    if ($usevariableamount == 1) {
        $paymentoptions = explode(',', $variableamount);
        if (count($paymentoptions) > 0) {
            foreach ($paymentoptions as $key => $paymentoption) {
                list($a, $b) = explode(':', $paymentoption);
                if ($variablename == $a) {
                    $amount = $b;
                }
            }
        }
    }
    $fixedmetadata[] = array(
        'display_name' => 'Unit Price',
        'variable_name' => 'Unit_Price',
        'type' => 'text',
        'value' => $currency . number_format($amount)
    );
    if ($usequantity === 'yes' && !(($recur === 'optional') || ($recur === 'plan'))) {
        $quantity = sanitize_text_field($_POST["pf-quantity"]);
        $unitamount = (int)str_replace(' ', '', $amount);
        $amount = $quantity * $unitamount;
    }
    //--------------------------------------

    //--------------------------------------
    if ($txncharge == 'customer') {
        $amount = spg_wp_siliconpay_add_siliconpay_charge($amount);
    }
    $maxFileSize = $filelimit * 1024 * 1024;

    if (!empty($_FILES)) {
        foreach ($_FILES as $keyname => $value) {
            if ($value['size'] > 0) {
                if ($value['size'] > $maxFileSize) {
                    $response['result'] = 'failed';
                    $response['message'] = 'Max upload size is ' . $filelimit . "MB";
                    exit(json_encode($response));
                } else {
                    $attachment_id = media_handle_upload($keyname, $_POST["pf-id"]);
                    $url = wp_get_attachment_url($attachment_id);
                    $fixedmetadata[] = array(
                        'display_name' => ucwords(str_replace("_", " ", $keyname)),
                        'variable_name' => $keyname,
                        'type' => 'link',
                        'value' => $url
                    );
                }
            } else {
                $fixedmetadata[] = array(
                    'display_name' => ucwords(str_replace("_", " ", $keyname)),
                    'variable_name' => $keyname,
                    'type' => 'text',
                    'value' => 'No file Uploaded'
                );
            }
        }
    }
    $plancode = 'none';


    $fixedmetadata = json_decode(json_encode($fixedmetadata, JSON_NUMERIC_CHECK), true);
    $fixedmetadata = array_merge($untouchedmetadata, $fixedmetadata);

    $insert = array(
        'post_id' => sanitize_text_field($_POST["pf-id"], ""),
        'email' => sanitize_text_field($_POST["pf-pemail"], ""),
        'phone' => sanitize_text_field($_POST["pf-phone"], ""),
        'method' => sanitize_text_field($_POST["pf-method"], ""),
        'user_id' => sanitize_text_field($_POST["pf-user_id"], ""),
        'amount' => sanitize_text_field($amount, ""),
        'plan' => sanitize_text_field($plancode, ""),
        'ip' => spg_wp_siliconpay_get_the_user_ip(),
        'txn_code' => $code,
        'metadata' => json_encode($fixedmetadata)
    );
    $exist = $wpdb->get_results(
        "SELECT * FROM $table WHERE (post_id = '" . $insert['post_id'] . "'
			AND email = '" . $insert['email'] . "'
			AND phone = '" . $insert['phone'] . "'
			AND method = '" . $insert['method'] . "'
			AND user_id = '" . $insert['user_id'] . "'
			AND amount = '" . $insert['amount'] . "'
			AND plan = '" . $insert['plan'] . "'
			AND ip = '" . $insert['ip'] . "'
			AND paid = '0'
			AND metadata = '" . $insert['metadata'] . "')"
    );
    if (count($exist) > 0) {
        // $insert['txn_code'] = $code;
        // $insert['plan'] = $exist[0]->plan;
        $wpdb->update($table, array('txn_code' => $code, 'plan' => $insert['plan']), array('id' => $exist[0]->id));
    } else {
        $wpdb->insert(
            $table,
            $insert
        );
        if ("yes" == get_post_meta($insert['post_id'], '_sendinvoice', true)) {
            spg_wp_siliconpay_send_invoice($currency, $insert['amount'], $fullname, $insert['email'], $code);
        }
    }
    if ($subaccount == "" || !isset($subaccount)) {
        $subaccount = null;
        $txnbearer = null;
        $transaction_charge = null;
    }
    if ($transaction_charge == "" || $transaction_charge == 0 || $transaction_charge == null) {
        $transaction_charge = null;
    }

    $amount = floatval($insert['amount']);
    $response = array(
        'result' => 'success',
        'code' => $insert['txn_code'],
        'plan' => $insert['plan'],
        'quantity' => $quantity,
        'email' => $insert['email'],
        'phone' => $insert['phone'],
        'method' => $insert['method'],
        'name' => $fullname,
        'total' => round($amount),
        'currency' => $currency,
        'custom_fields' => $fixedmetadata,
        'subaccount' => $subaccount,
        'txnbearer' => $txnbearer,
        'transaction_charge' => $transaction_charge
    );
    echo json_encode($response);
    die();
}

function spg_wp_siliconpay_meta_as_custom_fields($metadata)
{
    $custom_fields = array();
    foreach ($metadata as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        if ($key == 'pf-fname') {
            $custom_fields[] = array(
                'display_name' => 'Full Name',
                'variable_name' => 'Full_Name',
                'type' => 'text',
                'value' => $value
            );
        } elseif ($key == 'pf-plancode') {
            $custom_fields[] = array(
                'display_name' => 'Plan',
                'variable_name' => 'Plan',
                'type' => 'text',
                'value' => $value
            );
        } elseif ($key == 'pf-phone') {
            $custom_fields[] = array(
                'display_name' => 'Phone Number',
                'variable_name' => 'Phone_Number',
                'type' => 'text',
                'value' => $value
            );
        } elseif ($key == 'pf-vname') {
            $custom_fields[] = array(
                'display_name' => 'Payment Option',
                'variable_name' => 'Payment Option',
                'type' => 'text',
                'value' => $value
            );
        } elseif ($key == 'pf-interval') {
            $custom_fields[] = array(
                'display_name' => 'Plan Interval',
                'variable_name' => 'Plan Interval',
                'type' => 'text',
                'value' => $value
            );
        } elseif ($key == 'pf-quantity') {
            $custom_fields[] = array(
                'display_name' => 'Quantity',
                'variable_name' => 'Quantity',
                'type' => 'text',
                'value' => $value
            );
        } else {
            $custom_fields[] = array(
                'display_name' => ucwords(str_replace("_", " ", $key)),
                'variable_name' => $key,
                'type' => 'text',
                'value' => (string)$value
            );
        }
    }
    return $custom_fields;
}

add_action('wp_ajax_spg_wp_siliconpay_confirm_payment', 'spg_wp_siliconpay_confirm_payment');
add_action('wp_ajax_nopriv_spg_wp_siliconpay_confirm_payment', 'spg_wp_siliconpay_confirm_payment');

function spg_wp_siliconpay_confirm_payment()
{
    if (trim($_POST['code']) == '') {
        $response['error'] = true;
        $response['error_message'] = "Did you make a payment?";

        exit(json_encode($response));
    }

    global $wpdb;
    $table = $wpdb->prefix . SPG_WP_SILICONPAY_TABLE;
    $code = sanitize_text_field($_POST['code']);

    $record = $wpdb->get_results("SELECT * FROM $table WHERE (txn_code = '" . $code . "')");

//    $record = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE txn_code=%d", $code));

    if (array_key_exists("0", $record)) {
        $payment_array = $record[0];
        $amount = get_post_meta($payment_array->post_id, '_amount', true);
        $recur = get_post_meta($payment_array->post_id, '_recur', true);
        $currency = get_post_meta($payment_array->post_id, '_currency', true);
        $txncharge = get_post_meta($payment_array->post_id, '_txncharge', true);
        $redirect = get_post_meta($payment_array->post_id, '_redirect', true);
        $minimum = get_post_meta($payment_array->post_id, '_minimum', true);
        $usevariableamount = get_post_meta($payment_array->post_id, '_usevariableamount', true);
        $variableamount = get_post_meta($payment_array->post_id, '_variableamount', true);

        if ($minimum == 1 && $amount != 0) {
            if ($payment_array->amount < $formamount) {
                $amount = $formamount;
            } else {
                $amount = $payment_array->amount;
            }
        }
        $oamount = $amount;
        $mode = esc_attr(get_option('mode'));
        if ($mode == 'test') {
            $key = esc_attr(get_option('lsk'));
        } else {
            $key = esc_attr(get_option('lsk'));
        };


        if ($_POST['status_code'] === '200') {
            ;

            if (trim($_POST['status']) === 'Successful' || trim($_POST['status']) === '200') {
                //=============================================================

                $usequantity = get_post_meta($payment_array->post_id, '_usequantity', true);
                if ($usequantity = "yes") {
                    $quantity = sanitize_text_field($_POST["quantity"]);
                    $sold = get_post_meta($payment_array->post_id, '_sold', true);
                    // error_log(print_r("sold", TRUE)); 
                    // error_log(print_r($sold, TRUE)); 
                    // error_log(print_r(" -  -  - -- - --  - -  --  - ", TRUE));
                    // error_log(print_r("Qty", TRUE));  
                    // error_log(print_r($quantity, TRUE)); 
                    if ($sold == '') {
                        $sold = '0';
                    }
                    $sold = $sold + $quantity;
                }


                if (get_post_meta($payment_array->post_id, '_sold', false)) {

                    update_post_meta($payment_array->post_id, '_sold', $sold);
                } else {
                    add_post_meta($payment_array->post_id, '_sold', $sold);
                }
                //=============================================================
                $customer_code = trim($_POST['customer_code']);
                $amount_paid = trim($_POST['amount']);
                $siliconpay_ref = trim($_POST['reference']);
                $paid_at = date("Y-m-d H:i:s");;


                if ($recur == 'optional' || $recur == 'plan') {
                    $wpdb->update($table, array('paid' => 1, 'amount' => $amount_paid, 'paid_at' => $paid_at), array('txn_code' => $siliconpay_ref));
                    $thankyou = get_post_meta($payment_array->post_id, '_successmsg', true);
                    $message = $thankyou;
                    $result = "success";
                } else {
                    if ($amount == 0 || $usevariableamount == 1) {
                        $wpdb->update($table, array('paid' => 1, 'amount' => $amount_paid, 'paid_at' => $paid_at), array('txn_code' => $siliconpay_ref));
                        $thankyou = get_post_meta($payment_array->post_id, '_successmsg', true);
                        $message = $thankyou;
                        $result = "success";
                        // spg_wp_siliconpay_send_receipt($currency,$amount,$name,$payment_array->email,$code,$metadata)
                    } else {
                        $usequantity = get_post_meta($payment_array->post_id, '_usequantity', true);
                        if ($usequantity == 'no') {
                            $oamount = (int)str_replace(' ', '', $amount);
                        } else {
                            $quantity = $_POST["quantity"];
                            $unitamount = (int)str_replace(' ', '', $amount);
                            $oamount = $quantity * $unitamount;
                        }
                        if ($txncharge == 'customer') {
                            if ($minimum == 0 && $amount != 0) {
                                $oamount = spg_wp_siliconpay_add_siliconpay_charge($oamount);
                            }
                        }

                        if ($oamount != $amount_paid) {
                            $message = "Invalid amount Paid. Amount required is " . $currency . "<b>" . number_format($oamount) . "</b>";
                            $result = "failed";
                        } else {
                            $wpdb->update($table, array('paid' => 1, 'paid_at' => $paid_at), array('txn_code' => $siliconpay_ref));
                            $thankyou = get_post_meta($payment_array->post_id, '_successmsg', true);
                            $message = $thankyou;
                            $result = "success";
                        }
                    }
                }
            } else {
                $message = "Transaction Failed/Invalid Code";
                $result = "failed";
            }
        } else {
            $message = "Payment Initiation on SILICON SAVANNAH Failed";
            $result = "failed";
        }
    } else {
        $message = "Uncaught Error: Your Payment initiation Failed.";
        $result = "failed";
    }

    //Stop
    if ($result == 'success') {
        $pstk_logger = new spg_wp_siliconpay_plugin_tracker('spg-siliconpay', Spg_WP_SiliconPay_Public::fetchEncryptionKey());
        $pstk_logger->log_transaction_success($code);
        $enabled_custom_plan = get_post_meta($payment_array->post_id, '_startdate_enabled', true);
        $sendreceipt = get_post_meta($payment_array->post_id, '_sendreceipt', true);
        if ($sendreceipt == 'yes') {
            $decoded = json_decode($payment_array->metadata);
            $fullname = $decoded[1]->value;
            spg_wp_siliconpay_send_receipt($payment_array->post_id, $currency, $amount_paid, $fullname, $payment_array->email, $siliconpay_ref, $payment_array->metadata);
            spg_wp_siliconpay_send_receipt_owner($payment_array->post_id, $currency, $amount_paid, $fullname, $payment_array->email, $siliconpay_ref, $payment_array->metadata);
        }
    }
    $credit_card_message = trim($_POST['payment_link']) !== 'null' ? $message . " You would be redirected in 10 seconds to a GTBank page to complete your card Transaction." : $message;

    $response = array(
        'result' => $result,
        'message' => $credit_card_message,
        'title' => trim($_POST['title']),
        'payment_link' => trim($_POST['payment_link']) !== 'null' ? $_POST['payment_link'] : null,
    );

    if ($result == 'success' && $redirect != '') {
        $response['result'] = 'success2';
        $response['link'] = $redirect;
    }


    echo json_encode($response);

    die();
}

