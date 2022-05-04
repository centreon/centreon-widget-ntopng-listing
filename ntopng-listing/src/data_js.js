var timeout;

jQuery(function() {
	loadMetric();
});

function loadMetric() {
	jQuery.ajax({
		url: './index.php',
        type: 'GET',
		data: {
            widgetId: widgetId
		},
        success : function(htmlData) {
            var data = jQuery(htmlData).filter('#metric');
            var $container = $('#metric');
            var h;

            $container.html(data);

            h = $container.scrollHeight + 10;

            if(h){
                parent.iResize(window.name, h);
            } else {
                parent.iResize(window.name, 340);
            }
        }
	});

    if (autoRefresh && autoRefresh != "") {
        if (timeout) {
            clearTimeout(timeout);
        }

        timeout = setTimeout(loadMetric, (autoRefresh * 1000));
    }
}
