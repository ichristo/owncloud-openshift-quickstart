$(document).ready(function(){
	var params = OC.Util.History.parseUrlQuery();

	// Hack to add a trusted domain
	if (params.trustDomain) {
		OC.dialogs.confirm(t('core', 'Are you really sure you want add "{domain}" as trusted domain?', {domain: params.trustDomain}),
			t('core', 'Add trusted domain'), function(answer) {
				if(answer) {
					$.ajax({
						type: 'POST',
						url: OC.generateUrl('settings/ajax/setsecurity.php'),
						data: { trustedDomain: params.trustDomain }
					}).done(function() {
						window.location.replace(OC.generateUrl('settings/admin'));
					});
				}
			});
	}


	$('#excludedGroups').each(function (index, element) {
		OC.Settings.setupGroupsSelect($(element));
		$(element).change(function(ev) {
			var groups = ev.val || [];
			if (groups.length > 0) {
				groups = ev.val.join(','); // FIXME: make this JSON
			} else {
				groups = '';
			}
			OC.AppConfig.setValue('core', $(this).attr('name'), groups);
		});
	});


	$('#loglevel').change(function(){
		$.post(OC.filePath('settings','ajax','setloglevel.php'), { level: $(this).val() },function(){
			OC.Log.reload();
		} );
	});

	$('#backgroundjobs input').change(function(){
		if($(this).attr('checked')){
			var mode = $(this).val();
			if (mode === 'ajax' || mode === 'webcron' || mode === 'cron') {
				OC.AppConfig.setValue('core', 'backgroundjobs_mode', mode);
			}
		}
	});

	$('#shareAPIEnabled').change(function() {
		$('#shareAPI p:not(#enable)').toggleClass('hidden', !this.checked);
	});

	$('#shareAPI input:not(#excludedGroups)').change(function() {
		if ($(this).attr('type') === 'checkbox') {
			if (this.checked) {
				var value = 'yes';
			} else {
				var value = 'no';
			}
		} else {
			var value = $(this).val();
		}
		OC.AppConfig.setValue('core', $(this).attr('name'), value);
	});

	$('#shareapiDefaultExpireDate').change(function() {
		$("#setDefaultExpireDate").toggleClass('hidden', !this.checked);
	});

	$('#allowLinks').change(function() {
		$("#publicLinkSettings").toggleClass('hidden', !this.checked);
		$('#setDefaultExpireDate').toggleClass('hidden', !(this.checked && $('#shareapiDefaultExpireDate')[0].checked));
	});

	$('#security').change(function(){
		$.post(OC.filePath('settings','ajax','setsecurity.php'), { enforceHTTPS: $('#forcessl').val() },function(){} );
	});

	$('#mail_smtpauth').change(function() {
		if (!this.checked) {
			$('#mail_credentials').addClass('hidden');
		} else {
			$('#mail_credentials').removeClass('hidden');
		}
	});

	$('#mail_smtpmode').change(function() {
		if ($(this).val() !== 'smtp') {
			$('#setting_smtpauth').addClass('hidden');
			$('#setting_smtphost').addClass('hidden');
			$('#mail_smtpsecure_label').addClass('hidden');
			$('#mail_smtpsecure').addClass('hidden');
			$('#mail_credentials').addClass('hidden');
		} else {
			$('#setting_smtpauth').removeClass('hidden');
			$('#setting_smtphost').removeClass('hidden');
			$('#mail_smtpsecure_label').removeClass('hidden');
			$('#mail_smtpsecure').removeClass('hidden');
			if ($('#mail_smtpauth').attr('checked')) {
				$('#mail_credentials').removeClass('hidden');
			}
		}
	});

	$('#mail_general_settings').change(function(){
		OC.msg.startSaving('#mail_settings_msg');
		var post = $( "#mail_general_settings" ).serialize();
		$.post(OC.generateUrl('/settings/admin/mailsettings'), post, function(data){
			OC.msg.finishedSaving('#mail_settings_msg', data);
		});
	});

	$('#mail_credentials_settings_submit').click(function(){
		OC.msg.startSaving('#mail_settings_msg');
		var post = $( "#mail_credentials_settings" ).serialize();
		$.post(OC.generateUrl('/settings/admin/mailsettings/credentials'), post, function(data){
			OC.msg.finishedSaving('#mail_settings_msg', data);
		});
	});

	$('#sendtestemail').click(function(event){
		event.preventDefault();
		OC.msg.startAction('#sendtestmail_msg', t('settings', 'Sending...'));
		$.post(OC.generateUrl('/settings/admin/mailtest'), '', function(data){
			OC.msg.finishedAction('#sendtestmail_msg', data);
		});
	});

	$('#shareapiExcludeGroups').change(function() {
		$("#selectExcludedGroups").toggleClass('hidden', !this.checked);
	});

	// run setup checks then gather error messages
	$.when(
		OC.SetupChecks.checkWebDAV(),
		OC.SetupChecks.checkSetup()
	).then(function(check1, check2) {
		var errors = [].concat(check1, check2);
		var $el = $('#postsetupchecks');
		var $errorsEl;
		$el.find('.loading').addClass('hidden');
		if (errors.length === 0) {
			$el.find('.success').removeClass('hidden');
		} else {
			$errorsEl = $el.find('.errors');
			for (var i = 0; i < errors.length; i++ ) {
				$errorsEl.append('<div class="setupwarning">' + errors[i] + '</div>');
			}
			$errorsEl.removeClass('hidden');
			$el.find('.hint').removeClass('hidden');
		}
	});
});
