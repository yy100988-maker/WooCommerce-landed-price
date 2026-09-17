/**
 * Woo Landed Price — 到手价国家切换
 */
(function ($) {
    'use strict';

    $(function () {
        var $box    = $('#wlp-box');
        var $select = $('#wlp-country');
        if (!$box.length || !$select.length) return;

        $select.on('change', function () {
            var country = $(this).val();
            var pid     = $box.data('product-id');
            if (!country || !pid) return;

            $.post(wlpAjax.ajax_url, {
                action:     'wlp_switch_country',
                country:    country,
                product_id: pid,
                nonce:      wlpAjax.nonce,
            }, function (res) {
                if (!res || !res.success) return;
                var d = res.data;
                $('#wlp-price').html(d.price);
                $('#wlp-shipping').html(d.shipping);
                $('#wlp-tax').html(d.tax);
                $('#wlp-total').html(d.total);
                $('#wlp-flag').text(d.flag);
            });
        });
    });
})(jQuery);
