
var cont = true;
jQuery("document").ready(function() {
	jQuery('#batch-response').show();
	jQuery('#spiningImg').hide();
	jQuery('.batch-cancel').hide();
	jQuery('.batch-confirmed').click(function(e) {
		e.preventDefault();
		jQuery('.batch-confirmed').hide();
		jQuery('.batch-cancel').show();
		jQuery("#batch-cancel").prop( "disabled", false ); // enable cancle button.
		// start the process
		var data = {
				action: 'process_batch',
				state: 'CONT'
			}
		if(cont){	
			send_Request(data);
		}
	});
	
})

jQuery("document").ready(function() {
	jQuery('#batch-response').show();
	jQuery('.batch-cancel').click(function(e) {
		e.preventDefault();
		jQuery( "#batch_response" ).append("<h4 style='color:red;'> User initiated Cancel Request! </h4>");
		jQuery('#batch-cancel').prop( "disabled", true ); // disable the cancle button.
		cont = false;
		// start the process
		var data = {
				action: 'process_batch',
				state: 'CANC'
			}
		send_Cancel(data);
	});
})

function send_Request(jdata) {
	if(cont){
		jQuery.ajax(ajaxurl, {
		type: 'POST',
		url: ajaxurl,
		data: jdata,
		timeout: 100000,
		dataType: "json",
		beforeSend: function() {
		  jQuery('#spiningImg').show();    /*showing  a div with spinning image */
		  jQuery('.batch-cancel').show();
		},
		success: function(response) {
			var data = {
				action: 'process_batch',
				state: 'CONT'
			}
			
			if( 'DONE' == response.state) {
				jQuery( "#batch_response" ).append("<h3 style='color:green;'> Batch Processing Finished Sucessfully! </h3>");
				//window.location = response.url;
				jQuery('#spiningImg').hide();
			}
			
			else if('CONT' == response.state) {
				jQuery('#spiningImg').hide();
				var total = (response.total < 0)? 0 : response.total;
				jQuery( "#batch_response" ).append("<h3> There are <strong> <u> " + total + " </u> </strong> document(s) left ... </h3>");
				if(cont){
					jQuery('#spiningImg').show();
					jQuery("#batch_response").append('<h3 style="color:blue;"> Processing The Next Batch ... </h3>');
					send_Request(data);
				}
				else{
					jQuery('#spiningImg').hide();
					jQuery('.batch-cancel').hide();
					jQuery( "#batch_response" ).append("<h3 style='color:red;'> BATCH PROCESSING SUCCESSFULLY CANCELED! </h3>");
				}
			}
			
			else if('CANC' == response.state){
				jQuery( "#batch_response" ).append("<h3 style='color:red;'> Batch Processing cancled by User\'s request! </h3>");
				jQuery( "#batch_response" ).append("<h3 style='color:green;'> Batch Processing Partially Finished! </h3>");
				jQuery('#spiningImg').hide();
			}
			
			else{
				jQuery('#spiningImg').hide();
				jQuery( "#batch_response" ).append("<h3 style='color:red;'> Something Went Wrong!!!!" + response.state + "</h3>");
			}
		}
		}).fail(function (response) {
			jQuery('#spiningImg').hide();
			if ( window.console && window.console.log ) {
				console.log( response );
			}
			jQuery( "#batch_response" ).append("<h3 style='color:red;'> Something Went Wrong!!!!" + response.state + "</h3>");
			//alert("BATCH PROCESSING FAILED!!!!!");
		});
	}
	else{
		jQuery('#spiningImg').hide();
		jQuery('.batch-cancel').hide();
		jQuery( "#batch_response" ).append("<h3 style='color:red;'> BATCH PROCESSING SUCESSFULLY CANCELED! </h3>");				
	}
}

function send_Cancel(jdata){
	
	jQuery.ajax(ajaxurl, {
		type: 'POST',
		url: ajaxurl,
		data: jdata,
		timeout: 10000,
		dataType: "json",
		beforeSend: function() {
		  jQuery('#spiningImg').show();    /*showing  a div with spinning image */
		},
		success: function(response) {

			if( 'DONE' == response.state) {
				jQuery( "#batch_response" ).append("<h3 style='color:green;'> Batch Processing Finished Successfully! </h3>");
				//window.location = response.url;
			}
			
			else if('CANC' == response.state){
				jQuery( "#batch_response" ).append("<h3 style='color:red;'> Batch Processing cancled by User\'s request! </h3>");
				jQuery( "#batch_response" ).append("<h3 style='color:green;'> Batch Processing Partially Finished! </h3>");
			}
			
			else{
				jQuery( "#batch_response" ).append("<h3 style='color:red;'> Something Went Wrong!!!! " + response.state + "</h3>");
			}
			jQuery('#spiningImg').hide();  
		}
	}).fail(function (response) {
		jQuery('#spiningImg').hide();
		if ( window.console && window.console.log ) {
			console.log( response );
		}
		jQuery( "#batch_response" ).append("<h3 style='color:red;'> Something Went Wrong!!!!" + response + "</h3>");
		alert("BATCH PROCESSING FAILED!!!!!");
	});
}

