(function () {

	AfterLogicApi.addPluginHook('view-model-defined', function (sViewModelName, oViewModel) {

		if (oViewModel && ('CServicesSettingsViewModel' === sViewModelName))
		{
			var 
				sSocialGoogleScopes = AfterLogicApi.getAppDataItem('SocialGoogleScopes'),
				bFilestorageScope = (sSocialGoogleScopes ? (sSocialGoogleScopes.indexOf("filestorage") > -1) : false)
			;
			oViewModel.allowGoogle = AfterLogicApi.getAppDataItem('SocialGoogle') && bFilestorageScope;
			oViewModel.googleConnected = ko.observable(false);

			oViewModel.onGoogleSignInClick = function ()
			{
				if (!oViewModel.googleConnected())
				{
					oViewModel.onSocialSignInClick('google');
				}
				else
				{
					oViewModel.onSocialSignOutClick('google');
				}
			};
		}
	});
	
}());
