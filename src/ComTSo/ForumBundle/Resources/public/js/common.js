
var timeout = null;
var updatePhotoOrder = function (t) {
    var list = {};
    var i = 0;
    t.children().each(function(){
        var id = $(this).data('id');
        if (id) {
            list[id] = i++;
        }
    });
    var topicId = t.parents('.topic').data('topic-id');
    var forumId = t.parents('.topic').data('forum-id');
    var url = Routing.generate('comtso_topic_order_photos', {id: topicId, forumId: forumId});
    $.ajax(url, {
        type: 'POST',
        data: {
            order: list
        },
        complete: function(data) {
            $(document).trigger('comtso.notification', {
                object: t,
                message: 'Modifications enregistrées'
            });
        }
    });
};

$(document).ready(function(){
    
    $(document).on('mouseover', '[data-toggle="tooltip"]', function(){
        $(this).tooltip({
            container: 'body'
        }).tooltip('show');
    });
    
    $('#chat-toggle').parent().show();
    $('#chat-toggle').click(function(e){
        e.preventDefault();
        if ($(this).parent().hasClass('active')) {
            $('#chat-panel').slideUp();
            $(this).parent().removeClass('active');
        } else {
            $('#chat-panel').slideDown();
            $(this).parent().addClass('active');
        }
    });

    var parseChatMessages = function(data) {
        $('#chat-box').html('');
        var t = $('#chat-message-template');
        $.each(data.messages, function(){
            var user = data.users[this.author_id];
            t.find('.avatar').attr('href', Routing.generate('comtso_user_show', {usernameCanonical: user.usernameCanonical}));
            t.find('.avatar .username').html(user.username);
            //t.find('.avatar img').attr('src', Routing.generate()).attr('title', user.username);
            t.find('p').html(this.content);
            var date = moment(this.created_at);
            t.find('time').html(date.fromNow() + ', ' + date.format('dddd D MMMM YYYY [à] H:mm'));
            $('#chat-box').prepend(t.html());
        });
        $('#chat-connected-users').html('');
        t = $('#chat-connected-user-template');
        $.each(data.connected_users_id, function(){
            user = data.users[this];
            t.find('a').attr('href', Routing.generate('comtso_user_show', {usernameCanonical: user.usernameCanonical}));
            //t.find('img').attr('src', user.avatar).attr('title', user.username).attr('alt', user.username);
            $('#chat-connected-users').append(t.html());
        });
        $('#chat-message-new').val('');
        $("#chat-message-new").prop('disabled', false);
    };

//    window.setInterval(function(){
//        $.ajax(chat_url, {success: parseChatMessages});
//    }, 10000);
    if ($('#chat-panel form').length) {
        $.ajax(Routing.generate('comtso_chat'), {success: parseChatMessages});
    }
    
    $(document).on('submit', '#chat-panel form', function(e){
        e.preventDefault();
        var data = $(this).serialize();
        $("#chat-message-new").prop('disabled', true);
        $.ajax(Routing.generate('comtso_chat'),{
            type: 'POST',
            data: data,
            success: parseChatMessages
        });
    });
    
    $("img.lazy").lazyload();
                
    moment.lang('fr');

    $('[data-provider="datepicker"]').datetimepicker({
        autoclose: true,
        format: 'dd/mm/yyyy',
        language: 'fr',
        minView: 'month',
        pickerPosition: 'bottom-left',
        todayBtn: true,
        startView: 'month'
    });

    $('[data-provider="datetimepicker"]').datetimepicker({
        autoclose: true,
        format: 'dd/mm/yyyy hh:ii',
        language: 'fr',
        pickerPosition: 'bottom-left',
        todayBtn: true
    });

    $('[data-provider="timepicker"]').datetimepicker({
        autoclose: true,
        format: 'hh:ii',
        formatViewType: 'time',
        maxView: 'day',
        minView: 'hour',
        pickerPosition: 'bottom-left',
        startView: 'day'
    });

    // Restore value from hidden input
    $('.date input[type=hidden]').each(function(){
        if($(this).val()) {
            $(this).parent().datetimepicker('setValue');
        }
    });
        
    $(document).on('click', '.photo-widget-browse', function(e){
        var widgetId = $(this).parents('.photo-selector-widget').attr('id');
        $('#photo-browser').modal({
            remote: Routing.generate('comtso_photo_uploader', {widget: widgetId})
        });
    });
    
    $(document).on('click', '.photo-widget-detach', function(e){
        var widget = $(this).parents('.photo-selector-widget').first();
        widget.find('.widget-container').html('<p class="text-muted empty">Aucune photo sélectionné</p>');
        widget.find('input[type="hidden"]').val('');
        $(this).remove();
    });
    
    oneUploadFormInit = function(el) {
        el.fileupload({
            // Uncomment the following to send cross-domain cookies:
            //xhrFields: {withCredentials: true},
            url: Routing.generate('_uploader_upload_photos'),
            // Enable image resizing, except for Android and Opera,
            // which actually support image resizing, but fail to
            // send Blob objects via XHR requests:
            disableImageResize: /Android(?!.*Chrome)|Opera/.test(window.navigator.userAgent),
            maxFileSize: 5000000,
            acceptFileTypes: /(\.|\/)(gif|jpe?g|png|bmp)$/i,
            done: function(e, o) {
                $('.photo-selector').load(Routing.generate('comtso_photo_browser'));
                o.context.remove();
            }
        });
    };
    
    $(document).on('loaded.bs.modal', '#photo-browser', function(e) {
        oneUploadFormInit($('#photo-upload'));
    });
    
    $(document).on('click', '.photo-selector ul.pagination li a', function(e){
        $(this).parents('.photo-selector').load($(this).attr('href'));
        e.preventDefault();
    });

    var titleUpdateTimeOut = null;
    $(document).on('change keyup', '.photo-selector-line input.photo-title', function(e){
        var t = $(this);
        var title = $(this).val();
        var id = t.parents('.photo-selector-line').data('photo-id');
        if (t.siblings('.old-title').val() === title) {
            return;
        }
        window.clearTimeout(titleUpdateTimeOut);
        titleUpdateTimeOut = window.setTimeout(function(e){
            $.ajax(Routing.generate('comtso_photo_update', {id: id}), {
                type: 'POST',
                data: {
                    title: title
                }
            }).done(function(){
                t.siblings('.old-title').val(title);
                t.siblings('.input-append-icon').show().fadeOut(1500);
            });
        }, 800);
    });
    
    $(document).on('click', '#photo-browser .photo-selector .photo-selector-line button.select', function(){
        var t = $(this);
        var widgetId = t.parents('.photo-selector').data('target-widget');
        var photoId = t.parents('.photo-selector-line').data('photo-id');
        var widget = $('#' + widgetId);
        $('input[type="hidden"]', widget).val(photoId);
        $('.widget-container', widget).load(Routing.generate('comtso_photo_widget', {id: photoId}));
        $('#photo-browser').modal('hide');
    });
    
    $(document).on('click', '.topic .photo-selector .photo-selector-line button.select', function(){
        var t = $(this);
        var line = t.parents('.photo-selector-line');
        var photoId = line.data('photo-id');
        var topicId = t.parents('.topic').data('topic-id');
        var forumId = t.parents('.topic').data('forum-id');
        var url = Routing.generate('comtso_topic_add_photo', {id: topicId, forumId: forumId, add: photoId});
        t.parents('tr').find('td').first().prepend($('<div>').addClass('cache loader'));
        $.ajax(url, {
            complete: function(data) {
                t.parents('tr')
                        .find('.cache')
                        .removeClass('loader')
                        .html(data.responseText)
                        .find('a[data-action="cancel"]')
                        .on('click', function(e) {
                            e.preventDefault();
                            $.ajax($(this).attr('href'), {
                                success: function() {
                                    t.parents('tr').find('.cache').remove();
                                }
                            });
                        });
                
            }
        });
    });
});