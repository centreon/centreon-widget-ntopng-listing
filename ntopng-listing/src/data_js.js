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
            var container = $('#metric');

            container.html(data);
            var h = container[0].scrollHeight;

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
