/**
 * Description
 */

/*global hoge: true*/

(function ($) {

    'use strict';

    var getBase64 = function (file, callback) {
        var reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = function () {
            callback(reader.result);
        };
        reader.onerror = function (error) {
            alert( 'Failed to encode BASE64: ' + error );
        };
    };

    var showMessage = function(messages, success){
        var markup = '<div class="alert alert-' + ( success ? 'success' : 'danger' ) + ' alert-dismissible fade show" role="alert">';
        markup += '<h4 class="alert-heading">Your ePub ' + ( success ? 'is VALID' : 'has ERROR' ) + '!</h4><p>';
        markup += messages.join('<br />');
        markup += '</p>';
        markup += '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                  '<span aria-hidden="true">&times;</span>' +
                  '</button>';
        markup += '</div>';
        var $message = $(markup);
        $('.try-form').after($message);
    };



    $(document).ready(function(){

        $('.try-form').submit(function(e){
            e.preventDefault();
            var version = $('#try-version').val();
            var $form = $(this);

            var files = $('#try-file').get(0).files;
            if(!files.length){
                alert('Please select file!');
                return;
            }

            getBase64(files[0], function(result){
                result = result.replace(/^data:.*;base64,/, '');
                $form.addClass('loading');
                var url = $form.attr('action') + '/' + version;
                var msg = $('<div></div>')
                $.ajax(url, {
                    method: 'POST',
                    data: result
                }).done(function(response){
                    showMessage(response.messages, response.success);
                }).fail(function(resopnse){
                    var message = 'Error occurred.';
                    if(response.responseJSON && response.responseJSON.messages){
                        message = response.responseJSON.status + ': ' + response.responseJSON.messages.join("\n");
                    }
                    showMessage([message], false);
                }).always(function(){
                    $form.removeClass('loading');
                });
            });
        });

    });


})(jQuery);
