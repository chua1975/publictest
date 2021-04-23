$(function() {
    var bill_ref_no = document.getElementById("data-ref").value;
    var url = woocommerce_params.ajax_url;
    console.log("Retrieve Bill Status");
   // var script = document.createElement("script");
    //script.type='text/javascript';
   // script.src = url+"?action=status_payment&params="+escape(bill_ref_no);

    $.ajax({
        url: url+"?action=status_payment&params="+escape(bill_ref_no),
    }).done(function(xhr) {
        console.log('success')
    });
});