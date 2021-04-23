'use_strict'

var counter = liquidpay_params.pay_timeout;
var countPost = 0;

function liquidpayTransactionIDField() {
    var html = "";
    if (("show_require" == liquidpay_params.transaction_id && (html = '<span class="liquidpay-required">*</span>'), "hide" != liquidpay_params.transaction_id)) {
        var e = "liquidpay-" + liquidpay_params.app_theme + "-input";
        return (
            '<form id="liquidpay-form"><div class="liquidpay-form-group"><label for="liquidpay-transaction-number"><strong>Enter 12-digit Transaction ID:</strong> ' +
            html +
            '</label><div class="liquidpay-clear-area"></div><input type="text" id="liquidpay-transaction-number" class="' +
            e +
            '" placeholder="001422121258" maxlength="12" style="width: 70%;" onkeypress="return isNumber(event)" /></div><div class="liquidpay-clear-area"></div></div>'
        );
    }
    return "";
}

retrieveBill = (json_data) => {
    const bill_status = json_data.bill_status || ''
    
    if (bill_status === "A") {
        window.onbeforeunload = null
        document.getElementById("payment-success-container").innerHTML =
            "<form method='POST' action='" +
                liquidpay_params.callback_url +
                "' id='LIQUIDPAYJSCheckoutForm' style='display: none;'><input type='hidden' name='wc_order_id' value='" +
                liquidpay_params.orderid +
                "'><input type='hidden' name='wc_order_key' value='" +
                liquidpay_params.order_key +
                "'><input type='hidden' name='wc_transaction_id' value='" +
                json_data.id +
                "'></form>";
        console.log('proccesing')
        document.getElementById("LIQUIDPAYJSCheckoutForm").submit();
        
    } else if(bill_status != "P" && bill_status != "W") {
        //document.getElementById("liquidpay-cancel-payment").click();
    }
}

function isNumber(event) {
    event = event || window.event;
    var e = event.which ? event.which : event.keyCode;
    return !(8 != e && 0 != e && e > 31 && (e < 48 || e > 57));
}

