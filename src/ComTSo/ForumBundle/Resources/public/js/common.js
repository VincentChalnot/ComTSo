$(document).ready(function(){
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
			var link = new String(user_url);
			t.find('.avatar').attr('href', link.replace('-place-holder-', user.usernameCanonical));
			t.find('.avatar .username').html(user.username);
			t.find('.avatar img').attr('src', user.avatar).attr('title', user.username);
			t.find('p').html(this.content);
			var date = moment(this.created_at);
			t.find('time').html(date.fromNow() + ', ' + date.format('dddd D MMMM YYYY [à] H:mm'));
			$('#chat-box').prepend(t.html());
		});
		$('#chat-connected-users').html('');
		t = $('#chat-connected-user-template');
		$.each(data.connected_users_id, function(){
			user = data.users[this];
			var link = new String(user_url);
			t.find('a').attr('href', link.replace('-place-holder-', user.usernameCanonical));
			t.find('img').attr('src', user.avatar).attr('title', user.username).attr('alt', user.username);
			$('#chat-connected-users').append(t.html());
		});
		$('#chat-message-new').val('');
		$("#chat-message-new").prop('disabled', false);
	};

//	window.setInterval(function(){
//		$.ajax(chat_url, {success: parseChatMessages});
//	}, 10000);
	if ($('#chat-panel form').length) {
		$.ajax(chat_url, {success: parseChatMessages});
	}
	
	$('#chat-panel form').submit(function(e){
		e.preventDefault();
		var data = $(this).serialize();
		$("#chat-message-new").prop('disabled', true);
		$.ajax(chat_url,{
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
	$('input[type=hidden]', '.date').each(function(){
		if($(this).val()) {
			$(this).parent().datetimepicker('setValue');
		}
	});
});