(function ($) {
    if ("undefined" == typeof liquidpay_params) return !1;

    1 == liquidpay_params.prevent_reload &&
        (window.onbeforeunload = function () {
            return "Are you sure you want to leave?";
        });
    var e = $("#data-qr-code"),
        //text = encodeURI(e.data("link"));
        text = e.data("link");
        const config = JSON.parse(liquidpay_params.qr_config)
    $("#liquidpay-qrcode").length &&
        new QRCode("liquidpay-qrcode", {
            text,
            width: e.data('width'), // Widht
            height: e.data('height'), // Height
            quietZone:6,
            logo: liquidpay_params.imageLogo, // LOGO
            logoBackgroundColor: '#ffffff',
            logoBackgroundTransparent: false,
            logoWidth:80,
            logoHeight:80,
            correctLevel: QRCode.CorrectLevel.Q,
            dotScale: 1,
            onRenderingEnd:function(options, dataURL){
                $("#liquidpay-confirm-payment").trigger("click");
            }
        }),
        
        $("#liquidpay-cancel-payment").click(function (e) {
            (window.onbeforeunload = null), (window.location.href = liquidpay_params.cancel_url), e.preventDefault();
        }),
        setInterval((e) => {
            if(document.getElementById("data-ref") === null ) return ;
            /*var bill_ref_no = document.getElementById("data-ref").value;
            var url = woocommerce_params.ajax_url;*/
            //console.log("Retrieve Bill Status");
            
            
            counter--;
            console.log("Retrieve Bill Status", counter);
            if (counter <= 0) {
                document.getElementById("spnBillStatus").textContent = "Timeout"; document.getElementById("liquidpay-qrcode").textContent = "";
                if (counter <= 0) {
                    (window.onbeforeunload = null), (window.location.href = liquidpay_params.cancel_url);
                }
                
            }
            else {
                document.getElementById("spnBillStatus").textContent = "Time remaining:"  +" ("+counter+")";
            }
            
            /*var script = document.createElement("script");

            script.type='text/javascript';

            script.src = url+"?action=status_payment&params="+escape(bill_ref_no);
            
            script.onload = function(){
                document.body.removeChild(script);
            }
            document.body.appendChild(script);*/
            
        }, 1000),
        setInterval((e) => {
            if(document.getElementById("data-ref") === null ) return ;
            var bill_ref_no = document.getElementById("data-ref").value;
            var url = woocommerce_params.ajax_url;

            countPost++;
            console.log("Retrieve Bill POST Status", countPost);

            var script = document.createElement("script");
            script.type='text/javascript';
            script.src = url+"?action=status_payment&params="+escape(bill_ref_no);
            script.onload = function(){
                document.body.removeChild(script);
            }
            document.body.appendChild(script);
        }, 5000),

        $("#liquidpay-confirm-payment").on("click", function (e) {
            $.confirm({
               title: liquidpay_params.title,
                content: $("#js_qrcode").html(),
                useBootstrap: !1,
                theme: liquidpay_params.app_theme,
                animation: "scale",
                type: $("#liquidpay-confirm-payment").data("theme"),
                boxWidth: $("#data-dialog-box").data("pay"),
                draggable: !1,
                offsetBottom: $("#data-dialog-box").data("offset"),
                offsetTop: $("#data-dialog-box").data("offset"),
                onContentReady: function () {
                    var self = this;
                    this.setContentAppend(`<div id="spnBillStatus">Time remaining: (${liquidpay_params.pay_timeout}) </div>`);
                },
                buttons: {
                    /*
                    nextStep: {
                        text: "Proceed to Next Step",
                        btnClass: "btn-" + $("#liquidpay-confirm-payment").data("theme"),
                        keys: ["enter", "shift"],
                        action: function () {
                            return (
                                test.toggle(),
                                $.confirm({
                                    title: '<span class="liquidpay-popup-title">Confirm your Payment!</span>',
                                    content: liquidpayTransactionIDField() + '<div id="liquidpay-confirm-text" class="liquidpay-confirm-text">' + liquidpay_params.confirm_message + "</div>",
                                    useBootstrap: !1,
                                    theme: liquidpay_params.app_theme,
                                    type: $("#liquidpay-confirm-payment").data("theme"),
                                    boxWidth: $("#data-dialog-box").data("redirect"),
                                    draggable: !1,
                                    buttons: {
                                        confirm: {
                                            text: "Confirm",
                                            btnClass: "btn-" + $("#liquidpay-confirm-payment").data("theme"),
                                            action: function () {
                                                var e = this.$content.find("#liquidpay-transaction-number").val();
                                                if ("show_require" == liquidpay_params.transaction_id && ("" == e || 12 != e.length))
                                                    return (
                                                        $.alert({
                                                            title: "Error!",
                                                            content: '<div id="liquidpay-error-text">Please enter a valid Transaction ID and try again.</div>',
                                                            useBootstrap: !1,
                                                            draggable: !1,
                                                            theme: liquidpay_params.app_theme,
                                                            type: "red",
                                                            boxWidth: $("#data-dialog-box").data("redirect"),
                                                        }),
                                                        !1
                                                    );
                                                (window.onbeforeunload = null),
                                                    $("#payment-success-container").html(
                                                        "<form method='POST' action='" +
                                                            liquidpay_params.callback_url +
                                                            "' id='LIQUIDPAYJSCheckoutForm' style='display: none;'><input type='hidden' name='wc_order_id' value='" +
                                                            liquidpay_params.orderid +
                                                            "'><input type='hidden' name='wc_order_key' value='" +
                                                            liquidpay_params.order_key +
                                                            "'><input type='hidden' name='wc_transaction_id' value='" +
                                                            e +
                                                            "'></form>"
                                                    ),
                                                    $.dialog({
                                                        title: '<span class="liquidpay-popup-title">Processing!<span>',
                                                        content: '<div id="liquidpay-confirm-text" class="liquidpay-confirm-text">' + liquidpay_params.processing_text + "</div>",
                                                        useBootstrap: !1,
                                                        draggable: !1,
                                                        theme: liquidpay_params.app_theme,
                                                        type: "blue",
                                                        closeIcon: !1,
                                                        offsetBottom: $("#data-dialog-box").data("offset"),
                                                        offsetTop: $("#data-dialog-box").data("offset"),
                                                        boxWidth: $("#data-dialog-box").data("redirect"),
                                                    }),
                                                    $("#LIQUIDPAYJSCheckoutForm").submit();
                                            },
                                        },
                                        goBack: {
                                            text: "Go Back",
                                            action: function () {
                                                test.toggle();
                                            },
                                        },
                                    },
                                }),
                                !1
                            );
                        },
                    },
                    */
                    close: function () {
                        $("#liquidpay-processing").hide(), $(".liquidpay-buttons").fadeIn("slow"), $(".liquidpay-waiting-text").text("Please click the Pay Now button below to complete the payment against this order.");
                    },
                },
            });
            $("#liquidpay-processing").show(), $(".liquidpay-buttons").hide(), $(".liquidpay-waiting-text").text("Please wait and don't press back or refresh this page while we are processing your payment..."), e.preventDefault();
        });
})(jQuery);
